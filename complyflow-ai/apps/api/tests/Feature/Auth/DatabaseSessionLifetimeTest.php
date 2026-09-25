<?php

namespace Tests\Feature\Auth;

use App\Models\DemoSession;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Symfony\Component\HttpFoundation\Cookie;
use Tests\TestCase;

class DatabaseSessionLifetimeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        $this->withCredentials();
        config(['session.driver' => 'database', 'session.lottery' => [100, 100]]);
    }

    public function test_browser_receives_a_persistent_session_cookie_for_at_least_24_hours(): void
    {
        $startedAt = now()->startOfSecond();
        $this->travelTo($startedAt);
        $response = $this->freshRequest('POST', '/api/v1/demo-sessions')->assertCreated();
        $cookie = $response->getCookie(config('session.cookie'), false);

        $this->assertGreaterThanOrEqual($startedAt->timestamp + 86400, $cookie->getExpiresTime());
        $this->assertGreaterThanOrEqual(86395, $cookie->getMaxAge());
        $this->assertStringContainsString('Max-Age=', (string) $cookie);
        $this->assertTrue($cookie->isHttpOnly());
        $this->assertSame('lax', $cookie->getSameSite());
        $this->assertDatabaseHas('sessions', [
            'id' => $response->getCookie(config('session.cookie'))->getValue(),
            'last_activity' => $startedAt->timestamp,
        ]);
    }

    public function test_a_new_request_restores_an_idle_demo_after_three_hours_and_gc_preserves_it(): void
    {
        $startedAt = now()->startOfSecond();
        $this->travelTo($startedAt);
        $created = $this->freshRequest('POST', '/api/v1/demo-sessions')->assertCreated();
        $cookie = $created->getCookie(config('session.cookie'), false);
        $sessionId = $created->getCookie(config('session.cookie'))->getValue();
        DB::table('sessions')->insert([
            'id' => 'expired-unrelated-session', 'payload' => base64_encode(serialize([])),
            'last_activity' => $startedAt->timestamp - 86401,
        ]);

        $this->travelTo($startedAt->copy()->addHours(3));
        $this->freshRequest('GET', '/api/v1/me', $cookie)->assertOk()
            ->assertJsonPath('data.demo.id', $created->json('data.id'))
            ->assertJsonPath('data.demo.expires_at', $created->json('data.expires_at'));
        $this->assertDatabaseHas('sessions', ['id' => $sessionId, 'last_activity' => now()->timestamp]);
        $this->assertDatabaseMissing('sessions', ['id' => 'expired-unrelated-session']);
    }

    public function test_activity_never_extends_demo_expiry_and_expired_identity_cannot_return_after_purge(): void
    {
        $startedAt = now()->startOfSecond();
        $this->travelTo($startedAt);
        $created = $this->freshRequest('POST', '/api/v1/demo-sessions')->assertCreated();
        $demo = DemoSession::where('public_id', $created->json('data.id'))->firstOrFail();
        $cookie = $created->getCookie(config('session.cookie'), false);

        $this->travelTo($startedAt->copy()->addHours(24)->subSecond());
        $active = $this->freshRequest('GET', '/api/v1/me', $cookie)->assertOk()
            ->assertJsonPath('data.demo.expires_at', $created->json('data.expires_at'));
        $this->assertTrue($demo->refresh()->expires_at->equalTo($startedAt->copy()->addHours(24)));
        $this->artisan('demo:purge-expired')->assertSuccessful();
        $this->assertDatabaseHas('demo_sessions', ['id' => $demo->id]);
        $renewedCookie = $active->getCookie(config('session.cookie'), false);
        $this->assertGreaterThan($demo->expires_at->timestamp, $renewedCookie->getExpiresTime());

        $this->travelTo($startedAt->copy()->addHours(24));
        $this->freshRequest('GET', '/api/v1/me', $renewedCookie)->assertUnauthorized()
            ->assertJsonPath('message', 'Demo session expired.');
        $this->assertDatabaseMissing('sessions', ['id' => $active->getCookie(config('session.cookie'))->getValue()]);
        $this->assertDatabaseHas('demo_sessions', ['id' => $demo->id]);

        $this->travelTo($startedAt->copy()->addHours(24)->addSecond());
        $guest = $this->freshRequest('GET', '/api/v1/me', $renewedCookie)->assertUnauthorized();
        $this->assertDatabaseHas('sessions', [
            'id' => $guest->getCookie(config('session.cookie'))->getValue(),
            'user_id' => null,
        ]);
        $this->artisan('demo:purge-expired')->assertSuccessful();
        $this->artisan('demo:purge-expired')->assertSuccessful();
        $this->assertDatabaseMissing('demo_sessions', ['id' => $demo->id]);
        $this->assertDatabaseMissing('organizations', ['id' => $demo->organization_id]);
        $this->assertDatabaseMissing('users', ['id' => $demo->user_id]);
        $this->freshRequest('GET', '/api/v1/me', $renewedCookie)->assertUnauthorized();
    }

    public function test_regular_sessions_slide_with_activity_but_replayed_cookies_fail_after_24_idle_hours(): void
    {
        $startedAt = now()->startOfSecond();
        $this->travelTo($startedAt);
        $created = $this->freshRequest('POST', '/api/v1/register', data: [
            'name' => 'Session Owner', 'organization_name' => 'Session Organization',
            'email' => 'session-owner@example.invalid', 'password' => 'a-valid-long-password',
            'password_confirmation' => 'a-valid-long-password',
        ])->assertCreated();
        $cookie = $created->getCookie(config('session.cookie'), false);

        foreach ([3, 26] as $hours) {
            $this->travelTo($startedAt->copy()->addHours($hours));
            $response = $this->freshRequest('GET', '/api/v1/me', $cookie)->assertOk()
                ->assertJsonPath('data.user.email', 'session-owner@example.invalid')
                ->assertJsonPath('data.demo', null);
            $cookie = $response->getCookie(config('session.cookie'), false);
            $this->assertSame(now()->timestamp + 86400, $cookie->getExpiresTime());
        }

        $this->travelTo($startedAt->copy()->addHours(50)->addSecond());
        // Force replay even though a real browser would have discarded this expired cookie.
        $this->freshRequest('GET', '/api/v1/me', $cookie, replayExpired: true)->assertUnauthorized();
    }

    /** A new store and both new web/Sanctum guards: no in-memory login survives a request. */
    private function freshRequest(string $method, string $uri, ?Cookie $cookie = null, array $data = [], bool $replayExpired = false): TestResponse
    {
        $this->app['auth']->forgetGuards();
        $this->app->forgetInstance('auth.driver');
        $this->app['auth']->shouldUse('web');
        $this->app['session']->forgetDrivers();
        $this->app->forgetInstance('session.store');
        $this->unencryptedCookies = [];
        if ($cookie !== null && ($replayExpired || $cookie->getExpiresTime() > now()->timestamp)) {
            $this->withUnencryptedCookie($cookie->getName(), $cookie->getValue());
        }

        return $this->json($method, $uri, $data);
    }
}
