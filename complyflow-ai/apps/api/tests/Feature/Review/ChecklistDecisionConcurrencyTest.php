<?php

namespace Tests\Feature\Review;

use App\Models\AnalysisRun;
use App\Models\AuditLog;
use App\Models\Document;
use App\Models\Organization;
use App\Models\RequirementSet;
use App\Models\Role;
use App\Models\Supplier;
use App\Models\User;
use App\Services\Audit\AuditHash;
use App\Services\Review\RecordFindingReview;
use App\Support\CurrentOrganization;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Process\InputStream;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class ChecklistDecisionConcurrencyTest extends TestCase
{
    use DatabaseMigrations;

    public static function firstWriters(): array
    {
        return [
            'version first' => ['version', 'decision'], 'decision first' => ['decision', 'version'],
            'analysis start during version insert' => ['version', 'start'],
            'rename before decision' => ['rename', 'decision'], 'decision before rename' => ['decision', 'rename'],
            'rename before analysis' => ['rename', 'start'], 'analysis before rename' => ['start', 'rename'],
            'rename before version' => ['rename', 'version'], 'version before rename' => ['version', 'rename'],
        ];
    }

    #[DataProvider('firstWriters')]
    public function test_checklist_mutations_are_compatible_with_decision_and_analysis_locks(string $first, string $second): void
    {
        $this->seed();
        $organization = Organization::create(['name' => 'Version concurrency', 'slug' => (string) Str::uuid()]);
        app(CurrentOrganization::class)->set($organization);
        $actor = User::factory()->create();
        $organization->users()->attach($actor, ['role_id' => Role::where('name', 'owner')->sole()->id]);
        $supplier = Supplier::create(['name' => 'Supplier']);
        $document = Document::create(['supplier_id' => $supplier->id, 'original_name' => 'generated.pdf', 'storage_name' => Str::uuid().'.pdf', 'mime_type' => 'application/pdf', 'size_bytes' => 9, 'sha256' => hash('sha256', '%PDF-1.4')]);
        $root = RequirementSet::create(['name' => 'Checklist', 'version' => 1, 'status' => 'published']);
        $source = RequirementSet::create(['name' => 'Checklist', 'parent_id' => $root->id, 'version' => 2, 'status' => 'published']);
        $draft = RequirementSet::create(['name' => 'Other draft', 'version' => 1, 'status' => 'draft']);
        $requirement = $source->requirements()->create(['code' => 'CERT', 'title' => 'Certificate', 'category' => 'Compliance', 'weight' => 1, 'position' => 1, 'evaluation_text' => 'Find evidence', 'is_required' => true]);
        $run = AnalysisRun::create(['supplier_id' => $supplier->id, 'requirement_set_id' => $source->id, 'status' => 'completed', 'idempotency_key' => 'fixture', 'document_set_hash' => str_repeat('a', 64)]);
        $findingId = (string) Str::uuid();
        DB::table('analysis_findings')->insert(['public_id' => $findingId, 'organization_id' => $organization->id, 'analysis_run_id' => $run->id, 'requirement_id' => $requirement->id, 'status' => 'met', 'justification' => 'Evidence', 'confidence' => 0.8]);
        app(RecordFindingReview::class)->handle($actor, $findingId, ['status' => 'met', 'justification' => 'Reviewed'], 'review');

        $name = 'checklist-decision-'.Str::uuid();
        $inputs = [new InputStream, new InputStream];
        $workers = [];
        foreach ([$first, $second] as $index => $mode) {
            $workers[] = new Process([PHP_BINARY, base_path('tests/Support/checklist-decision-worker.php'),
                (string) $organization->id, (string) $actor->id, $mode, $supplier->public_id, $run->public_id,
                $mode === 'rename' ? $draft->public_id : $source->public_id, $name.'-'.$index,
                $mode === 'version' && $second === 'start' ? 'insert' : 'lock',
                $mode === 'rename' ? 'Renamed draft' : $document->public_id], base_path(), timeout: 15);
        }
        try {
            $workers[0]->setInput($inputs[0])->start();
            $this->waitUntil(fn () => str_contains($workers[0]->getOutput(), 'ready'), $workers[0]);
            $workers[1]->setInput($inputs[1])->start();
            $this->waitUntil(fn () => str_contains($workers[1]->getOutput(), 'ready') || DB::selectOne('SELECT wait_event_type FROM pg_stat_activity WHERE application_name = ?', [$name.'-1'])?->wait_event_type === 'Lock', $workers[1]);
            // Each process either owns its first checklist lock or waits for the other's root.
            // Releasing both reveals an inverse lock order without relying on scheduler timing.
            foreach ($inputs as $input) {
                $input->write("go\n");
                $input->close();
            }
            do {
                $running = false;
                foreach ($workers as $worker) {
                    $worker->getOutput();
                    $worker->checkTimeout();
                    $running = $worker->isRunning() || $running;
                }
                usleep(10000);
            } while ($running);
            foreach ($workers as $index => $worker) {
                $worker->wait();
                $this->assertTrue($worker->isSuccessful(), $worker->getErrorOutput());
                $lines = explode("\n", trim($worker->getOutput()));
                $result = json_decode(end($lines), true, flags: JSON_THROW_ON_ERROR);
                $this->assertSame([$first, $second][$index] === 'rename' ? 200 : 201, $result['status'], json_encode($result));
            }
            if (in_array('start', [$first, $second], true)) {
                $this->assertDatabaseHas('analysis_runs', ['idempotency_key' => 'version-race-start', 'requirement_set_id' => $source->id, 'status' => 'pending']);
                $this->assertDatabaseCount('analysis_runs', 2);
                $this->assertDatabaseCount('jobs', 1);
                $this->assertDatabaseCount('supplier_decisions', 0);
            } elseif (in_array('decision', [$first, $second], true)) {
                $this->assertDatabaseHas('supplier_decisions', ['analysis_run_id' => $run->id, 'decided_by' => $actor->id, 'decision' => 'approved']);
                $this->assertDatabaseCount('supplier_decisions', 1);
            }
            if (in_array('version', [$first, $second], true)) {
                $clone = RequirementSet::where('parent_id', $root->id)->where('version', 3)->sole();
                $this->assertSame('draft', $clone->status);
                $this->assertSame(['CERT'], $clone->requirements()->pluck('code')->all());
            }
            if (in_array('rename', [$first, $second], true)) {
                $this->assertSame('Renamed draft', $draft->fresh()->name);
            }
            $this->assertDatabaseHas('requirement_sets', ['id' => $source->id, 'status' => 'published', 'version' => 2]);
            $this->assertTrue(app(AuditHash::class)->verifyChain(AuditLog::forOrganization($organization)->orderBy('id')->get()));
            $this->assertDatabaseCount('audit_logs', in_array('decision', [$first, $second], true) ? 2 : 1);
        } finally {
            foreach ($inputs as $input) {
                $input->close();
            }
            foreach ($workers as $worker) {
                if ($worker->isRunning()) {
                    $worker->stop();
                }
            }
        }
    }

    private function waitUntil(callable $condition, Process $worker): void
    {
        $deadline = microtime(true) + 5;
        do {
            if ($condition()) {
                return;
            }
            usleep(10000);
        } while ($worker->isRunning() && microtime(true) < $deadline);
        $this->fail('Worker did not reach the lock barrier: '.$worker->getOutput().$worker->getErrorOutput());
    }
}
