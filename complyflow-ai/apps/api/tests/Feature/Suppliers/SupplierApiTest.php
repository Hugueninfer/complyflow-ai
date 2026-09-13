<?php

namespace Tests\Feature\Suppliers;

use App\Models\DemoSession;
use App\Models\Organization;
use App\Models\Role;
use App\Models\Supplier;
use App\Models\User;
use App\Support\CurrentOrganization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class SupplierApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed();
    }

    public function test_supplier_is_created_in_current_organization_and_request_tenant_is_ignored(): void
    {
        $organization = $this->organization('Northwind');
        $otherOrganization = $this->organization('Globex');
        $owner = $this->userWithRole($organization, 'owner');

        $response = $this->actingAs($owner)->postJson('/api/v1/suppliers', [
            'organization_id' => $otherOrganization->id,
            'name' => 'NovaGuard Facilities',
            'tax_id' => '42.108.921/0001-84',
            'risk_level' => 'high',
        ])->assertCreated();

        $this->assertDatabaseHas('suppliers', [
            'public_id' => $response->json('data.id'),
            'organization_id' => $organization->id,
            'name' => 'NovaGuard Facilities',
        ]);
        $this->assertDatabaseMissing('suppliers', [
            'public_id' => $response->json('data.id'),
            'organization_id' => $otherOrganization->id,
        ]);
    }

    public function test_supplier_uuids_are_resolved_only_in_current_organization(): void
    {
        $organization = $this->organization('Northwind');
        $otherOrganization = $this->organization('Globex');
        $owner = $this->userWithRole($organization, 'owner');
        $ownSupplier = $this->supplier($organization, 'Own supplier');
        $foreignSupplier = $this->supplier($otherOrganization, 'Foreign supplier');

        $this->actingAs($owner)
            ->getJson('/api/v1/suppliers/'.$ownSupplier->public_id)
            ->assertOk()
            ->assertJsonPath('data.name', 'Own supplier');
        $this->actingAs($owner)
            ->getJson('/api/v1/suppliers/'.$foreignSupplier->public_id)
            ->assertNotFound();
        $this->actingAs($owner)
            ->putJson('/api/v1/suppliers/'.$foreignSupplier->public_id, ['name' => 'Stolen'])
            ->assertNotFound();
        $this->actingAs($owner)
            ->deleteJson('/api/v1/suppliers/'.$foreignSupplier->public_id)
            ->assertNotFound();
        $this->assertDatabaseHas('suppliers', [
            'id' => $foreignSupplier->id,
            'name' => 'Foreign supplier',
        ]);
    }

    public function test_supplier_listing_contains_only_current_organization_records(): void
    {
        $organization = $this->organization('Northwind');
        $otherOrganization = $this->organization('Globex');
        $reviewer = $this->userWithRole($organization, 'reviewer');
        $this->supplier($organization, 'Visible supplier');
        $this->supplier($otherOrganization, 'Hidden supplier');

        $this->actingAs($reviewer)->getJson('/api/v1/suppliers')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.name', 'Visible supplier');
    }

    public function test_analyst_can_create_and_update_suppliers_while_reviewer_can_only_read(): void
    {
        $organization = $this->organization('Northwind');
        $analyst = $this->userWithRole($organization, 'analyst');
        $reviewer = $this->userWithRole($organization, 'reviewer');

        $supplierId = $this->actingAs($analyst)->postJson('/api/v1/suppliers', [
            'name' => 'Original supplier',
            'risk_level' => 'medium',
        ])->assertCreated()->json('data.id');

        $this->actingAs($analyst)->putJson('/api/v1/suppliers/'.$supplierId, [
            'name' => 'Updated supplier',
            'risk_level' => 'low',
        ])->assertOk()->assertJsonPath('data.name', 'Updated supplier');

        $this->actingAs($reviewer)->getJson('/api/v1/suppliers/'.$supplierId)
            ->assertOk()
            ->assertJsonPath('data.name', 'Updated supplier');
        $this->actingAs($reviewer)->postJson('/api/v1/suppliers', [
            'name' => 'Forbidden supplier',
            'risk_level' => 'high',
        ])->assertForbidden();
        $this->actingAs($reviewer)->putJson('/api/v1/suppliers/'.$supplierId, [
            'name' => 'Forbidden update',
        ])->assertForbidden();
    }

    public function test_demo_supplier_creation_atomically_enforces_quota_and_increments_only_on_persistence(): void
    {
        $organization = $this->organization('Demo tenant');
        $owner = $this->userWithRole($organization, 'owner');
        $demo = DemoSession::query()->create([
            'organization_id' => $organization->id,
            'user_id' => $owner->id,
            'token_hash' => hash('sha256', 'supplier-demo-token'),
            'supplier_quota' => 10,
            'analysis_quota' => 3,
            'storage_quota_bytes' => 15 * 1024 * 1024,
            'suppliers_used' => 9,
            'expires_at' => now()->addHour(),
        ]);

        $this->actingAs($owner)->postJson('/api/v1/suppliers', [
            'name' => 'Tenth supplier',
            'risk_level' => 'medium',
        ])->assertCreated();
        $this->assertSame(10, $demo->fresh()->suppliers_used);
        $this->assertDatabaseCount('suppliers', 1);

        $this->actingAs($owner)->postJson('/api/v1/suppliers', [
            'name' => 'Eleventh supplier',
            'risk_level' => 'medium',
        ])->assertTooManyRequests()->assertJsonPath('message', 'Demo quota exceeded.');
        $this->assertSame(10, $demo->fresh()->suppliers_used);
        $this->assertDatabaseCount('suppliers', 1);
    }

    public function test_failed_demo_supplier_validation_does_not_consume_quota(): void
    {
        $organization = $this->organization('Demo tenant');
        $owner = $this->userWithRole($organization, 'owner');
        $demo = DemoSession::query()->create([
            'organization_id' => $organization->id,
            'user_id' => $owner->id,
            'token_hash' => hash('sha256', 'invalid-demo-token'),
            'supplier_quota' => 10,
            'analysis_quota' => 3,
            'storage_quota_bytes' => 15 * 1024 * 1024,
            'suppliers_used' => 4,
            'expires_at' => now()->addHour(),
        ]);

        $this->actingAs($owner)->postJson('/api/v1/suppliers', [
            'name' => '',
            'risk_level' => 'unknown',
        ])->assertUnprocessable();

        $this->assertSame(4, $demo->fresh()->suppliers_used);
        $this->assertDatabaseCount('suppliers', 0);
    }

    public function test_analyst_can_soft_delete_current_organization_supplier(): void
    {
        $organization = $this->organization('Northwind');
        $analyst = $this->userWithRole($organization, 'analyst');
        $supplier = $this->supplier($organization, 'Disposable supplier');

        $this->actingAs($analyst)
            ->deleteJson('/api/v1/suppliers/'.$supplier->public_id)
            ->assertNoContent();

        $this->assertSoftDeleted('suppliers', ['id' => $supplier->id]);
    }

    private function organization(string $name): Organization
    {
        return Organization::query()->create([
            'name' => $name,
            'slug' => Str::slug($name).'-'.Str::lower(Str::random(6)),
        ]);
    }

    private function userWithRole(Organization $organization, string $role): User
    {
        $user = User::factory()->create();
        $roleId = Role::query()->where('name', $role)->firstOrFail()->id;
        $organization->users()->attach($user, ['role_id' => $roleId]);

        return $user;
    }

    private function supplier(Organization $organization, string $name): Supplier
    {
        app(CurrentOrganization::class)->set($organization);

        try {
            return Supplier::query()->create(['name' => $name, 'risk_level' => 'medium']);
        } finally {
            app(CurrentOrganization::class)->clear();
        }
    }
}
