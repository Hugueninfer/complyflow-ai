<?php

namespace Tests\Feature\Requirements;

use App\Models\Organization;
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
