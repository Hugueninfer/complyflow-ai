<?php

namespace Tests\Feature\Auth;

use App\Models\Organization;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use RuntimeException;
use Tests\TestCase;

class AuthenticationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed();
    }

    public function test_registration_creates_an_owner_tenant_and_ignores_client_tenant_ids(): void
    {
        $untrustedOrganization = Organization::query()->create([
            'name' => 'Untrusted tenant',
            'slug' => 'untrusted-tenant',
        ]);

        $response = $this->postJson('/api/v1/register', [
            'name' => 'Ana Reviewer',
            'email' => 'ana@example.com',
            'password' => 'correct horse battery staple',
            'password_confirmation' => 'correct horse battery staple',
            'organization_name' => 'Ana Compliance',
            'organization_id' => $untrustedOrganization->id,
        ])->assertCreated();

        $user = User::query()->where('email', 'ana@example.com')->firstOrFail();
        $organization = Organization::query()->where('name', 'Ana Compliance')->firstOrFail();

        $this->assertAuthenticatedAs($user, 'web');
        $this->assertSame($user->public_id, $response->json('data.user.id'));
        $this->assertSame($organization->public_id, $response->json('data.organization.id'));
        $this->assertDatabaseHas('organization_user', [
            'organization_id' => $organization->id,
            'user_id' => $user->id,
            'role_id' => $this->ownerRoleId(),
        ]);
        $this->assertDatabaseMissing('organization_user', [
            'organization_id' => $untrustedOrganization->id,
            'user_id' => $user->id,
        ]);
    }

    public function test_registration_rolls_back_user_when_tenant_creation_fails(): void
    {
        Event::listen('eloquent.creating: '.Organization::class, function (): never {
            throw new RuntimeException('tenant provisioning failed');
        });

        try {
            $this->postJson('/api/v1/register', [
                'name' => 'Ana Reviewer',
                'email' => 'ana@example.com',
                'password' => 'correct horse battery staple',
                'password_confirmation' => 'correct horse battery staple',
                'organization_name' => 'Ana Compliance',
            ])->assertInternalServerError();
        } finally {
            Event::forget('eloquent.creating: '.Organization::class);
        }

        $this->assertDatabaseMissing('users', ['email' => 'ana@example.com']);
        $this->assertDatabaseMissing('organizations', ['name' => 'Ana Compliance']);
    }

    public function test_login_regenerates_the_session_and_sets_a_secure_browser_cookie(): void
    {
        $user = User::factory()->create([
            'email' => 'ana@example.com',
            'password' => 'correct horse battery staple',
        ]);
        $this->withSession(['pre_login_marker' => true]);
        $oldSessionId = $this->app['session']->getId();

        $response = $this->postJson('/api/v1/login', [
            'email' => 'ana@example.com',
            'password' => 'correct horse battery staple',
        ])->assertOk();

        $this->assertAuthenticatedAs($user, 'web');
        $this->assertNotSame($oldSessionId, $this->app['session']->getId());
        $this->assertStringContainsString('httponly', strtolower(implode(';', $response->headers->all('set-cookie'))));
        $this->assertStringContainsString('samesite=lax', strtolower(implode(';', $response->headers->all('set-cookie'))));
    }

    public function test_login_rejects_invalid_credentials(): void
    {
        User::factory()->create([
            'email' => 'ana@example.com',
            'password' => 'correct horse battery staple',
        ]);

        $this->postJson('/api/v1/login', [
            'email' => 'ana@example.com',
            'password' => 'wrong password',
        ])->assertUnprocessable()
            ->assertJsonValidationErrors('email');

        $this->assertGuest('web');
    }

    public function test_logout_invalidates_the_authenticated_session(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);
        $this->withSession(['private' => 'value']);
        $oldSessionId = $this->app['session']->getId();

        $this->postJson('/api/v1/logout')->assertNoContent();

        $this->assertGuest('web');
        $this->assertFalse($this->app['session']->has('private'));
        $this->assertNotSame($oldSessionId, $this->app['session']->getId());
    }

    private function ownerRoleId(): int
    {
        return (int) Role::query()->where('name', 'owner')->value('id');
    }
}
