<?php

namespace Tests\Feature\Analysis;

use App\Jobs\ProcessAnalysis;
use App\Models\AnalysisRun;
use App\Models\Document;
use App\Models\Organization;
use App\Models\RequirementSet;
use App\Models\Supplier;
use App\Services\Analysis\StartAnalysis;
use App\Support\CurrentOrganization;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Queue\MaxAttemptsExceededException;
use Illuminate\Queue\WorkerOptions;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Process\Exception\ProcessSignaledException;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class WorkerInterruptionTest extends TestCase
{
    use DatabaseMigrations;

    private function queuedRun(bool $legacyPayload): AnalysisRun
    {
        config(['queue.default' => 'database', 'cache.default' => 'database']);
        Http::preventStrayRequests();
        $organization = Organization::create(['name' => 'Worker interruption', 'slug' => (string) Str::uuid()]);
        app(CurrentOrganization::class)->set($organization);
        $supplier = Supplier::create(['name' => 'Supplier']);
        $set = RequirementSet::create(['name' => 'Checklist', 'version' => 1, 'status' => 'published']);
        $set->requirements()->create(['code' => 'R1', 'title' => 'Criterion', 'category' => 'Compliance', 'weight' => 1,
            'position' => 1, 'evaluation_text' => 'Find evidence.']);
        $document = Document::create(['supplier_id' => $supplier->id, 'original_name' => 'generated.pdf',
            'storage_name' => Str::uuid().'.pdf', 'mime_type' => 'application/pdf', 'size_bytes' => 8, 'sha256' => hash('sha256', '%PDF-1.4')]);
        DB::table('document_blobs')->insert(['public_id' => (string) Str::uuid(), 'organization_id' => $organization->id,
            'document_id' => $document->id, 'contents' => '%PDF-1.4', 'created_at' => now(), 'updated_at' => now()]);
        $run = app(StartAnalysis::class)->handle($supplier, $set->public_id, [$document->public_id], 'interrupted');
        // Reduce only the test's worker alarm to one second. Legacy serialized
        // commands contain just the constructor ID, no new ownership properties.
        $record = DB::table('jobs')->sole();
        $payload = json_decode($record->payload, true, flags: JSON_THROW_ON_ERROR);
        $payload['timeout'] = 1;
        if ($legacyPayload) {
            $payload['data']['command'] = sprintf('O:24:"App\\Jobs\\ProcessAnalysis":1:{s:13:"analysisRunId";i:%d;}', $run->id);
        }
        DB::table('jobs')->where('id', $record->id)->update(['payload' => json_encode($payload, JSON_THROW_ON_ERROR)]);

        return $run;
    }

    public static function payloadVersions(): array
    {
        return ['current payload' => [false], 'legacy constructor-only payload' => [true]];
    }

    private function interruptWorker(string $mode): void
    {
        $process = new Process([PHP_BINARY, base_path('tests/Support/interrupted-analysis-worker.php'), $mode], base_path(), timeout: 8);
        try {
            $process->run();
        } catch (ProcessSignaledException) {
        }
        $this->assertStringContainsString('reached_http', $process->getOutput(), $process->getErrorOutput());
        $this->assertTrue($process->hasBeenSignaled());
        $this->assertSame(SIGKILL, $process->getTermSignal());
    }

    #[DataProvider('payloadVersions')]
    public function test_sigkill_recovers_only_the_same_expired_database_reservation(bool $legacyPayload): void
    {
        $run = $this->queuedRun($legacyPayload);
        $queue = Queue::connection('database');
        // Three actual released deliveries leave the fourth reservation for the
        // subprocess. No analysis ownership/status is fabricated in this test.
        for ($attempt = 1; $attempt <= 3; $attempt++) {
            $released = $queue->pop();
            $this->assertSame($attempt, $released->attempts());
            $released->release();
        }
        $id = (string) DB::table('jobs')->sole()->id;
        $this->interruptWorker('crash');
        $run->refresh();
        $this->assertSame('processing', $run->status);
        $this->assertSame($id, $run->owner_reservation_id);
        $this->assertSame(4, $run->owner_reservation_attempt);
        $this->assertNotNull($run->owner_message_uuid);
        $job = new ProcessAnalysis($run->id);
        $this->assertFalse(Cache::lock($job->middleware()[0]->getLockKey($job), 85)->get());
        $this->travel(100)->seconds();
        // A distinct row with identical payload still cannot finalize the crashed
        // owner's processing state, even after the overlap lock has expired.
        $queue->pushRaw(DB::table('jobs')->where('id', $id)->value('payload'), 'duplicate');
        for ($attempt = 1; $attempt <= 4; $attempt++) {
            $queue->pop('duplicate')->release();
        }
        $duplicate = $queue->pop('duplicate');
        $this->assertNotSame($id, (string) $duplicate->getJobId());
        try {
            app('queue.worker')->process('database', $duplicate, new WorkerOptions(maxTries: 4));
        } catch (MaxAttemptsExceededException) {
        }
        $this->assertTrue($duplicate->hasFailed());
        $this->assertSame('processing', $run->fresh()->status);
        $this->assertNull($run->fresh()->completed_at);
        $expired = $queue->pop();
        $this->assertSame($id, (string) $expired->getJobId());
        $this->assertSame(5, $expired->attempts());
        try {
            app('queue.worker')->process('database', $expired, new WorkerOptions(maxTries: 4));
        } catch (MaxAttemptsExceededException) {
        }
        $this->assertTrue($expired->hasFailed());
        $this->assertSame('failed', $run->fresh()->status);
        $this->assertSame('analysis_failed', $run->fresh()->error_code);
        $this->assertNotNull($run->fresh()->completed_at);
        $this->assertDatabaseCount('analysis_findings', 0);
        Http::assertNothingSent();
    }

    #[DataProvider('payloadVersions')]
    public function test_hard_worker_timeout_finalizes_its_actual_owning_reservation(bool $legacyPayload): void
    {
        $run = $this->queuedRun($legacyPayload);
        $id = (string) DB::table('jobs')->sole()->id;
        $this->interruptWorker('timeout');
        $run->refresh();
        $this->assertSame($id, $run->owner_reservation_id);
        $this->assertSame(1, $run->owner_reservation_attempt);
        $this->assertSame('failed', $run->status);
        $this->assertSame('Não foi possível processar a análise.', $run->error_message);
        $this->assertNotNull($run->completed_at);
        $this->assertDatabaseCount('jobs', 0);
        $this->assertDatabaseCount('analysis_findings', 0);
    }
}
