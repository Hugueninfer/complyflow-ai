<?php

namespace Tests\Feature\Requirements;

use App\Models\Organization;
use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class RequirementSetLineageMigrationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed();
    }

    public function test_upgrade_normalizes_legacy_descendant_to_root_and_prevents_renamed_v3_duplicate(): void
    {
        [$owner, $v1, $v2, $v3] = $this->upgradeLegacyLineage(softDeleteV3: false);

        $this->assertSame($v1, DB::table('requirement_sets')->where('id', $v3)->value('parent_id'));
        $this->actingAs($owner)
            ->postJson('/api/v1/requirement-sets/'.DB::table('requirement_sets')->where('id', $v2)->value('public_id').'/versions')
            ->assertStatus(409)
            ->assertJsonPath('message', 'A draft requirement set version already exists.');
        $this->assertSame(1, DB::table('requirement_sets')->where('version', 3)->count());
    }

    public function test_upgrade_normalizes_soft_deleted_legacy_v3_and_keeps_sequence_reserved(): void
    {
        [$owner, $v1, $v2, $v3] = $this->upgradeLegacyLineage(softDeleteV3: true);

        $this->assertSame($v1, DB::table('requirement_sets')->where('id', $v3)->value('parent_id'));
        $this->actingAs($owner)
            ->postJson('/api/v1/requirement-sets/'.DB::table('requirement_sets')->where('id', $v2)->value('public_id').'/versions')
            ->assertCreated()
            ->assertJsonPath('data.version', 4);
        $this->assertSame(1, DB::table('requirement_sets')->where('version', 3)->count());
        $this->assertSame($v1, DB::table('requirement_sets')->where('version', 4)->value('parent_id'));
    }

    /** @return array{User, int, int, int} */
    private function upgradeLegacyLineage(bool $softDeleteV3): array
    {
        $migration = $this->lineageMigration();
        $migration->down();
        $organization = Organization::query()->create([
            'name' => 'Legacy tenant',
            'slug' => 'legacy-'.Str::lower(Str::random(8)),
        ]);
        $owner = User::factory()->create();
        $ownerRole = Role::query()->where('name', 'owner')->firstOrFail();
        $organization->users()->attach($owner, ['role_id' => $ownerRole->id]);
        $v1 = $this->insertSet($organization, null, 'Legacy Controls', 1, 'published');
        $v2 = $this->insertSet($organization, $v1, 'Renamed v2', 2, 'published');
        $v3 = $this->insertSet(
            $organization,
            $v2,
            'Renamed legacy v3',
            3,
            'draft',
            $softDeleteV3 ? now() : null,
        );

        $migration->up();

        return [$owner, $v1, $v2, $v3];
    }

    private function insertSet(
        Organization $organization,
        ?int $parentId,
        string $name,
        int $version,
        string $status,
        mixed $deletedAt = null,
    ): int {
        return DB::table('requirement_sets')->insertGetId([
            'public_id' => Str::uuid(),
            'organization_id' => $organization->id,
            'parent_id' => $parentId,
            'name' => $name,
            'version' => $version,
            'status' => $status,
            'published_at' => $status === 'published' ? now() : null,
            'created_at' => now(),
            'updated_at' => now(),
            'deleted_at' => $deletedAt,
        ]);
    }

    private function lineageMigration(): Migration
    {
        return require database_path('migrations/2026_09_13_000004_add_requirement_set_lineage_constraint.php');
    }
}
