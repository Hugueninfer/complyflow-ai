<?php

namespace Tests\Feature\Requirements;

use App\Models\Organization;
use App\Models\RequirementSet;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class RequirementSetApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed();
    }

    public function test_analyst_creates_a_draft_with_requirements_in_current_organization(): void
    {
        $organization = $this->organization('Northwind');
        $otherOrganization = $this->organization('Globex');
        $analyst = $this->userWithRole($organization, 'analyst');

        $response = $this->actingAs($analyst)->postJson('/api/v1/requirement-sets', [
            'organization_id' => $otherOrganization->id,
            'name' => 'Vendor Security',
            'requirements' => [$this->requirement('SEC-001', 'Access control', 1)],
        ])->assertCreated()
            ->assertJsonPath('data.version', 1)
            ->assertJsonPath('data.status', 'draft')
            ->assertJsonPath('data.requirements.0.code', 'SEC-001');

        $set = DB::table('requirement_sets')->where('public_id', $response->json('data.id'))->first();
        $this->assertSame($organization->id, $set->organization_id);
        $this->assertDatabaseHas('requirements', [
            'requirement_set_id' => $set->id,
            'organization_id' => $organization->id,
            'code' => 'SEC-001',
        ]);
    }

    public function test_reviewer_can_read_but_cannot_create_or_edit_requirement_sets(): void
    {
        $organization = $this->organization('Northwind');
        $analyst = $this->userWithRole($organization, 'analyst');
        $reviewer = $this->userWithRole($organization, 'reviewer');
        $setId = $this->createSet($analyst);

        $this->actingAs($reviewer)->getJson('/api/v1/requirement-sets/'.$setId)
            ->assertOk()
            ->assertJsonPath('data.name', 'Vendor Security');
        $this->actingAs($reviewer)->postJson('/api/v1/requirement-sets', [
            'name' => 'Forbidden set',
            'requirements' => [$this->requirement('NO-001', 'No access', 1)],
        ])->assertForbidden();
        $this->actingAs($reviewer)->putJson('/api/v1/requirement-sets/'.$setId, [
            'name' => 'Forbidden edit',
        ])->assertForbidden();
    }

    public function test_requirement_set_listing_contains_only_current_organization_records(): void
    {
        $organization = $this->organization('Northwind');
        $otherOrganization = $this->organization('Globex');
        $reviewer = $this->userWithRole($organization, 'reviewer');
        $owner = $this->userWithRole($organization, 'owner');
        $foreignOwner = $this->userWithRole($otherOrganization, 'owner');
        $this->createSet($owner);
        $this->createSet($foreignOwner);

        $this->actingAs($reviewer)->getJson('/api/v1/requirement-sets')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.name', 'Vendor Security');
    }

    public function test_only_owner_can_publish_and_published_requirement_set_cannot_be_edited(): void
    {
        $organization = $this->organization('Northwind');
        $owner = $this->userWithRole($organization, 'owner');
        $analyst = $this->userWithRole($organization, 'analyst');
        $setId = $this->createSet($analyst);

        $this->actingAs($analyst)
            ->postJson('/api/v1/requirement-sets/'.$setId.'/publish')
            ->assertForbidden();
        $this->actingAs($owner)
            ->postJson('/api/v1/requirement-sets/'.$setId.'/publish')
            ->assertOk()
            ->assertJsonPath('data.status', 'published');

        $this->actingAs($owner)->putJson('/api/v1/requirement-sets/'.$setId, [
            'name' => 'Alterado',
        ])->assertStatus(409);
        $this->assertDatabaseHas('requirement_sets', [
            'public_id' => $setId,
            'name' => 'Vendor Security',
            'status' => 'published',
        ]);
    }

    public function test_published_immutability_precedes_unique_payload_validation(): void
    {
        $organization = $this->organization('Northwind');
        $owner = $this->userWithRole($organization, 'owner');
        $publishedId = $this->createSet($owner, 'Published Controls');
        $this->createSet($owner, 'Conflicting Controls');
        $this->actingAs($owner)->postJson('/api/v1/requirement-sets/'.$publishedId.'/publish')->assertOk();

        $this->actingAs($owner)->putJson('/api/v1/requirement-sets/'.$publishedId, [
            'name' => 'Conflicting Controls',
        ])->assertStatus(409)
            ->assertJsonPath('message', 'Published requirement sets are immutable.');
    }

    public function test_update_precedence_preserves_forbidden_and_foreign_not_found_responses(): void
    {
        $organization = $this->organization('Northwind');
        $otherOrganization = $this->organization('Globex');
        $owner = $this->userWithRole($organization, 'owner');
        $reviewer = $this->userWithRole($organization, 'reviewer');
        $foreignOwner = $this->userWithRole($otherOrganization, 'owner');
        $publishedId = $this->createSet($owner, 'Published Controls');
        $this->createSet($owner, 'Conflicting Controls');
        $foreignId = $this->createSet($foreignOwner, 'Foreign Controls');
        $this->actingAs($owner)->postJson('/api/v1/requirement-sets/'.$publishedId.'/publish')->assertOk();

        $this->actingAs($reviewer)->putJson('/api/v1/requirement-sets/'.$publishedId, [
            'name' => 'Conflicting Controls',
        ])->assertForbidden();
        $this->actingAs($owner)->putJson('/api/v1/requirement-sets/'.$foreignId, [
            'name' => 'Conflicting Controls',
        ])->assertNotFound();
    }

    public function test_analyst_can_edit_a_draft_and_replace_its_requirements(): void
    {
        $organization = $this->organization('Northwind');
        $analyst = $this->userWithRole($organization, 'analyst');
        $setId = $this->createSet($analyst);

        $this->actingAs($analyst)->putJson('/api/v1/requirement-sets/'.$setId, [
            'name' => 'Updated Security',
            'requirements' => [$this->requirement('SEC-002', 'Encryption', 2)],
        ])->assertOk()
            ->assertJsonPath('data.name', 'Updated Security')
            ->assertJsonCount(1, 'data.requirements')
            ->assertJsonPath('data.requirements.0.code', 'SEC-002');

        $set = DB::table('requirement_sets')->where('public_id', $setId)->first();
        $this->assertDatabaseMissing('requirements', [
            'requirement_set_id' => $set->id,
            'code' => 'SEC-001',
        ]);
    }

    public function test_new_version_clones_published_set_and_requirements_without_mutating_source(): void
    {
        $organization = $this->organization('Northwind');
        $owner = $this->userWithRole($organization, 'owner');
        $setId = $this->createSet($owner);
        $this->actingAs($owner)->postJson('/api/v1/requirement-sets/'.$setId.'/publish')->assertOk();

        $response = $this->actingAs($owner)
            ->postJson('/api/v1/requirement-sets/'.$setId.'/versions')
            ->assertCreated()
            ->assertJsonPath('data.version', 2)
            ->assertJsonPath('data.status', 'draft')
            ->assertJsonPath('data.requirements.0.code', 'SEC-001');

        $source = DB::table('requirement_sets')->where('public_id', $setId)->first();
        $clone = DB::table('requirement_sets')->where('public_id', $response->json('data.id'))->first();
        $this->assertSame($source->id, $clone->parent_id);
        $this->assertSame($source->organization_id, $clone->organization_id);
        $this->assertSame(1, DB::table('requirements')->where('requirement_set_id', $source->id)->count());
        $this->assertSame(1, DB::table('requirements')->where('requirement_set_id', $clone->id)->count());
    }

    public function test_descendant_versions_anchor_to_root_and_v1_cannot_branch_after_v2_is_renamed(): void
    {
        $organization = $this->organization('Northwind');
        $owner = $this->userWithRole($organization, 'owner');
        $v1Id = $this->createSet($owner);
        $this->actingAs($owner)->postJson('/api/v1/requirement-sets/'.$v1Id.'/publish')->assertOk();
        $v2Id = $this->actingAs($owner)
            ->postJson('/api/v1/requirement-sets/'.$v1Id.'/versions')
            ->assertCreated()
            ->json('data.id');

        $this->actingAs($owner)->putJson('/api/v1/requirement-sets/'.$v2Id, [
            'name' => 'Renamed Security',
        ])->assertOk();
        $this->actingAs($owner)
            ->postJson('/api/v1/requirement-sets/'.$v1Id.'/versions')
            ->assertStatus(409);

        $this->actingAs($owner)->postJson('/api/v1/requirement-sets/'.$v2Id.'/publish')->assertOk();
        $v3Id = $this->actingAs($owner)
            ->postJson('/api/v1/requirement-sets/'.$v2Id.'/versions')
            ->assertCreated()
            ->assertJsonPath('data.version', 3)
            ->json('data.id');

        $v1 = DB::table('requirement_sets')->where('public_id', $v1Id)->first();
        $v2 = DB::table('requirement_sets')->where('public_id', $v2Id)->first();
        $v3 = DB::table('requirement_sets')->where('public_id', $v3Id)->first();
        $this->assertSame($v1->id, $v2->parent_id);
        $this->assertSame($v1->id, $v3->parent_id);
    }

    public function test_soft_deleted_version_reserves_sequence_and_next_draft_can_be_published(): void
    {
        $organization = $this->organization('Northwind');
        $owner = $this->userWithRole($organization, 'owner');
        $v1Id = $this->createSet($owner);
        $this->actingAs($owner)->postJson('/api/v1/requirement-sets/'.$v1Id.'/publish')->assertOk();
        $v2Id = $this->actingAs($owner)
            ->postJson('/api/v1/requirement-sets/'.$v1Id.'/versions')
            ->assertCreated()
            ->json('data.id');
        $this->actingAs($owner)->deleteJson('/api/v1/requirement-sets/'.$v2Id)->assertNoContent();

        $this->actingAs($owner)
            ->postJson('/api/v1/requirement-sets/'.$v1Id.'/versions')
            ->assertCreated()
            ->assertJsonPath('data.version', 3);
        $v3Id = RequirementSet::where('version', 3)->firstOrFail()->public_id;
        $this->actingAs($owner)->postJson('/api/v1/requirement-sets/'.$v3Id.'/publish')->assertOk();
        $this->actingAs($owner)->postJson('/api/v1/requirement-sets/'.$v1Id.'/versions')->assertStatus(409);
        $this->actingAs($owner)->postJson('/api/v1/requirement-sets/'.$v2Id.'/versions')->assertNotFound();
        $this->actingAs($owner)->postJson('/api/v1/requirement-sets/'.$v3Id.'/versions')
            ->assertCreated()->assertJsonPath('data.version', 4);
    }

    public function test_create_and_edit_reject_weights_that_are_not_positive_at_storage_precision(): void
    {
        $owner = $this->userWithRole($this->organization('Northwind'), 'owner');
        $id = $this->createSet($owner);
        foreach ([0, -1, 0.0001] as $weight) {
            $requirements = [array_replace($this->requirement('R1', 'Criterion', 0), ['weight' => $weight])];
            $this->actingAs($owner)->postJson('/api/v1/requirement-sets', ['name' => 'Invalid', 'requirements' => $requirements])
                ->assertUnprocessable()->assertJsonValidationErrors('requirements.0.weight');
            $this->actingAs($owner)->putJson('/api/v1/requirement-sets/'.$id, ['requirements' => $requirements])
                ->assertUnprocessable()->assertJsonValidationErrors('requirements.0.weight');
        }
        $this->assertSame('1.500', RequirementSet::where('public_id', $id)->firstOrFail()->requirements()->firstOrFail()->weight);
    }

    public function test_publish_revalidates_legacy_weight_before_freezing_draft(): void
    {
        $owner = $this->userWithRole($this->organization('Northwind'), 'owner');
        $id = $this->createSet($owner);
        $set = RequirementSet::where('public_id', $id)->firstOrFail();
        $set->requirements()->update(['weight' => 0]);
        $this->actingAs($owner)->postJson('/api/v1/requirement-sets/'.$id.'/publish')
            ->assertUnprocessable()->assertJsonValidationErrors('requirements.0.weight');
        $this->assertSame('draft', $set->fresh()->status);
        $this->assertNull($set->fresh()->published_at);
        $this->actingAs($owner)->putJson('/api/v1/requirement-sets/'.$id, [
            'requirements' => [array_replace($this->requirement('R1', 'Criterion', 0), ['weight' => 0.001])],
        ])->assertOk()->assertJsonPath('data.requirements.0.weight', '0.001');
        $this->actingAs($owner)->postJson('/api/v1/requirement-sets/'.$id.'/publish')->assertOk();
    }

    public function test_duplicate_name_and_version_are_rejected_on_create_and_update(): void
    {
        $organization = $this->organization('Northwind');
        $owner = $this->userWithRole($organization, 'owner');
        $firstId = $this->createSet($owner);
        $secondId = $this->createSet($owner, 'Privacy Controls');

        $this->actingAs($owner)->postJson('/api/v1/requirement-sets', [
            'name' => 'Vendor Security',
            'requirements' => [$this->requirement('DUP-001', 'Duplicate', 1)],
        ])->assertUnprocessable()->assertJsonValidationErrors('name');

        $this->actingAs($owner)->putJson('/api/v1/requirement-sets/'.$secondId, [
            'name' => 'Vendor Security',
        ])->assertUnprocessable()->assertJsonValidationErrors('name');
        $this->assertDatabaseHas('requirement_sets', [
            'public_id' => $firstId,
            'name' => 'Vendor Security',
        ]);
        $this->assertDatabaseHas('requirement_sets', [
            'public_id' => $secondId,
            'name' => 'Privacy Controls',
        ]);
    }

    public function test_requirement_set_update_may_keep_its_own_name_and_version(): void
    {
        $organization = $this->organization('Northwind');
        $owner = $this->userWithRole($organization, 'owner');
        $setId = $this->createSet($owner);

        $this->actingAs($owner)->putJson('/api/v1/requirement-sets/'.$setId, [
            'name' => 'Vendor Security',
            'requirements' => [$this->requirement('SEC-002', 'Encryption', 2)],
        ])->assertOk()
            ->assertJsonPath('data.name', 'Vendor Security')
            ->assertJsonPath('data.requirements.0.code', 'SEC-002');
    }

    public function test_requirement_name_unique_constraint_race_returns_validation_error_and_rolls_back(): void
    {
        $organization = $this->organization('Northwind');
        $owner = $this->userWithRole($organization, 'owner');
        RequirementSet::query()->count();
        $insertedByRace = false;

        RequirementSet::creating(function (RequirementSet $set) use (&$insertedByRace): void {
            if ($insertedByRace || $set->name !== 'Racing Controls' || $set->version !== 1) {
                return;
            }

            $insertedByRace = true;
            RequirementSet::query()->getQuery()->insert([
                'public_id' => Str::uuid(),
                'organization_id' => $set->organization_id,
                'parent_id' => null,
                'name' => $set->name,
                'version' => 1,
                'status' => 'draft',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        });

        $this->actingAs($owner)->postJson('/api/v1/requirement-sets', [
            'name' => 'Racing Controls',
            'requirements' => [$this->requirement('RACE-001', 'Race', 1)],
        ])->assertUnprocessable()->assertJsonValidationErrors('name');

        $this->assertDatabaseMissing('requirement_sets', ['name' => 'Racing Controls']);
    }

    public function test_requirement_version_unique_constraint_race_returns_controlled_conflict(): void
    {
        $organization = $this->organization('Northwind');
        $owner = $this->userWithRole($organization, 'owner');
        $v1Id = $this->createSet($owner, 'Race Controls');
        $this->actingAs($owner)->postJson('/api/v1/requirement-sets/'.$v1Id.'/publish')->assertOk();
        RequirementSet::query()->count();
        $insertedByRace = false;

        RequirementSet::creating(function (RequirementSet $set) use (&$insertedByRace): void {
            if ($insertedByRace || $set->name !== 'Race Controls' || $set->version !== 2) {
                return;
            }

            $insertedByRace = true;
            RequirementSet::query()->getQuery()->insert([
                'public_id' => Str::uuid(),
                'organization_id' => $set->organization_id,
                'parent_id' => $set->parent_id,
                'name' => 'Concurrent renamed version',
                'version' => $set->version,
                'status' => 'draft',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        });

        $this->actingAs($owner)
            ->postJson('/api/v1/requirement-sets/'.$v1Id.'/versions')
            ->assertStatus(409);

        $this->assertSame(0, DB::table('requirement_sets')->where('version', 2)->count());
    }

    public function test_requirement_set_uuids_are_resolved_only_in_current_organization(): void
    {
        $organization = $this->organization('Northwind');
        $otherOrganization = $this->organization('Globex');
        $owner = $this->userWithRole($organization, 'owner');
        $foreignOwner = $this->userWithRole($otherOrganization, 'owner');
        $foreignSetId = $this->createSet($foreignOwner);

        $this->actingAs($owner)->getJson('/api/v1/requirement-sets/'.$foreignSetId)->assertNotFound();
        $this->actingAs($owner)->putJson('/api/v1/requirement-sets/'.$foreignSetId, [
            'name' => 'Stolen set',
        ])->assertNotFound();
        $this->actingAs($owner)->deleteJson('/api/v1/requirement-sets/'.$foreignSetId)->assertNotFound();
        $this->actingAs($owner)->postJson('/api/v1/requirement-sets/'.$foreignSetId.'/publish')->assertNotFound();
        $this->actingAs($owner)->postJson('/api/v1/requirement-sets/'.$foreignSetId.'/versions')->assertNotFound();
    }

    public function test_analyst_can_delete_a_draft_but_published_set_is_immutable(): void
    {
        $organization = $this->organization('Northwind');
        $owner = $this->userWithRole($organization, 'owner');
        $analyst = $this->userWithRole($organization, 'analyst');
        $draftId = $this->createSet($analyst);

        $this->actingAs($analyst)
            ->deleteJson('/api/v1/requirement-sets/'.$draftId)
            ->assertNoContent();
        $this->assertSoftDeleted('requirement_sets', ['public_id' => $draftId]);

        $publishedId = $this->createSet($owner, 'Published Security');
        $this->actingAs($owner)->postJson('/api/v1/requirement-sets/'.$publishedId.'/publish')->assertOk();
        $this->actingAs($owner)
            ->deleteJson('/api/v1/requirement-sets/'.$publishedId)
            ->assertStatus(409);
        $this->assertDatabaseHas('requirement_sets', [
            'public_id' => $publishedId,
            'deleted_at' => null,
            'status' => 'published',
        ]);
    }

    public function test_name_is_reserved_across_versions_and_lineages_but_case_remains_significant(): void
    {
        $owner = $this->userWithRole($this->organization('Names'), 'owner');
        $a = $this->createSet($owner, 'A');
        $b = $this->createSet($owner, 'B');
        $this->postJson('/api/v1/requirement-sets/'.$a.'/publish')->assertOk();
        $this->postJson('/api/v1/requirement-sets/'.$b.'/publish')->assertOk();
        $b2 = $this->postJson('/api/v1/requirement-sets/'.$b.'/versions')->assertCreated()->json('data.id');
        $this->putJson('/api/v1/requirement-sets/'.$b2, ['name' => ' A '])
            ->assertUnprocessable()->assertJsonValidationErrors('name');
        $this->putJson('/api/v1/requirement-sets/'.$b2, ['name' => 'B'])->assertOk();
        $this->putJson('/api/v1/requirement-sets/'.$b2, ['name' => 'a'])->assertOk();
        $this->postJson('/api/v1/requirement-sets/'.$b2.'/publish')->assertOk();
        $this->postJson('/api/v1/requirement-sets/'.$a.'/versions')->assertCreated()->assertJsonPath('data.name', 'A');
        $this->postJson('/api/v1/requirement-sets', ['name' => 'a', 'requirements' => [$this->requirement('R', 'R', 1)]])
            ->assertUnprocessable()->assertJsonValidationErrors('name');
    }

    public function test_deleted_other_lineage_name_stays_reserved_and_foreign_tenant_does_not_reserve_it(): void
    {
        $owner = $this->userWithRole($this->organization('Names'), 'owner');
        $root = $this->createSet($owner, 'First');
        $this->postJson('/api/v1/requirement-sets/'.$root.'/publish')->assertOk();
        $v2 = $this->postJson('/api/v1/requirement-sets/'.$root.'/versions')->assertCreated()->json('data.id');
        $this->putJson('/api/v1/requirement-sets/'.$v2, ['name' => 'Reserved'])->assertOk();
        $this->deleteJson('/api/v1/requirement-sets/'.$v2)->assertNoContent();
        $other = $this->createSet($owner, 'Other');
        $this->putJson('/api/v1/requirement-sets/'.$other, ['name' => 'Reserved'])
            ->assertUnprocessable()->assertJsonValidationErrors('name');
        $this->postJson('/api/v1/requirement-sets', ['name' => 'Reserved', 'requirements' => [$this->requirement('R', 'R', 1)]])
            ->assertUnprocessable()->assertJsonValidationErrors('name');
        $this->createSet($this->userWithRole($this->organization('Foreign names'), 'owner'), 'Reserved');
    }

    public function test_legacy_cross_lineage_name_collision_cannot_publish_or_version_and_draft_can_be_repaired(): void
    {
        $owner = $this->userWithRole($this->organization('Legacy names'), 'owner');
        $a = $this->createSet($owner, 'A');
        $b = $this->createSet($owner, 'B');
        $this->postJson('/api/v1/requirement-sets/'.$a.'/publish')->assertOk();
        $this->postJson('/api/v1/requirement-sets/'.$b.'/publish')->assertOk();
        $b2 = $this->postJson('/api/v1/requirement-sets/'.$b.'/versions')->assertCreated()->json('data.id');
        DB::table('requirement_sets')->where('public_id', $b2)->update(['name' => 'A']);
        $this->postJson('/api/v1/requirement-sets/'.$b2.'/publish')->assertUnprocessable()->assertJsonValidationErrors('name');
        $this->postJson('/api/v1/requirement-sets/'.$a.'/versions')->assertUnprocessable()->assertJsonValidationErrors('name');
        $this->assertDatabaseHas('requirement_sets', ['public_id' => $b2, 'status' => 'draft']);
        $this->putJson('/api/v1/requirement-sets/'.$b2, ['name' => 'Repaired'])->assertOk();
        $this->postJson('/api/v1/requirement-sets/'.$b2.'/publish')->assertOk();
        $this->postJson('/api/v1/requirement-sets/'.$a.'/versions')->assertCreated();
    }

    private function createSet(User $user, string $name = 'Vendor Security'): string
    {
        return $this->actingAs($user)->postJson('/api/v1/requirement-sets', [
            'name' => $name,
            'requirements' => [$this->requirement('SEC-001', 'Access control', 1)],
        ])->assertCreated()->json('data.id');
    }

    /** @return array<string, mixed> */
    private function requirement(string $code, string $title, int $position): array
    {
        return [
            'code' => $code,
            'title' => $title,
            'category' => 'security',
            'weight' => 1.5,
            'position' => $position,
            'evaluation_text' => 'The supplier must document access control.',
            'is_required' => true,
        ];
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
}
