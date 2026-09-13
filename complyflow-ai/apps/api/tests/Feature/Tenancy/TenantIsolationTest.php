<?php

namespace Tests\Feature\Tenancy;

use App\Models\Organization;
use App\Models\Role;
use App\Models\Supplier;
use App\Models\User;
use App\Support\CurrentOrganization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Tests\TestCase;

class TenantIsolationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Route::middleware(['auth', 'organization'])
            ->get('/api/v1/suppliers/{supplier}', function (string $supplier) {
                $record = Supplier::query()
                    ->wherePublicIdForCurrentOrganization($supplier)
                    ->firstOrFail();

                return response()->json(['data' => ['id' => $record->public_id]]);
            });
    }

    public function test_user_cannot_resolve_supplier_from_another_organization(): void
    {
        [$organizationA, $ownerA] = $this->organizationWithOwner('Northwind');
        [$organizationB] = $this->organizationWithOwner('Contoso');
        $supplierOfB = $this->createSupplier($organizationB->id, 'Contoso Security');

        $this->assertNull(
            Supplier::query()
                ->forOrganization($organizationA)
                ->where('public_id', $supplierOfB)
                ->first(),
        );

        $this->actingAs($ownerA)
            ->getJson('/api/v1/suppliers/'.$supplierOfB)
            ->assertNotFound();

        $this->assertNotSame($organizationA->id, $organizationB->id);
    }

    public function test_user_can_resolve_supplier_from_own_organization(): void
    {
        [$organization, $owner] = $this->organizationWithOwner('Northwind');
        $supplier = $this->createSupplier($organization->id, 'Northwind Security');

        app(CurrentOrganization::class)->set($organization);
        $resolved = Supplier::query()
            ->wherePublicIdForCurrentOrganization($supplier)
            ->firstOrFail();
        app(CurrentOrganization::class)->clear();

        $this->assertSame($supplier, $resolved->public_id);

        $this->actingAs($owner)
            ->getJson('/api/v1/suppliers/'.$supplier)
            ->assertOk()
            ->assertJsonPath('data.id', $supplier);
    }

    /**
     * @return array{Organization, User}
     */
    private function organizationWithOwner(string $name): array
    {
        $organization = Organization::query()->create([
            'name' => $name,
            'slug' => Str::slug($name).'-'.Str::lower(Str::random(6)),
        ]);
        $owner = User::factory()->create();
        $ownerRole = Role::query()->firstOrCreate(['name' => 'owner']);

        $organization->users()->attach($owner, ['role_id' => $ownerRole->id]);

        return [$organization, $owner];
    }

    private function createSupplier(int $organizationId, string $name): string
    {
        $currentOrganization = app(CurrentOrganization::class);
        $currentOrganization->set($organizationId);

        try {
            return Supplier::query()->create([
                'name' => $name,
                'risk_level' => 'medium',
            ])->public_id;
        } finally {
            $currentOrganization->clear();
        }
    }
}
