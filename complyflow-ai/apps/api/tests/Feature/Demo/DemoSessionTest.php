<?php

namespace Tests\Feature\Demo;

use App\Models\Organization;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class DemoSessionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed();

        foreach (['suppliers', 'analyses', 'storage'] as $quota) {
            Route::middleware(['web', 'auth:sanctum', 'demo.quota:'.$quota])
                ->post('/api/v1/testing/demo-quota/'.$quota, fn (Request $request) => response()->noContent());
        }

        Route::middleware(['web', 'auth:sanctum', 'organization'])
            ->get('/api/v1/testing/demo-tenant', fn () => response()->noContent());
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_demo_expires_exactly_after_24_hours(): void
    {
        Carbon::setTestNow('2026-09-13 12:00:00');

        $response = $this->postJson('/api/v1/demo-sessions')->assertCreated();

        $this->assertSame('2026-09-14T12:00:00.000000Z', $response->json('data.expires_at'));
    }

    public function test_demo_has_the_defined_supplier_analysis_and_storage_quotas(): void
    {
        $response = $this->postJson('/api/v1/demo-sessions')->assertCreated();

        $response->assertJsonPath('data.quotas.suppliers', 10)
            ->assertJsonPath('data.quotas.analyses', 3)
            ->assertJsonPath('data.quotas.storage_bytes', 15 * 1024 * 1024);
    }

    public function test_demo_session_is_valid_at_23_59_59_and_invalid_exactly_at_24_hours(): void
    {
        Carbon::setTestNow('2026-09-13 12:00:00');
        $this->postJson('/api/v1/demo-sessions')->assertCreated();

        Carbon::setTestNow('2026-09-14 11:59:59');
        $this->getJson('/api/v1/testing/demo-tenant')->assertNoContent();

        Carbon::setTestNow('2026-09-14 12:00:00');
        $this->getJson('/api/v1/testing/demo-tenant')
            ->assertUnauthorized()
            ->assertJsonPath('message', 'Demo session expired.');
        $this->assertGuest('web');
    }

    public function test_demo_quota_middleware_rejects_each_exhausted_resource(): void
    {
        $organizationPublicId = $this->postJson('/api/v1/demo-sessions')
            ->assertCreated()
            ->json('data.organization_id');
        $organizationId = Organization::query()->where('public_id', $organizationPublicId)->value('id');

        DB::table('demo_sessions')->where('organization_id', $organizationId)->update([
            'suppliers_used' => 10,
            'analyses_used' => 3,
            'storage_used_bytes' => 15 * 1024 * 1024,
        ]);

        foreach (['suppliers', 'analyses', 'storage'] as $quota) {
            $this->postJson('/api/v1/testing/demo-quota/'.$quota)
                ->assertTooManyRequests()
                ->assertJsonPath('message', 'Demo quota exceeded.');
        }
    }

    public function test_purge_removes_only_demos_expired_at_or_before_now_and_is_idempotent(): void
    {
        Carbon::setTestNow('2026-09-13 12:00:00');
        $expiredOrganization = $this->postJson('/api/v1/demo-sessions')->json('data.organization_id');
        $this->postJson('/api/v1/logout')->assertNoContent();

        Carbon::setTestNow('2026-09-13 12:00:01');
        $activeOrganization = $this->postJson('/api/v1/demo-sessions')->json('data.organization_id');
        $this->postJson('/api/v1/logout')->assertNoContent();

        $expiredOrganizationId = Organization::query()->where('public_id', $expiredOrganization)->value('id');
        $activeOrganizationId = Organization::query()->where('public_id', $activeOrganization)->value('id');
        $blobId = $this->createBlobFor($expiredOrganizationId);

        Carbon::setTestNow('2026-09-14 12:00:00');

        $this->artisan('demo:purge-expired')->assertSuccessful();
        $this->artisan('demo:purge-expired')->assertSuccessful();

        $this->assertDatabaseMissing('document_blobs', ['id' => $blobId]);
        $this->assertDatabaseMissing('organizations', ['id' => $expiredOrganizationId]);
        $this->assertDatabaseMissing('demo_sessions', ['organization_id' => $expiredOrganizationId]);
        $this->assertDatabaseHas('organizations', ['id' => $activeOrganizationId]);
        $this->assertDatabaseHas('demo_sessions', ['organization_id' => $activeOrganizationId]);
    }

    private function createBlobFor(int $organizationId): int
    {
        $supplierId = DB::table('suppliers')->insertGetId([
            'public_id' => fake()->uuid(),
            'organization_id' => $organizationId,
            'name' => 'Disposable supplier',
            'risk_level' => 'medium',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $documentId = DB::table('documents')->insertGetId([
            'public_id' => fake()->uuid(),
            'organization_id' => $organizationId,
            'supplier_id' => $supplierId,
            'original_name' => 'disposable.txt',
            'storage_name' => fake()->uuid().'.txt',
            'mime_type' => 'text/plain',
            'size_bytes' => 4,
            'sha256' => hash('sha256', 'demo'),
            'status' => 'ready',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return DB::table('document_blobs')->insertGetId([
            'public_id' => fake()->uuid(),
            'organization_id' => $organizationId,
            'document_id' => $documentId,
            'contents' => 'demo',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
