<?php

namespace Tests\Feature\Audit;

use App\Models\AnalysisRun;
use App\Models\AuditLog;
use App\Models\Organization;
use App\Models\RequirementSet;
use App\Models\Role;
use App\Models\Supplier;
use App\Models\User;
use App\Services\Audit\AuditHash;
use App\Support\CurrentOrganization;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\Process\InputStream;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class AuditConcurrencyTest extends TestCase
{
    use DatabaseMigrations;

    private Organization $organization;

    private User $actor;

    private Supplier $supplier;

    private AnalysisRun $run;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        [$this->organization, $this->actor] = $this->tenant();
        app(CurrentOrganization::class)->set($this->organization);
        $this->supplier = Supplier::create(['name' => 'Concurrent supplier']);
        $set = RequirementSet::create(['name' => 'Checklist', 'version' => 1, 'status' => 'published']);
        $this->run = AnalysisRun::create(['supplier_id' => $this->supplier->id, 'requirement_set_id' => $set->id, 'status' => 'completed', 'idempotency_key' => 'fixture', 'document_set_hash' => str_repeat('a', 64)]);
    }

    public function test_concurrent_first_events_share_one_chain_while_another_tenant_can_write(): void
    {
        $results = $this->race('audit', differentTenant: true);
        $this->assertSame([201, 201], array_column($results, 'status'));
        $logs = AuditLog::forOrganization($this->organization)->orderBy('id')->get();
        $this->assertCount(2, $logs);
        $this->assertNull($logs[0]->previous_hash);
        $this->assertSame($logs[0]->event_hash, $logs[1]->previous_hash);
        $this->assertTrue(app(AuditHash::class)->verifyChain($logs));
        $this->assertDatabaseCount('audit_logs', 3);
    }

    public function test_concurrent_decision_retry_creates_one_decision_and_audit(): void
    {
        $results = $this->race('decision');
        $this->assertSame([201, 200], array_column($results, 'status'));
        $this->assertSame($results[0]['id'], $results[1]['id']);
        $this->assertDatabaseCount('supplier_decisions', 1);
        $this->assertDatabaseCount('audit_logs', 1);
    }

    public function test_concurrent_different_decision_keys_cannot_create_two_final_decisions(): void
    {
        $results = $this->race('decision', differentKey: true);
        $this->assertSame([201, 409], array_column($results, 'status'));
        $this->assertDatabaseCount('supplier_decisions', 1);
        $this->assertDatabaseCount('audit_logs', 1);
    }

    private function race(string $mode, bool $differentTenant = false, bool $differentKey = false): array
    {
        $name = 'history-race-'.Str::uuid();
        $input = new InputStream;
        $workers = [
            $this->worker($this->organization, $this->actor, $mode, $name.'-0', 'pause', 'retry'),
            $this->worker($this->organization, $this->actor, $mode, $name.'-1', 'run', $differentKey ? 'other' : 'retry'),
        ];
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
            $this->assertSame('Lock', $state?->wait_event_type, 'The second writer must wait for the uncommitted tenant head.');
            if ($differentTenant) {
                [$organization, $actor] = $this->tenant();
                $other = $this->worker($organization, $actor, 'audit', $name.'-other', 'run', 'other');
                $other->mustRun();
                $this->assertSame(201, json_decode(trim($other->getOutput()), true)['status']);
                $this->assertNull(AuditLog::forOrganization($organization)->sole()->previous_hash);
            }
            $input->write("go\n");
            $input->close();
            $results = [];
            foreach ($workers as $worker) {
                $worker->wait();
                $this->assertTrue($worker->isSuccessful(), $worker->getErrorOutput());
                $lines = explode("\n", trim($worker->getOutput()));
                $results[] = json_decode(end($lines), true, flags: JSON_THROW_ON_ERROR);
            }

            return $results;
        } finally {
            $input->close();
            foreach ($workers as $worker) {
                if ($worker->isRunning()) {
                    $worker->stop();
                }
            }
        }
    }

    private function worker(Organization $organization, User $actor, string $mode, string $name, string $pause, string $key): Process
    {
        return new Process([PHP_BINARY, base_path('tests/Support/history-worker.php'), (string) $organization->id, (string) $actor->id,
            $mode, $this->supplier->public_id, $this->run->public_id, $name, $pause, $key], base_path(), timeout: 10);
    }

    private function tenant(): array
    {
        $organization = Organization::create(['name' => 'Concurrent tenant', 'slug' => (string) Str::uuid()]);
        $actor = User::factory()->create();
        $organization->users()->attach($actor, ['role_id' => Role::where('name', 'reviewer')->sole()->id]);

        return [$organization, $actor];
    }
}
