<?php

namespace Tests\Feature\Analysis;

use App\Models\Document;
use App\Models\Organization;
use App\Models\RequirementSet;
use App\Models\Supplier;
use App\Services\Analysis\StartAnalysis;
use App\Support\CurrentOrganization;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\Process\InputStream;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class AnalysisConcurrencyTest extends TestCase
{
    use DatabaseMigrations;

    private Supplier $supplier;

    private RequirementSet $set;

    private Document $document;

    protected function setUp(): void
    {
        parent::setUp();
        config(['queue.default' => 'database']);
        $organization = Organization::create(['name' => 'Concurrency', 'slug' => (string) Str::uuid()]);
        app(CurrentOrganization::class)->set($organization);
        $this->supplier = Supplier::create(['name' => 'Supplier']);
        $this->set = RequirementSet::create(['name' => 'Checklist', 'version' => 1, 'status' => 'published']);
        $this->set->requirements()->create(['code' => 'R1', 'title' => 'Criterion', 'category' => 'Compliance', 'weight' => 1, 'position' => 1, 'evaluation_text' => 'Find evidence.']);
        $this->document = Document::create(['supplier_id' => $this->supplier->id, 'original_name' => 'generated.pdf', 'storage_name' => Str::uuid().'.pdf', 'mime_type' => 'application/pdf', 'size_bytes' => 9, 'sha256' => hash('sha256', '%PDF-1.4')]);
    }

    public function test_job_is_only_inserted_after_outer_commit_and_rollback_discards_it(): void
    {
        DB::beginTransaction();
        app(StartAnalysis::class)->handle($this->supplier, $this->set->public_id, [$this->document->public_id], 'rollback');
        $this->assertDatabaseCount('jobs', 0);
        DB::rollBack();
        $this->assertDatabaseCount('analysis_runs', 0);
        $this->assertDatabaseCount('jobs', 0);
        DB::beginTransaction();
        app(StartAnalysis::class)->handle($this->supplier, $this->set->public_id, [$this->document->public_id], 'commit');
        $this->assertDatabaseCount('jobs', 0);
        DB::commit();
        $this->assertDatabaseCount('jobs', 1);
        app(StartAnalysis::class)->handle($this->supplier, $this->set->public_id, [$this->document->public_id], 'commit');
        $this->assertDatabaseCount('jobs', 1);
    }

    public function test_two_concurrent_requests_create_one_analysis_and_one_database_job(): void
    {
        $name = 'analysis-race-'.Str::uuid();
        $input = new InputStream;
        $workers = [];
        foreach ([0, 1] as $index) {
            $workers[] = new Process([PHP_BINARY, base_path('tests/Support/start-analysis-worker.php'), (string) $this->supplier->organization_id,
                $this->supplier->public_id, $this->set->public_id, $this->document->public_id, $name.'-'.$index, $index === 0 ? 'pause' : 'run'], base_path(), timeout: 10);
        }
        try {
            $workers[0]->setInput($input)->start();
            $deadline = microtime(true) + 5;
            while (! str_contains($workers[0]->getOutput(), 'ready') && $workers[0]->isRunning() && microtime(true) < $deadline) {
                usleep(10000);
            }
            $this->assertStringContainsString('ready', $workers[0]->getOutput(), $workers[0]->getErrorOutput());
            $workers[1]->start();
            $deadline = microtime(true) + 5;
            do {
                $state = DB::selectOne('SELECT wait_event_type FROM pg_stat_activity WHERE application_name = ?', [$name.'-1']);
                if ($state?->wait_event_type === 'Lock') {
                    break;
                }
                usleep(10000);
            } while ($workers[1]->isRunning() && microtime(true) < $deadline);
            $this->assertSame('Lock', $state?->wait_event_type, 'Second request must contend for the first transaction lock.');
            $input->write("go\n");
            $input->close();
            $results = [];
            foreach ($workers as $worker) {
                $worker->wait();
                $this->assertTrue($worker->isSuccessful(), $worker->getErrorOutput());
                $lines = explode("\n", trim($worker->getOutput()));
                $results[] = json_decode(end($lines), true, flags: JSON_THROW_ON_ERROR);
            }
            $this->assertSame([true, false], array_column($results, 'created'));
            $this->assertSame($results[0]['id'], $results[1]['id']);
            $this->assertDatabaseCount('analysis_runs', 1);
            $this->assertDatabaseCount('jobs', 1);
        } finally {
            $input->close();
            foreach ($workers as $worker) {
                if ($worker->isRunning()) {
                    $worker->stop();
                }
            }
        }
    }

    public function test_global_ai_budget_serializes_different_tenants(): void
    {
        $otherOrganization = Organization::create(['name' => 'Other tenant', 'slug' => (string) Str::uuid()]);
        app(CurrentOrganization::class)->set($otherOrganization);
        $otherSupplier = Supplier::create(['name' => 'Other supplier']);
        $otherSet = RequirementSet::create(['name' => 'Other checklist', 'version' => 1, 'status' => 'published']);
        $otherSet->requirements()->create([
            'code' => 'R1', 'title' => 'Criterion', 'category' => 'Compliance',
            'weight' => 1, 'position' => 1, 'evaluation_text' => 'Find evidence.',
        ]);
        $otherDocument = Document::create([
            'supplier_id' => $otherSupplier->id, 'original_name' => 'other.pdf',
            'storage_name' => Str::uuid().'.pdf', 'mime_type' => 'application/pdf',
            'size_bytes' => 9, 'sha256' => hash('sha256', 'other-pdf'),
        ]);
        app(CurrentOrganization::class)->clear();

        $name = 'provider-budget-'.Str::uuid();
        $input = new InputStream;
        $arguments = [
            [(string) $this->supplier->organization_id, $this->supplier->public_id, $this->set->public_id, $this->document->public_id],
            [(string) $otherOrganization->id, $otherSupplier->public_id, $otherSet->public_id, $otherDocument->public_id],
        ];
        $workers = [];
        foreach ($arguments as $index => $ids) {
            $workers[] = new Process(
                [PHP_BINARY, base_path('tests/Support/start-analysis-worker.php'), ...$ids,
                    $name.'-'.$index, $index === 0 ? 'pause' : 'run'],
                base_path(), ['AI_DAILY_REQUIREMENT_LIMIT' => '1'], timeout: 10,
            );
        }

        try {
            $workers[0]->setInput($input)->start();
            $deadline = microtime(true) + 5;
            while (! str_contains($workers[0]->getOutput(), 'ready') && $workers[0]->isRunning() && microtime(true) < $deadline) {
                usleep(10000);
            }
            $this->assertStringContainsString('ready', $workers[0]->getOutput(), $workers[0]->getErrorOutput());
            $workers[1]->start();
            $deadline = microtime(true) + 5;
            do {
                $state = DB::selectOne('SELECT wait_event_type FROM pg_stat_activity WHERE application_name = ?', [$name.'-1']);
                if ($state?->wait_event_type === 'Lock') {
                    break;
                }
                usleep(10000);
            } while ($workers[1]->isRunning() && microtime(true) < $deadline);
            $this->assertSame('Lock', $state?->wait_event_type, 'Second tenant must wait for the shared provider budget lock.');
            $input->write("go\n");
            $input->close();

            $results = [];
            foreach ($workers as $worker) {
                $worker->wait();
                $this->assertTrue($worker->isSuccessful(), $worker->getErrorOutput());
                $lines = explode("\n", trim($worker->getOutput()));
                $results[] = json_decode(end($lines), true, flags: JSON_THROW_ON_ERROR);
            }
            $this->assertTrue($results[0]['created']);
            $this->assertTrue($results[1]['quota']);
            $this->assertDatabaseCount('analysis_runs', 1);
            $this->assertDatabaseCount('jobs', 1);
        } finally {
            $input->close();
            foreach ($workers as $worker) {
                if ($worker->isRunning()) {
                    $worker->stop();
                }
            }
        }
    }
}
