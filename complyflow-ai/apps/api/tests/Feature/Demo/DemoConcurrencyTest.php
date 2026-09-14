<?php

namespace Tests\Feature\Demo;

use App\Models\AuditLog;
use App\Models\DemoSession;
use App\Services\Audit\AuditHash;
use Database\Seeders\DemoTemplateSeeder;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Process\InputStream;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class DemoConcurrencyTest extends TestCase
{
    use DatabaseMigrations;

    public function test_concurrent_seeds_wait_for_one_complete_template(): void
    {
        $this->seed();
        $input = new InputStream;
        $a = $this->worker('seed', 'seed-a', true)->setInput($input);
        $b = $this->worker('seed', 'seed-b');
        try {
            $a->start();
            $this->ready($a);
            $b->start();
            $deadline = microtime(true) + 5;
            do {
                $state = DB::selectOne("SELECT wait_event_type FROM pg_stat_activity WHERE application_name = 'seed-b'");
                if ($state?->wait_event_type === 'Lock') {
                    break;
                }
                usleep(10000);
            } while ($b->isRunning() && microtime(true) < $deadline);
            $this->assertSame('Lock', $state?->wait_event_type);
            $this->assertDatabaseCount('organizations', 0);
            $input->write("go\n");
            $input->close();
            $a->wait();
            $b->wait();
            $this->assertTrue($a->isSuccessful(), $a->getErrorOutput());
            $this->assertTrue($b->isSuccessful(), $b->getErrorOutput());
            $this->assertDatabaseCount('organizations', 1);
            $this->assertDatabaseCount('suppliers', 3);
            $this->assertDatabaseCount('audit_logs', 5);
            $this->assertDatabaseCount('organization_user', 0);
        } finally {
            $input->close();
            $a->stop();
            $b->stop();
        }
    }

    public function test_two_clones_can_finish_before_either_commits_with_distinct_audit_chains(): void
    {
        $this->seed();
        $this->seed(DemoTemplateSeeder::class);
        $inputs = [new InputStream, new InputStream];
        $workers = [$this->worker('clone', 'clone-a', true)->setInput($inputs[0]), $this->worker('clone', 'clone-b', true)->setInput($inputs[1])];
        try {
            foreach ($workers as $worker) {
                $worker->start();
            }
            foreach ($workers as $worker) {
                $this->ready($worker);
            }
            $this->assertDatabaseCount('demo_sessions', 0);
            foreach ($inputs as $input) {
                $input->write("go\n");
                $input->close();
            }
            foreach ($workers as $worker) {
                $worker->wait();
                $this->assertTrue($worker->isSuccessful(), $worker->getErrorOutput());
            }
            $this->assertDatabaseCount('demo_sessions', 2);
            foreach (DemoSession::all() as $demo) {
                $logs = AuditLog::where('organization_id', $demo->organization_id)->orderBy('id')->get();
                $this->assertCount(5, $logs);
                $this->assertTrue(app(AuditHash::class)->verifyChain($logs));
                $this->assertSame(2, $demo->analyses_used);
                $this->assertSame(16416, $demo->storage_used_bytes);
            }
            $this->assertSame(15, AuditLog::distinct()->count('event_hash'));
        } finally {
            foreach ($inputs as $input) {
                $input->close();
            }
            foreach ($workers as $worker) {
                $worker->stop();
            }
        }
    }

    private function worker(string $mode, string $name, bool $pause = false): Process
    {
        return new Process([PHP_BINARY, base_path('tests/Support/demo-worker.php'), $mode, $name, $pause ? 'pause' : 'run'], base_path(), timeout: 15);
    }

    private function ready(Process $worker): void
    {
        $deadline = microtime(true) + 5;
        while (! str_contains($worker->getOutput(), 'ready') && $worker->isRunning() && microtime(true) < $deadline) {
            usleep(10000);
        }
        $this->assertStringContainsString('ready', $worker->getOutput(), $worker->getErrorOutput());
    }
}
