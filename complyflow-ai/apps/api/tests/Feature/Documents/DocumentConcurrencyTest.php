<?php

namespace Tests\Feature\Documents;

use App\Models\DemoSession;
use App\Models\Organization;
use App\Models\Supplier;
use App\Models\User;
use App\Support\CurrentOrganization;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\Process\InputStream;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class DocumentConcurrencyTest extends TestCase
{
    use DatabaseMigrations;

    public function test_concurrent_same_supplier_uploads_return_one_document_and_one_blob(): void
    {
        $organization = $this->tenant();
        $supplier = Supplier::query()->create(['name' => 'Supplier']);
        $results = $this->race($organization, $supplier, $supplier);

        $this->assertSame([201, 200], array_column($results, 'status'));
        $this->assertSame($results[0]['id'], $results[1]['id']);
        $this->assertDatabaseCount('documents', 1);
        $this->assertDatabaseCount('document_blobs', 1);
    }

    public function test_concurrent_uploads_to_different_demo_suppliers_cannot_exceed_tenant_quota(): void
    {
        $organization = $this->tenant();
        $first = Supplier::query()->create(['name' => 'First']);
        $second = Supplier::query()->create(['name' => 'Second']);
        $demo = DemoSession::query()->create([
            'organization_id' => $organization->id,
            'user_id' => User::factory()->create()->id,
            'token_hash' => hash('sha256', (string) Str::uuid()),
            'storage_quota_bytes' => 15728640,
            'storage_used_bytes' => 15728630,
            'expires_at' => now()->addHour(),
        ]);

        $results = $this->race($organization, $first, $second);

        $this->assertSame([201, 413], array_column($results, 'status'));
        $this->assertSame(15728640, $demo->fresh()->storage_used_bytes);
        $this->assertDatabaseCount('documents', 1);
        $this->assertDatabaseCount('document_blobs', 1);
    }

    private function tenant(): Organization
    {
        $organization = Organization::query()->create(['name' => 'Race', 'slug' => (string) Str::uuid()]);
        app(CurrentOrganization::class)->set($organization);

        return $organization;
    }

    /** @return array<int, array<string, mixed>> */
    private function race(Organization $organization, Supplier $first, Supplier $second): array
    {
        $file = UploadedFile::fake()->createWithContent('race.pdf', "%PDF-1.7\n%");
        $name = 'document-race-'.Str::uuid();
        $input = new InputStream;
        $workers = [];

        foreach ([$first, $second] as $index => $supplier) {
            $workers[] = new Process([
                PHP_BINARY, base_path('tests/Support/store-pdf-worker.php'),
                (string) $organization->id, $supplier->public_id, $file->getRealPath(),
                $name.'-'.$index, $index === 0 ? 'pause' : 'run',
            ], base_path(), timeout: 10);
        }

        try {
            $workers[0]->setInput($input)->start();
            $deadline = microtime(true) + 5;
            while (! str_contains($workers[0]->getOutput(), 'ready') && $workers[0]->isRunning() && microtime(true) < $deadline) {
                usleep(10000);
            }
            $this->assertStringContainsString('ready', $workers[0]->getOutput(), $workers[0]->getErrorOutput());
            $workers[1]->start();
            // Release the first transaction once the second either waits on its lock or completes without one.
            $deadline = microtime(true) + 5;
            while ($workers[1]->isRunning() && microtime(true) < $deadline) {
                $state = DB::selectOne('SELECT wait_event_type FROM pg_stat_activity WHERE application_name = ?', [$name.'-1']);
                if ($state?->wait_event_type === 'Lock') {
                    break;
                }
                usleep(10000);
            }
            $input->write("go\n");
            $input->close();
            $workers[0]->wait();
            $workers[1]->wait();

            return array_map(function (Process $worker): array {
                $this->assertTrue($worker->isSuccessful(), $worker->getErrorOutput());
                $lines = explode("\n", trim($worker->getOutput()));

                return json_decode(end($lines), true, flags: JSON_THROW_ON_ERROR);
            }, $workers);
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
