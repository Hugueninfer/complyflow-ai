<?php

namespace Tests\Feature\Auth;

use App\Models\Organization;
use App\Models\Role;
use App\Models\User;
use App\Support\CurrentOrganization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class RolePermissionTest extends TestCase
{
    use RefreshDatabase;

    public function test_analyst_cannot_record_supplier_decision(): void
    {
        $organization = $this->createOrganization();
        $analyst = $this->userWithRole($organization, 'analyst');
        app(CurrentOrganization::class)->set($organization);

        $this->assertFalse($analyst->hasPermission('supplier.decide'));
    }

    public function test_owner_and_reviewer_can_record_supplier_decision(): void
    {
        $organization = $this->createOrganization();
        $owner = $this->userWithRole($organization, 'owner');
        $reviewer = $this->userWithRole($organization, 'reviewer');
        app(CurrentOrganization::class)->set($organization);

        $this->assertTrue($owner->hasPermission('supplier.decide'));
        $this->assertTrue($reviewer->hasPermission('supplier.decide'));
    }

    public function test_permission_from_another_organization_is_not_inherited(): void
    {
        $organizationA = $this->createOrganization('Acme');
        $organizationB = $this->createOrganization('Globex');
        $user = $this->userWithRole($organizationA, 'owner');
        $organizationB->users()->attach($user, [
            'role_id' => Role::query()->where('name', 'analyst')->firstOrFail()->id,
        ]);
        app(CurrentOrganization::class)->set($organizationB);

        $this->assertFalse($user->hasPermission('supplier.decide'));
    }

    private function createOrganization(string $name = 'Northwind'): Organization
    {
        $this->seed();

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
}
