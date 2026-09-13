<?php

namespace Tests\Feature\Analysis;

use App\Data\Processor\ProcessorResult;
use App\Jobs\ProcessAnalysis;
use App\Models\AnalysisRun;
use App\Models\Requirement;
use App\Services\Processor\ProcessorClient;
use App\Services\Processor\ProcessorException;
use App\Services\Processor\ResultPersister;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use RuntimeException;

class ProcessAnalysisTest extends AnalysisTestCase
{
    public function test_completed_persistence_survives_late_exception_and_duplicate_delivery(): void
    {
        $id = $this->start()->assertStatus(202)->json('data.id');
        $run = AnalysisRun::where('public_id', $id)->firstOrFail();
        $raw = $this->processorResult($id);
        $raw['findings'][0]['status'] = 'missing';
        $raw['findings'][0]['citations'] = [];
        $raw['findings'][0]['search_summary'] = 'No evidence found in the document.';
        Http::fake(['processor:8001/*' => Http::response($raw)]);
        app()->instance(ResultPersister::class, new class implements ResultPersister
        {
            public function handle(AnalysisRun $run, ProcessorResult $result): void
            {
                DB::transaction(function () use ($run, $result) {
                    $finding = $result->findings[0];
                    DB::table('analysis_findings')->insert([
                        'public_id' => (string) Str::uuid(), 'organization_id' => $run->organization_id,
                        'analysis_run_id' => $run->id, 'requirement_id' => Requirement::forOrganization($run->organization_id)->where('public_id', $finding->requirementId)->firstOrFail()->id,
                        'status' => $finding->status, 'confidence' => $finding->confidence, 'justification' => $finding->justification,
                        'search_summary' => $finding->searchSummary, 'created_at' => now(), 'updated_at' => now(),
                    ]);
                    $run->update(['status' => 'completed', 'progress' => 100, 'completed_at' => now()]);
                });
                DB::afterCommit(fn () => throw new RuntimeException('Late failure after committed persistence.'));
            }
        });
        $job = new ProcessAnalysis($run->id);
        try {
            app()->call([$job, 'handle']);
        } catch (ProcessorException) {
        }
        $this->assertSame('completed', $run->fresh()->status);
        $this->assertSame(100, $run->fresh()->progress);
        $job->failed(new RuntimeException('Late worker failure.'));
        app()->call([$job, 'handle']);
        $this->assertDatabaseCount('analysis_findings', 1);
        Http::assertSentCount(1);
    }

    public function test_provider_configuration_error_is_terminal_even_when_http_503(): void
    {
        $id = $this->start()->assertStatus(202)->json('data.id');
        $run = AnalysisRun::where('public_id', $id)->firstOrFail();
        Http::fake(['processor:8001/*' => Http::response(['detail' => 'provider_not_configured'], 503)]);
        app()->call([new ProcessAnalysis($run->id), 'handle']);
        $this->assertSame('failed', $run->fresh()->status);
        $this->assertSame(1, $run->fresh()->attempts);
    }

    public function test_client_rejects_json_objects_in_place_of_empty_arrays(): void
    {
        $id = $this->start()->assertStatus(202)->json('data.id');
        $run = AnalysisRun::where('public_id', $id)->firstOrFail();
        $raw = $this->processorResult($id);
        $raw['findings'][0]['status'] = 'missing';
        $raw['findings'][0]['search_summary'] = 'Searched all pages.';
        $raw['findings'][0]['citations'] = new \stdClass;
        Http::fake(['processor:8001/*' => Http::response($raw)]);
        $this->expectException(ProcessorException::class);
        app(ProcessorClient::class)->analyze($run);
    }

    public function test_client_rejects_zero_weight_before_sending_invalid_python_schema(): void
    {
        $id = $this->start()->assertStatus(202)->json('data.id');
        $run = AnalysisRun::where('public_id', $id)->firstOrFail();
        $this->set->requirements()->update(['weight' => 0]);
        Http::fake(['processor:8001/*' => Http::response($this->processorResult($id))]);
        try {
            app(ProcessorClient::class)->analyze($run);
            $this->fail('Processor schema requires weight greater than zero.');
        } catch (ProcessorException $error) {
            $this->assertSame('invalid_analysis_input', $error->publicCode);
        }
        Http::assertNothingSent();
    }

    public function test_lock_prevents_overlapping_http_execution(): void
    {
        $id = $this->start()->assertStatus(202)->json('data.id');
        $run = AnalysisRun::where('public_id', $id)->firstOrFail();
        $job = new ProcessAnalysis($run->id);
        $middleware = $job->middleware()[0];
        $lock = Cache::lock($middleware->getLockKey($job), 85);
        $this->assertTrue($lock->get());
        try {
            $middleware->handle($job, function () {
                $this->fail('Overlapping job must not execute.');
            });
            Http::assertNothingSent();
        } finally {
            $lock->release();
        }
    }

    public function test_client_rejects_changed_input_snapshot_before_http(): void
    {
        $id = $this->start()->assertStatus(202)->json('data.id');
        $run = AnalysisRun::where('public_id', $id)->firstOrFail();
        Http::fake(['processor:8001/*' => Http::response($this->processorResult($id))]);
        $this->set->update(['version' => 2]);
        try {
            app(ProcessorClient::class)->analyze($run);
            $this->fail('Changed version must invalidate the saved fingerprint.');
        } catch (ProcessorException $error) {
            $this->assertSame('invalid_analysis_input', $error->publicCode);
        }
        Http::assertNothingSent();
    }

    public function test_client_rejects_foreign_analysis_requirement_and_document_ids(): void
    {
        $id = $this->start()->assertStatus(202)->json('data.id');
        $run = AnalysisRun::where('public_id', $id)->firstOrFail();
        foreach (['analysis_id', 'findings', 'processed_documents'] as $field) {
            $raw = $this->processorResult($id);
            if ($field === 'analysis_id') {
                $raw[$field] = $this->supplier->public_id;
            }
            if ($field === 'findings') {
                $raw['findings'][0]['requirement_id'] = $this->supplier->public_id;
            }
            if ($field === 'processed_documents') {
                $raw['processed_documents'][0]['document_id'] = $this->supplier->public_id;
                $raw['findings'][0]['citations'][0]['document_id'] = $this->supplier->public_id;
            }
            Http::fake(['processor:8001/*' => Http::response($raw)]);
            try {
                app(ProcessorClient::class)->analyze($run);
                $this->fail('Foreign IDs must be rejected.');
            } catch (ProcessorException $error) {
                $this->assertSame('invalid_processor_result', $error->publicCode);
            }
        }
    }

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.processor.url' => 'http://processor:8001', 'services.processor.secret' => 'test-only-secret']);
        Http::preventStrayRequests();
    }

    public function test_client_sends_minimum_signed_contract_and_accepts_strict_dto(): void
    {
        $id = $this->start()->assertStatus(202)->json('data.id');
        $run = AnalysisRun::where('public_id', $id)->firstOrFail();
        Http::fake(['processor:8001/*' => Http::response($this->processorResult($id))]);
        $result = app(ProcessorClient::class)->analyze($run);
        $this->assertInstanceOf(ProcessorResult::class, $result);
        $this->assertSame($id, $result->analysisId);
        Http::assertSent(function ($request) use ($id) {
            $body = json_decode($request->body(), true);
            $this->assertSame(['analysis_id', 'idempotency_key', 'requirements', 'documents'], array_keys($body));
            $this->assertSame($id, $body['analysis_id']);
            $this->assertSame(['document_id', 'sha256', 'content_base64'], array_keys($body['documents'][0]));
            $this->assertSame($this->document->sha256, hash('sha256', base64_decode($body['documents'][0]['content_base64'])));
            $this->assertSame('Certidão válida', $body['requirements'][0]['criterion']);
            $message = $request->header('X-CF-Timestamp')[0]."\n".$request->header('X-CF-Nonce')[0]."\n".hash('sha256', $request->body());
            $this->assertSame(hash_hmac('sha256', $message, 'test-only-secret'), $request->header('X-CF-Signature')[0]);

            return $request->url() === 'http://processor:8001/v1/analyze';
        });
    }

    public function test_transient_errors_retry_with_fresh_nonces_and_final_attempt_fails_safely(): void
    {
        $id = $this->start()->assertStatus(202)->json('data.id');
        $run = AnalysisRun::where('public_id', $id)->firstOrFail();
        Http::fake(['processor:8001/*' => Http::response(['detail' => 'SECRET document /path/file.pdf'], 503)]);
        $job = new ProcessAnalysis($run->id);
        $this->assertSame([10, 30, 90], $job->backoff());
        $this->assertSame(4, $job->tries);
        $this->assertInstanceOf(WithoutOverlapping::class, $job->middleware()[0]);
        $this->assertLessThan(config('queue.connections.database.retry_after'), $job->timeout);
        for ($attempt = 1; $attempt <= 4; $attempt++) {
            try {
                app()->call([$job, 'handle']);
                $this->fail('Retryable transport failure must escape to the worker.');
            } catch (ProcessorException $error) {
                $this->assertTrue($error->retryable);
                $this->assertStringNotContainsString('SECRET', $error->getMessage());
            }
            $run->refresh();
            $this->assertSame($attempt, $run->attempts);
            $this->assertSame($attempt === 4 ? 'failed' : 'pending', $run->status);
            $this->assertNotNull($run->started_at);
            $this->assertSame($attempt === 4, $run->completed_at !== null);
        }
        $nonces = Http::recorded()->map(fn ($pair) => $pair[0]->header('X-CF-Nonce')[0]);
        $this->assertCount(4, $nonces->unique());
        $this->assertStringNotContainsString('SECRET', $run->error_message);
        app()->call([$job, 'handle']);
        Http::assertSentCount(4);
    }

    public function test_terminal_http_failure_does_not_retry_and_failed_callback_is_sanitized(): void
    {
        $id = $this->start()->assertStatus(202)->json('data.id');
        $run = AnalysisRun::where('public_id', $id)->firstOrFail();
        Http::fake(['processor:8001/*' => Http::response(['detail' => 'private PDF'], 422)]);
        $job = new ProcessAnalysis($run->id);
        app()->call([$job, 'handle']);
        $this->assertSame('failed', $run->fresh()->status);
        $job->failed(new RuntimeException('SECRET provider response'));
        $this->assertStringNotContainsString('SECRET', $run->fresh()->error_message);
        Http::assertSentCount(1);
    }

    public function test_default_persistence_seam_fails_closed_without_false_completion(): void
    {
        $id = $this->start()->assertStatus(202)->json('data.id');
        $run = AnalysisRun::where('public_id', $id)->firstOrFail();
        Http::fake(['processor:8001/*' => Http::response($this->processorResult($id))]);
        app()->call([new ProcessAnalysis($run->id), 'handle']);
        $this->assertSame('failed', $run->fresh()->status);
        $this->assertSame('result_persistence_unavailable', $run->fresh()->error_code);
        $this->assertDatabaseCount('analysis_findings', 0);
    }

    public function test_malformed_response_is_rejected_before_persistence(): void
    {
        $id = $this->start()->assertStatus(202)->json('data.id');
        $run = AnalysisRun::where('public_id', $id)->firstOrFail();
        $raw = $this->processorResult($id);
        $raw['findings'][0]['confidence'] = '0.8';
        Http::fake(['processor:8001/*' => Http::response($raw)]);
        app()->instance(ResultPersister::class, new class implements ResultPersister
        {
            public function handle(AnalysisRun $run, ProcessorResult $result): void
            {
                throw new \LogicException('Persistence must not be called.');
            }
        });
        app()->call([new ProcessAnalysis($run->id), 'handle']);
        $this->assertSame('invalid_processor_result', $run->fresh()->error_code);
        $this->assertDatabaseCount('analysis_findings', 0);
    }
}
