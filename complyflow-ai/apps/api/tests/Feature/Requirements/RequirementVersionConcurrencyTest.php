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
use Symfony\Component\Process\InputStream;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class RequirementVersionConcurrencyTest extends TestCase
{
    use DatabaseMigrations;

    public function test_concurrent_version_requests_skip_deleted_numbers_and_create_one_active_draft(): void
    {
        $this->seed();
        $organization = Organization::create(['name' => 'Version race', 'slug' => (string) Str::uuid()]);
        app(CurrentOrganization::class)->set($organization);
        $owner = User::factory()->create();
        $organization->users()->attach($owner, ['role_id' => Role::where('name', 'owner')->sole()->id]);
        $root = RequirementSet::create(['name' => 'Checklist', 'version' => 1, 'status' => 'published']);
        $root->requirements()->create(['code' => 'R1', 'title' => 'Criterion', 'category' => 'Compliance', 'weight' => 1, 'position' => 0, 'evaluation_text' => 'Find evidence']);
        $deleted = RequirementSet::create(['parent_id' => $root->id, 'name' => 'Renamed deleted draft', 'version' => 2, 'status' => 'draft']);
        $deleted->delete();
        $name = 'version-race-'.Str::uuid();
        $input = new InputStream;
        $workers = [];
        foreach ([0, 1] as $index) {
            $workers[] = new Process([PHP_BINARY, base_path('tests/Support/checklist-decision-worker.php'),
                (string) $organization->id, (string) $owner->id, 'version', '', '', $root->public_id,
                $name.'-'.$index, 'lock', ''], base_path(), timeout: 15);
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
            $this->assertSame('Lock', $state?->wait_event_type);
            $input->write("go\n");
            $input->close();
            $results = [];
            foreach ($workers as $worker) {
                $worker->wait();
                $this->assertTrue($worker->isSuccessful(), $worker->getErrorOutput());
                $lines = explode("\n", trim($worker->getOutput()));
                $results[] = json_decode(end($lines), true, flags: JSON_THROW_ON_ERROR);
            }
            $this->assertSame([201, 409], array_column($results, 'status'));
            $this->assertSame(3, $results[0]['data']['version']);
            $draft = RequirementSet::where('parent_id', $root->id)->sole();
            $this->assertSame(3, $draft->version);
            $this->assertSame(['R1'], $draft->requirements()->pluck('code')->all());
            $this->assertSoftDeleted('requirement_sets', ['id' => $deleted->id]);
            $this->actingAs($owner)->postJson('/api/v1/requirement-sets/'.$draft->public_id.'/publish')->assertOk();
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
