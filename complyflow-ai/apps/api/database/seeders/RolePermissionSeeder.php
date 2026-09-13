<?php

namespace Database\Seeders;

use App\Models\Permission;
use App\Models\Role;
use Illuminate\Database\Seeder;

class RolePermissionSeeder extends Seeder
{
    /**
     * @var array<string, list<string>>
     */
    private const ROLE_PERMISSIONS = [
        'owner' => [
            'supplier.view', 'supplier.create', 'supplier.update', 'supplier.decide',
            'requirement.view', 'requirement.create', 'requirement.update', 'requirement.publish',
            'document.view', 'document.upload',
            'analysis.view', 'analysis.run',
            'finding.review', 'audit.view',
        ],
        'analyst' => [
            'supplier.view', 'supplier.create', 'supplier.update',
            'requirement.view', 'requirement.create', 'requirement.update',
            'document.view', 'document.upload',
            'analysis.view', 'analysis.run',
        ],
        'reviewer' => [
            'supplier.view', 'supplier.decide',
            'requirement.view',
            'document.view',
            'analysis.view',
            'finding.review', 'audit.view',
        ],
    ];

    public function run(): void
    {
        $permissions = collect(self::ROLE_PERMISSIONS)
            ->flatten()
            ->unique()
            ->mapWithKeys(function (string $name): array {
                $permission = Permission::query()->firstOrCreate(['name' => $name]);

                return [$name => $permission->id];
            });

        foreach (self::ROLE_PERMISSIONS as $roleName => $permissionNames) {
            $role = Role::query()->firstOrCreate(['name' => $roleName]);
            $role->permissions()->sync($permissions->only($permissionNames)->values()->all());
        }
    }
}
