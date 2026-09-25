<?php

namespace Tests\Feature\Requirements;

use App\Models\Organization;
use App\Models\RequirementSet;
use App\Models\Role;
use App\Models\User;
use App\Support\CurrentOrganization;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Process\InputStream;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class RequirementNameConcurrencyTest extends TestCase
{
    use DatabaseMigrations;

    public static function firstWriters(): array
    {
        return ['rename versus rename' => ['rename'], 'store versus rename' => ['store']];
    }

    #[DataProvider('firstWriters')]
    public function test_concurrent_cross_version_writers_cannot_claim_the_same_name(string $first): void
    {
        $this->seed();
        $organization = Organization::create(['name' => 'Name race', 'slug' => (string) Str::uuid()]);
        app(CurrentOrganization::class)->set($organization);
        $owner = User::factory()->create();
        $organization->users()->attach($owner, ['role_id' => Role::where('name', 'owner')->sole()->id]);
        $a = RequirementSet::create(['name' => 'A', 'version' => 1, 'status' => 'draft']);
        $b = RequirementSet::create(['name' => 'B', 'version' => 1, 'status' => 'published']);
        $b2 = RequirementSet::create(['parent_id' => $b->id, 'name' => 'B', 'version' => 2, 'status' => 'draft']);
        $name = 'name-race-'.Str::uuid();
        $input = new InputStream;
        $workers = [];
        foreach ([$a, $b2] as $index => $source) {
            $mode = $index === 0 ? $first : 'rename';
            $workers[] = new Process([PHP_BINARY, base_path('tests/Support/checklist-decision-worker.php'),
                (string) $organization->id, (string) $owner->id, $mode, '', '', $source->public_id,
                $name.'-'.$index, $mode === 'store' ? 'insert' : 'lock', 'Shared name'], base_path(), timeout: 15);
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
            $input->write("go\n");
            $input->close();
            $results = [];
            foreach ($workers as $worker) {
                $worker->wait();
                $this->assertTrue($worker->isSuccessful(), $worker->getErrorOutput());
                $lines = explode("\n", trim($worker->getOutput()));
                $results[] = json_decode(end($lines), true, flags: JSON_THROW_ON_ERROR);
            }
            $this->assertSame([$first === 'store' ? 201 : 200, 422], array_column($results, 'status'));
            $this->assertArrayHasKey('name', $results[1]['errors']);
            $this->assertSame('Lock', $state?->wait_event_type);
            $this->assertSame($first === 'store' ? 'A' : 'Shared name', $a->fresh()->name);
            $this->assertSame('B', $b2->fresh()->name);
            $this->assertSame(1, RequirementSet::where('name', 'Shared name')->count());
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
