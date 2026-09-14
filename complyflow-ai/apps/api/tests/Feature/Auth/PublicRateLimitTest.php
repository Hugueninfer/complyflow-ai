<?php

namespace Tests\Feature\Auth;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PublicRateLimitTest extends TestCase
{
    use RefreshDatabase;

    public function test_repeated_login_attempts_are_limited_before_password_work(): void
    {
        for ($attempt = 0; $attempt < 5; $attempt++) {
            $this->postJson('/api/v1/login', [])->assertUnprocessable();
        }
        $this->postJson('/api/v1/login', [])->assertTooManyRequests();
    }

    public function test_repeated_registration_attempts_are_limited(): void
    {
        for ($attempt = 0; $attempt < 10; $attempt++) {
            $this->postJson('/api/v1/register', [])->assertUnprocessable();
        }
        $this->postJson('/api/v1/register', [])->assertTooManyRequests();
    }

    public function test_two_identities_behind_the_same_proxy_do_not_share_login_or_registration_buckets(): void
    {
        $this->withServerVariables(['REMOTE_ADDR' => '10.0.0.8']);
        foreach (['login' => 5, 'register' => 10] as $route => $limit) {
            for ($attempt = 0; $attempt < $limit; $attempt++) {
                $this->postJson('/api/v1/'.$route, ['email' => 'first@example.invalid'])->assertUnprocessable();
            }
            $this->postJson('/api/v1/'.$route, ['email' => 'first@example.invalid'])->assertTooManyRequests();
            $this->app['session']->invalidate();
            $this->postJson('/api/v1/'.$route, ['email' => 'second@example.invalid'])->assertUnprocessable();
        }
    }

    public function test_normalized_identity_stays_limited_across_sessions_and_forged_forwarding_headers(): void
    {
        for ($attempt = 0; $attempt < 5; $attempt++) {
            $this->withServerVariables(['REMOTE_ADDR' => '10.0.0.8'])
                ->withHeaders(['X-Forwarded-For' => '192.0.2.'.$attempt, 'Origin' => 'https://untrusted-'.$attempt.'.invalid'])
                ->postJson('/api/v1/login', ['email' => 'person@example.invalid'])->assertUnprocessable();
        }
        $this->app['session']->invalidate();
        $this->withServerVariables(['REMOTE_ADDR' => '10.0.0.9'])
            ->withHeaders(['X-Forwarded-For' => '203.0.113.99', 'X-Forwarded-Host' => 'evil.invalid'])
            ->postJson('/api/v1/login', ['email' => ' PERSON@EXAMPLE.INVALID '])->assertTooManyRequests();
    }

    public function test_demo_budget_follows_server_session_across_login_regeneration_not_new_user_or_proxy_ip(): void
    {
        $this->seed();
        $this->withServerVariables(['REMOTE_ADDR' => '10.0.0.8']);
        for ($attempt = 0; $attempt < 10; $attempt++) {
            $this->withHeaders(['X-Forwarded-For' => '192.0.2.'.$attempt])
                ->postJson('/api/v1/demo-sessions', ['public_rate_limit_nonce' => 'client-chosen-'.$attempt])->assertCreated();
        }
        $this->postJson('/api/v1/demo-sessions', [])->assertTooManyRequests();
        // Independent server session behind exactly the same load balancer address.
        $this->app['session']->invalidate();
        $this->app['auth']->forgetGuards();
        $this->postJson('/api/v1/demo-sessions', [])->assertCreated();
    }

    public function test_cycling_identities_in_one_session_does_not_bypass_login_budget(): void
    {
        for ($attempt = 0; $attempt < 20; $attempt++) {
            $this->postJson('/api/v1/login', ['email' => 'person-'.$attempt.'@example.invalid'])->assertUnprocessable();
        }
        $this->postJson('/api/v1/login', ['email' => 'another@example.invalid'])->assertTooManyRequests();
    }

    public function test_demo_aggregate_budget_bounds_cookie_cycling_without_a_single_session_locking_out_everyone(): void
    {
        $this->seed();
        for ($attempt = 0; $attempt < 60; $attempt++) {
            $this->app['session']->invalidate();
            $this->app['auth']->forgetGuards();
            $this->postJson('/api/v1/demo-sessions', [])->assertCreated();
        }
        $this->app['session']->invalidate();
        $this->app['auth']->forgetGuards();
        $this->postJson('/api/v1/demo-sessions', [])->assertTooManyRequests();
    }
}
