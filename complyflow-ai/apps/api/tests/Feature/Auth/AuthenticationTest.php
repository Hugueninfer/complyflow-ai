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

    public function test_registration_normalizes_email_before_uniqueness_validation(): void
    {
        User::factory()->create(['email' => 'ana@example.com']);
        $this->postJson('/api/v1/register', $this->registration(' ANA@EXAMPLE.COM '))
            ->assertUnprocessable()->assertJsonValidationErrors('email');
        $this->assertDatabaseMissing('organizations', ['name' => 'Normalized tenant']);
    }

    public function test_registration_stores_a_trimmed_lowercase_email_and_requires_confirmed_long_password(): void
    {
        $this->postJson('/api/v1/register', $this->registration(' NEW@EXAMPLE.COM '))
            ->assertCreated()->assertJsonPath('data.user.email', 'new@example.com');
        $this->postJson('/api/v1/logout')->assertNoContent();
        $this->postJson('/api/v1/register', [...$this->registration('short@example.com'), 'password' => 'short', 'password_confirmation' => 'short'])
            ->assertUnprocessable()->assertJsonValidationErrors('password');
        $this->postJson('/api/v1/register', [...$this->registration('mismatch@example.com'), 'password_confirmation' => 'different password'])
            ->assertUnprocessable()->assertJsonValidationErrors('password');
    }

    public function test_login_accepts_email_case_and_whitespace_without_bypassing_the_password(): void
    {
        $user = User::factory()->create(['email' => 'ana@example.com', 'password' => 'correct horse battery staple']);
        $this->postJson('/api/v1/login', ['email' => ' ANA@EXAMPLE.COM ', 'password' => 'wrong password'])
            ->assertUnprocessable();
        $this->assertGuest('web');
        $this->postJson('/api/v1/login', ['email' => ' ANA@EXAMPLE.COM ', 'password' => 'correct horse battery staple'])->assertOk();
        $this->assertAuthenticatedAs($user, 'web');
    }

    public function test_legacy_uppercase_identity_can_login_and_cannot_be_registered_again(): void
    {
        $user = User::factory()->create(['email' => 'LEGACY@EXAMPLE.COM', 'password' => 'correct horse battery staple']);
        $this->postJson('/api/v1/register', $this->registration('legacy@example.com'))
            ->assertUnprocessable()->assertJsonValidationErrors('email');
        $this->postJson('/api/v1/login', ['email' => ' legacy@example.com ', 'password' => 'correct horse battery staple'])->assertOk();
        $this->assertAuthenticatedAs($user, 'web');
    }

    private function registration(string $email): array
    {
        return ['name' => 'Owner', 'organization_name' => 'Normalized tenant', 'email' => $email,
            'password' => 'correct horse battery staple', 'password_confirmation' => 'correct horse battery staple'];
    }

    public function test_registration_rejects_a_non_string_email_without_a_server_error(): void
    {
        $this->postJson('/api/v1/register', [...$this->registration('owner@example.invalid'), 'email' => ['invalid']])
            ->assertUnprocessable()->assertJsonValidationErrors('email');
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
