<?php

namespace Tests\Feature\Analysis;

use App\Data\Processor\ProcessorResult;
use App\Jobs\ProcessAnalysis;
use App\Models\AnalysisRun;
use App\Models\Requirement;
use App\Services\Processor\ProcessorException;
use App\Services\Processor\ResultPersister;
use Illuminate\Queue\MaxAttemptsExceededException;
use Illuminate\Queue\WorkerOptions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;

class WorkerAnalysisTest extends AnalysisTestCase
{
    private function queuedRun(): AnalysisRun
    {
        $id = $this->start()->assertStatus(202)->json('data.id');
        Queue::swap(Queue::getFacadeRoot()->queue);
        config(['queue.default' => 'database', 'services.processor.url' => 'http://processor:8001', 'services.processor.secret' => 'test-only-secret']);
        Http::preventStrayRequests();
        app()->instance(ResultPersister::class, new class implements ResultPersister
        {
            public function handle(AnalysisRun $run, ProcessorResult $result): void
            {
                DB::transaction(function () use ($run, $result) {
                    $finding = $result->findings[0];
                    DB::table('analysis_findings')->insert([
                        'public_id' => (string) Str::uuid(), 'organization_id' => $run->organization_id,
                        'analysis_run_id' => $run->id, 'requirement_id' => Requirement::where('public_id', $finding->requirementId)->firstOrFail()->id,
                        'status' => $finding->status, 'confidence' => $finding->confidence, 'justification' => $finding->justification,
                        'search_summary' => $finding->searchSummary, 'created_at' => now(), 'updated_at' => now(),
                    ]);
                    $run->update(['status' => 'completed', 'progress' => 100, 'completed_at' => now()]);
                });
            }
        });

        return AnalysisRun::where('public_id', $id)->firstOrFail();
    }

    private function response(AnalysisRun $run)
    {
        $raw = $this->processorResult($run->public_id);
        $raw['findings'][0]['status'] = 'missing';
        $raw['findings'][0]['citations'] = [];
        $raw['findings'][0]['search_summary'] = 'No evidence located.';

        return Http::response($raw);
    }

    public static function duplicateMessages(): array
    {
        return ['distinct messages' => [false], 'redelivered identical payload' => [true]];
    }

    #[DataProvider('duplicateMessages')]
    public function test_overlap_releases_exhaust_only_the_contender_message_while_owner_completes(bool $samePayload): void
    {
        $run = $this->queuedRun();
        $queue = Queue::connection('database');
        $queue->push(new ProcessAnalysis($run->id), '', 'owner');
        $owner = $queue->pop('owner');
        if ($samePayload) {
            $queue->pushRaw($owner->getRawBody(), 'contender');
        } else {
            $queue->push(new ProcessAnalysis($run->id), '', 'contender');
        }
        $snapshot = null;
        $attempts = [];
        $failed = false;
        Http::fake(function () use ($queue, $run, &$snapshot, &$attempts, &$failed) {
            // The owner holds WithoutOverlapping while awaiting HTTP. Drive another
            // real worker through delayed releases and its max-attempts preflight.
            for ($index = 0; $index < 5; $index++) {
                $contender = $queue->pop('contender');
                $attempts[] = $contender->attempts();
                try {
                    app('queue.worker')->process('database', $contender, new WorkerOptions(maxTries: 4));
                } catch (MaxAttemptsExceededException) {
                    $failed = $contender->hasFailed();
                }
                $this->travel(10)->seconds();
            }
            $snapshot = $run->fresh()->only('status', 'attempts', 'completed_at');

            return $this->response($run);
        });
        app('queue.worker')->process('database', $owner, new WorkerOptions(maxTries: 4));
        $this->assertSame([1, 2, 3, 4, 5], $attempts);
        $this->assertTrue($failed);
        $this->assertSame(['status' => 'processing', 'attempts' => 1, 'completed_at' => null], $snapshot);
        $this->assertSame('completed', $run->fresh()->status);
        $this->assertDatabaseCount('analysis_findings', 1);
        Http::assertSentCount(1);
    }

    public function test_failed_owner_callback_prevents_its_later_http_result_from_completing(): void
    {
        $run = $this->queuedRun();
        $queue = Queue::connection('database');
        $queue->push(new ProcessAnalysis($run->id));
        $owner = $queue->pop();
        Http::fake(function () use ($owner, $run) {
            // Job::fail invokes CallQueuedHandler::failed with a fresh command.
            $owner->fail(new RuntimeException('Simulated terminal owner failure.'));

            return $this->response($run);
        });
        app('queue.worker')->process('database', $owner, new WorkerOptions(maxTries: 4));
        $this->assertTrue($owner->hasFailed());
        $this->assertSame('failed', $run->fresh()->status);
        $this->assertNotNull($run->fresh()->completed_at);
        $this->assertDatabaseCount('analysis_findings', 0);
    }

    public function test_callback_from_previous_reservation_cannot_fail_a_new_retry_of_same_message(): void
    {
        $run = $this->queuedRun();
        $queue = Queue::connection('database');
        $queue->push(new ProcessAnalysis($run->id));
        $old = $queue->pop();
        $calls = 0;
        $snapshot = null;
        Http::fake(function () use ($old, $run, &$snapshot, &$calls) {
            if (++$calls === 1) {
                return Http::response([], 503);
            }
            $old->fail(new RuntimeException('Stale failure callback.'));
            $snapshot = $run->fresh()->status;

            return $this->response($run);
        });
        try {
            app('queue.worker')->process('database', $old, new WorkerOptions(maxTries: 4));
        } catch (ProcessorException) {
        }
        $this->assertTrue($old->isReleased());
        $this->travel(10)->seconds();
        $current = $queue->pop();
        $this->assertSame($old->uuid(), $current->uuid());
        $this->assertSame(2, $current->attempts());
        app('queue.worker')->process('database', $current, new WorkerOptions(maxTries: 4));
        $this->assertSame('processing', $snapshot);
        $this->assertSame('completed', $run->fresh()->status);
        $this->assertDatabaseCount('analysis_findings', 1);
    }

    public function test_legacy_run_without_reservation_identity_is_not_finalized_by_preflight(): void
    {
        $run = $this->queuedRun();
        $queue = Queue::connection('database');
        $queue->push(new ProcessAnalysis($run->id));
        $interrupted = $queue->pop();
        // Legacy state has no reservation identity: neither UUID nor an unlocked
        // lease authorizes this callback to infer ownership.
        $run->update(['status' => 'processing', 'attempts' => 4, 'started_at' => now()->subSeconds(100),
            'owner_message_uuid' => $interrupted->uuid(), 'owner_reservation_attempt' => 4]);
        DB::table('jobs')->where('id', $interrupted->getJobId())->update(['attempts' => 4, 'reserved_at' => now()->subSeconds(100)->timestamp]);
        $exhausted = $queue->pop();
        $this->assertSame(5, $exhausted->attempts());
        try {
            app('queue.worker')->process('database', $exhausted, new WorkerOptions(maxTries: 4));
        } catch (MaxAttemptsExceededException) {
        }
        $this->assertTrue($exhausted->hasFailed());
        $this->assertSame('processing', $run->fresh()->status);
        $this->assertNull($run->fresh()->completed_at);
        Http::assertNothingSent();
    }

    public function test_exhausted_duplicate_during_backoff_preserves_the_owners_scheduled_retry(): void
    {
        $this->travelTo(now()->startOfSecond());
        $run = $this->queuedRun();
        $queue = Queue::connection('database');
        $queue->push(new ProcessAnalysis($run->id), '', 'owner');
        $owner = $queue->pop('owner');
        $queue->pushRaw($owner->getRawBody(), 'contender');
        $calls = 0;
        Http::fake(function () use ($queue, $run, &$calls) {
            if (++$calls > 1) {
                return $this->response($run);
            }
            for ($attempt = 1; $attempt <= 4; $attempt++) {
                $contender = $queue->pop('contender');
                $this->assertSame($attempt, $contender->attempts());
                app('queue.worker')->process('database', $contender, new WorkerOptions(maxTries: 4));
                $this->assertTrue($contender->isReleased());
                $this->travel($attempt === 4 ? 5 : 10)->seconds();
            }

            return Http::response([], 503); // t35: owner releases until t45.
        });
        try {
            app('queue.worker')->process('database', $owner, new WorkerOptions(maxTries: 4));
        } catch (ProcessorException) {
        }
        $this->assertTrue($owner->isReleased());
        $this->assertSame('pending', $run->fresh()->status);
        $this->travel(5)->seconds(); // t40: no lock, but a valid retry is pending.
        $contender = $queue->pop('contender');
        $this->assertSame(5, $contender->attempts());
        $this->assertNotSame($owner->getJobId(), $contender->getJobId());
        try {
            app('queue.worker')->process('database', $contender, new WorkerOptions(maxTries: 4));
        } catch (MaxAttemptsExceededException) {
        }
        $this->assertTrue($contender->hasFailed());
        $this->assertSame('pending', $run->fresh()->status);
        $this->assertNull($run->fresh()->completed_at);
        $owner->fail(new RuntimeException('Late callback after this reservation was released.'));
        $this->assertSame('pending', $run->fresh()->status);
        $this->assertNull($queue->pop('owner'));
        $this->travel(5)->seconds();
        $retry = $queue->pop('owner');
        $this->assertSame(2, $retry->attempts());
        app('queue.worker')->process('database', $retry, new WorkerOptions(maxTries: 4));
        $this->assertSame('completed', $run->fresh()->status);
        $this->assertDatabaseCount('analysis_findings', 1);
        Http::assertSentCount(2);
    }

    public function test_identical_payload_in_different_database_reservation_cannot_fail_owner(): void
    {
        $run = $this->queuedRun();
        $queue = Queue::connection('database');
        $queue->push(new ProcessAnalysis($run->id), '', 'owner');
        $owner = $queue->pop('owner');
        $queue->pushRaw($owner->getRawBody(), 'duplicate');
        $duplicate = $queue->pop('duplicate');
        $this->assertSame($owner->uuid(), $duplicate->uuid());
        $this->assertSame($owner->attempts(), $duplicate->attempts());
        $this->assertNotSame($owner->getJobId(), $duplicate->getJobId());
        $snapshot = null;
        Http::fake(function () use ($duplicate, $run, &$snapshot) {
            $duplicate->fail(new RuntimeException('Different reservation failed.'));
            $snapshot = $run->fresh()->status;

            return $this->response($run);
        });
        app('queue.worker')->process('database', $owner, new WorkerOptions(maxTries: 4));
        $this->assertSame('processing', $snapshot);
        $this->assertSame('completed', $run->fresh()->status);
        $this->assertDatabaseCount('analysis_findings', 1);
    }
}
