<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class HealthTest extends TestCase
{
    public function test_health_is_ready_only_when_all_dependencies_are_available(): void
    {
        Http::fake(['*/health' => Http::response(['status' => 'ready'])]);
        $response = $this->getJson('/api/health');

        $response->assertOk();
        $response->assertExactJson(['status' => 'ready', 'checks' => ['laravel' => 'ready', 'database' => 'ready', 'processor' => 'ready']]);
    }

    public function test_processor_failure_is_sanitized_and_not_ready(): void
    {
        Http::fake(['*/health' => Http::response(['secret' => 'never-public'], 500)]);
        $this->getJson('/api/health')->assertStatus(503)
            ->assertJsonPath('checks.processor', 'unavailable')->assertDontSee('never-public');
    }

    public function test_database_failure_is_sanitized_and_not_ready(): void
    {
        Http::fake(['*/health' => Http::response(['status' => 'ready'])]);
        DB::shouldReceive('select')->once()->andThrow(new \RuntimeException('postgres://secret'));
        $this->getJson('/api/health')->assertStatus(503)
            ->assertJsonPath('checks.database', 'unavailable')->assertDontSee('postgres://secret');
    }
}
