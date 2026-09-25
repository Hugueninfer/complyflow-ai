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
        $this->supplier($otherOrganization, 'Foreign duplicate', '42.108.921/0001-84');

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
        $ownSupplier = $this->supplier($organization, 'Own supplier', '42.108.921/0001-84');
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
            ->putJson('/api/v1/suppliers/'.$foreignSupplier->public_id, [
                'tax_id' => $ownSupplier->tax_id,
            ])
            ->assertNotFound();
        $this->actingAs($owner)
            ->deleteJson('/api/v1/suppliers/'.$foreignSupplier->public_id)
            ->assertNotFound();
        $this->assertDatabaseHas('suppliers', [
            'id' => $foreignSupplier->id,
            'name' => 'Foreign supplier',
        ]);
    }

    public function test_malformed_supplier_uuid_is_not_found_for_every_member_route(): void
    {
        $owner = $this->userWithRole($this->organization('Northwind'), 'owner');
        $malformed = 'not-a-uuid';

        $this->actingAs($owner)->getJson('/api/v1/suppliers/'.$malformed)->assertNotFound();
        $this->actingAs($owner)->putJson('/api/v1/suppliers/'.$malformed, ['name' => 'Ignored'])->assertNotFound();
        $this->actingAs($owner)->patchJson('/api/v1/suppliers/'.$malformed, ['name' => 'Ignored'])->assertNotFound();
        $this->actingAs($owner)->deleteJson('/api/v1/suppliers/'.$malformed)->assertNotFound();
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

    public function test_duplicate_tax_id_is_rejected_on_create_and_update_without_server_error(): void
    {
        $organization = $this->organization('Northwind');
        $owner = $this->userWithRole($organization, 'owner');
        $first = $this->supplier($organization, 'First supplier', '42.108.921/0001-84');
        $second = $this->supplier($organization, 'Second supplier', '73.260.643/0001-08');

        $this->actingAs($owner)->postJson('/api/v1/suppliers', [
            'name' => 'Duplicate supplier',
            'tax_id' => $first->tax_id,
            'risk_level' => 'medium',
        ])->assertUnprocessable()->assertJsonValidationErrors('tax_id');

        $this->actingAs($owner)->putJson('/api/v1/suppliers/'.$second->public_id, [
            'tax_id' => $first->tax_id,
        ])->assertUnprocessable()->assertJsonValidationErrors('tax_id');
        $this->assertDatabaseHas('suppliers', [
            'id' => $second->id,
            'tax_id' => '73.260.643/0001-08',
        ]);
    }

    public function test_supplier_update_may_keep_its_own_tax_id(): void
    {
        $organization = $this->organization('Northwind');
        $owner = $this->userWithRole($organization, 'owner');
        $supplier = $this->supplier($organization, 'Original supplier', '42.108.921/0001-84');

        $this->actingAs($owner)->putJson('/api/v1/suppliers/'.$supplier->public_id, [
            'name' => 'Renamed supplier',
            'tax_id' => $supplier->tax_id,
        ])->assertOk()->assertJsonPath('data.name', 'Renamed supplier');
    }

    public function test_supplier_unique_constraint_race_returns_validation_error_and_rolls_back(): void
    {
        $organization = $this->organization('Northwind');
        $owner = $this->userWithRole($organization, 'owner');
        Supplier::query()->count();
        $insertedByRace = false;

        Supplier::creating(function (Supplier $supplier) use (&$insertedByRace): void {
            if ($insertedByRace || $supplier->tax_id !== '19.870.554/0001-70') {
                return;
            }

            $insertedByRace = true;
            Supplier::query()->getQuery()->insert([
                'public_id' => Str::uuid(),
                'organization_id' => $supplier->organization_id,
                'name' => 'Concurrent supplier',
                'tax_id' => $supplier->tax_id,
                'risk_level' => 'medium',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        });

        $this->actingAs($owner)->postJson('/api/v1/suppliers', [
            'name' => 'Racing supplier',
            'tax_id' => '19.870.554/0001-70',
            'risk_level' => 'high',
        ])->assertUnprocessable()->assertJsonValidationErrors('tax_id');

        $this->assertDatabaseMissing('suppliers', ['tax_id' => '19.870.554/0001-70']);
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

    private function supplier(Organization $organization, string $name, ?string $taxId = null): Supplier
    {
        app(CurrentOrganization::class)->set($organization);

        try {
            return Supplier::query()->create([
                'name' => $name,
                'tax_id' => $taxId,
                'risk_level' => 'medium',
            ]);
        } finally {
            app(CurrentOrganization::class)->clear();
        }
    }
}
