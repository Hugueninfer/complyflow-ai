<?php

namespace Tests\Feature\Tenancy;

use App\Models\Organization;
use App\Models\Role;
use App\Models\User;
use App\Support\CurrentOrganization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
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
                $record = DB::table('suppliers')
                    ->where('organization_id', app(CurrentOrganization::class)->id())
                    ->where('public_id', $supplier)
                    ->firstOrFail();

                return response()->json(['data' => ['id' => $record->public_id]]);
            });
    }

    public function test_user_cannot_resolve_supplier_from_another_organization(): void
    {
        [$organizationA, $ownerA] = $this->organizationWithOwner('Northwind');
        [$organizationB] = $this->organizationWithOwner('Contoso');
        $supplierOfB = $this->createSupplier($organizationB->id, 'Contoso Security');

        $this->actingAs($ownerA)
            ->getJson('/api/v1/suppliers/'.$supplierOfB)
            ->assertNotFound();

        $this->assertNotSame($organizationA->id, $organizationB->id);
    }

    public function test_user_can_resolve_supplier_from_own_organization(): void
    {
        [$organization, $owner] = $this->organizationWithOwner('Northwind');
        $supplier = $this->createSupplier($organization->id, 'Northwind Security');

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
        $publicId = (string) Str::uuid();

        DB::table('suppliers')->insert([
            'organization_id' => $organizationId,
            'public_id' => $publicId,
            'name' => $name,
            'risk_level' => 'medium',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $publicId;
    }
}
