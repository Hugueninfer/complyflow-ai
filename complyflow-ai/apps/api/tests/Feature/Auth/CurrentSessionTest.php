<?php

namespace Tests\Feature\Auth;

use App\Models\DemoSession;
use App\Models\Organization;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CurrentSessionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
    }

    public function test_anonymous_browser_cannot_read_a_session(): void
    {
        $this->getJson('/api/v1/me')->assertUnauthorized();
    }

    public function test_cookie_login_restores_only_the_resolved_tenant_and_role(): void
    {
        $user = User::factory()->create(['password' => 'correct horse battery staple']);
        $own = Organization::query()->create(['name' => 'Own organization', 'slug' => 'own']);
        $other = Organization::query()->create(['name' => 'Other organization', 'slug' => 'other']);
        $own->users()->attach($user, ['role_id' => Role::where('name', 'analyst')->value('id')]);
        $this->postJson('/api/v1/login', ['email' => $user->email, 'password' => 'correct horse battery staple'])->assertOk();

        $response = $this->getJson('/api/v1/me?organization_id='.$other->public_id, ['X-Organization-Id' => $other->public_id])
            ->assertOk()
            ->assertJsonPath('data.user.id', $user->public_id)
            ->assertJsonPath('data.organization.id', $own->public_id)
            ->assertJsonPath('data.role', 'analyst')
            ->assertJsonPath('data.demo', null);
        $this->assertContains('supplier.view', $response->json('data.permissions'));
        $this->assertNotContains('finding.review', $response->json('data.permissions'));
        $this->assertArrayNotHasKey('password', $response->json('data.user'));
        $this->postJson('/api/v1/logout')->assertNoContent();
        $this->assertGuest('web');
        // Each real HTTP request gets a fresh Sanctum request guard.
        $this->app['auth']->forgetGuards();
        $this->getJson('/api/v1/me')->assertUnauthorized();
    }

    public function test_demo_session_metadata_is_restored_and_expiry_is_enforced(): void
    {
        $demo = $this->postJson('/api/v1/demo-sessions')->assertCreated()->json('data');
        $this->getJson('/api/v1/me')->assertOk()
            ->assertJsonPath('data.organization.id', $demo['organization_id'])
            ->assertJsonPath('data.demo.id', $demo['id'])
            ->assertJsonPath('data.demo.expires_at', $demo['expires_at'])
            ->assertJsonPath('data.demo.quotas', $demo['quotas']);
        $session = DemoSession::where('public_id', $demo['id'])->firstOrFail();
        $this->travelTo($session->expires_at);
        $this->getJson('/api/v1/me')->assertUnauthorized();
        $this->assertGuest('web');
    }
}
