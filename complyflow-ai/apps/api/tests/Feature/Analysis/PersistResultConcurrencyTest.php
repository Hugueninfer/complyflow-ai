<?php

namespace Tests\Feature\Analysis;

use App\Models\AnalysisRun;
use App\Models\Document;
use App\Models\Organization;
use App\Models\RequirementSet;
use App\Models\Supplier;
use App\Services\Analysis\AnalysisFingerprint;
use App\Support\CurrentOrganization;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Process\InputStream;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class PersistResultConcurrencyTest extends TestCase
{
    use DatabaseMigrations;

    public static function races(): array
    {
        return ['same run' => [false, false, false], 'shared extraction' => [true, false, false], 'rollback winner' => [false, true, false], 'checklist publication' => [false, false, true]];
    }

    #[DataProvider('races')]
    public function test_concurrent_persistence_serializes_and_has_no_partial_visibility(bool $differentRuns, bool $rollback, bool $archive): void
    {
        $organization = Organization::create(['name' => 'Persistence race', 'slug' => (string) Str::uuid()]);
        app(CurrentOrganization::class)->set($organization);
        $supplier = Supplier::create(['name' => 'Supplier']);
        $set = RequirementSet::create(['name' => 'Checklist', 'version' => 1, 'status' => 'published']);
        $set->requirements()->create(['code' => 'R1', 'title' => 'Criterion', 'category' => 'Compliance', 'weight' => 1, 'position' => 1, 'evaluation_text' => 'Find evidence.']);
        $document = Document::create(['supplier_id' => $supplier->id, 'original_name' => 'generated.pdf', 'storage_name' => Str::uuid().'.pdf', 'mime_type' => 'application/pdf', 'size_bytes' => 9, 'sha256' => hash('sha256', '%PDF-1.4')]);
        $attributes = ['supplier_id' => $supplier->id, 'requirement_set_id' => $set->id, 'status' => 'processing', 'attempts' => 1,
            'document_ids' => [$document->public_id], 'document_set_hash' => AnalysisFingerprint::make($supplier, $set, [$document->sha256]),
            'owner_message_uuid' => (string) Str::uuid(), 'owner_reservation_id' => '71', 'owner_reservation_attempt' => 1];
        $first = AnalysisRun::create($attributes + ['idempotency_key' => 'first']);
        $second = $differentRuns ? AnalysisRun::create($attributes + ['idempotency_key' => 'second']) : $first;
        $name = 'persist-race-'.Str::uuid();
        $input = new InputStream;
        $workers = [
            new Process([PHP_BINARY, base_path('tests/Support/persist-result-worker.php'), (string) $first->id, $name.'-0', $rollback ? 'rollback' : 'pause'], base_path(), timeout: 10),
            new Process([PHP_BINARY, base_path('tests/Support/persist-result-worker.php'), (string) $second->id, $name.'-1', $archive ? 'archive' : 'run'], base_path(), timeout: 10),
        ];
        try {
            $workers[0]->setInput($input)->start();
            $deadline = microtime(true) + 5;
            while (! str_contains($workers[0]->getOutput(), 'ready') && $workers[0]->isRunning() && microtime(true) < $deadline) {
                usleep(10000);
            }
            $this->assertStringContainsString('ready', $workers[0]->getOutput(), $workers[0]->getErrorOutput());
            $this->assertDatabaseCount('document_pages', 0);
            $this->assertSame('processing', $first->fresh()->status);
            $workers[1]->start();
            $deadline = microtime(true) + 5;
            do {
                $state = DB::selectOne('SELECT wait_event_type FROM pg_stat_activity WHERE application_name = ?', [$name.'-1']);
                if ($state?->wait_event_type === 'Lock') {
                    break;
                }
                usleep(10000);
            } while ($workers[1]->isRunning() && microtime(true) < $deadline);
            $this->assertSame('Lock', $state?->wait_event_type, 'The second transaction must wait for the first.');
            $input->write("go\n");
            $input->close();
            foreach ($workers as $worker) {
                $worker->wait();
                $this->assertTrue($worker->isSuccessful(), $worker->getErrorOutput());
            }
            $this->assertStringContainsString($rollback ? 'analysis_failed' : 'completed', $workers[0]->getOutput());
            $this->assertStringContainsString($archive ? 'archived' : 'completed', $workers[1]->getOutput());
            $this->assertSame('completed', $first->fresh()->status);
            $this->assertSame('completed', $second->fresh()->status);
            $this->assertDatabaseCount('document_pages', 1);
            $this->assertDatabaseCount('document_chunks', 1);
            $this->assertDatabaseCount('analysis_findings', $differentRuns ? 2 : 1);
            $this->assertDatabaseCount('finding_citations', $differentRuns ? 2 : 1);
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
