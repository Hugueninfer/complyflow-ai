<?php

namespace Tests\Feature\Database;

use App\Providers\AppServiceProvider;
use Illuminate\Cache\RateLimiter;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Monolog\Handler\TestHandler;
use Tests\TestCase;

class ExpiredApplicationCacheTest extends TestCase
{
    use DatabaseMigrations;

    private string $prefix = 'gc-test_%\\-';

    protected function setUp(): void
    {
        parent::setUp();
        $this->assertSame('pgsql', DB::connection()->getDriverName(), 'This regression requires real PostgreSQL.');
        config(['cache.default' => 'database', 'cache.prefix' => $this->prefix]);
        Cache::forgetDriver('database');
        $this->app->instance(RateLimiter::class, new RateLimiter(Cache::store('database')));
        \Illuminate\Support\Facades\RateLimiter::clearResolvedInstance(RateLimiter::class);
        $this->app->getProvider(AppServiceProvider::class)->boot();
        config(['database.connections.gc_concurrent' => config('database.connections.'.DB::getDefaultConnection())]);
    }

    protected function tearDown(): void
    {
        DB::purge('gc_concurrent');
        parent::tearDown();
    }

    public function test_unvisited_expired_throttle_keys_are_collected_but_active_cache_other_prefixes_and_locks_survive(): void
    {
        $limiter = $this->app->make(RateLimiter::class);
        $limiter->hit('abandoned-identity', 1);
        $limiter->hit('still-active-identity', 60);
        Cache::put('active-value', 'keep', 60);
        DB::table('cache')->insert([
            ['key' => 'foreign-cache-old', 'value' => 'foreign', 'expiration' => now()->timestamp - 1],
            ['key' => 'gc-test_anything\\-old', 'value' => 'foreign', 'expiration' => now()->timestamp - 1],
        ]);
        DB::table('cache_locks')->insert(['key' => $this->prefix.'expired-lock', 'owner' => 'owner', 'expiration' => now()->timestamp - 1]);
        $this->travel(2)->seconds();
        // Query directly: consulting the expired keys through Cache would conceal the original bug.
        $this->assertSame(2, DB::table('cache')->where('key', 'like', '%abandoned-identity%')->count());

        $this->postJson('/api/v1/login', ['email' => 'visitor@example.invalid'])->assertUnprocessable();

        $this->assertSame(0, DB::table('cache')->where('key', 'like', '%abandoned-identity%')->count());
        $this->assertSame(1, $limiter->attempts('still-active-identity'));
        $this->assertSame('keep', Cache::get('active-value'));
        $this->assertDatabaseHas('cache', ['key' => 'foreign-cache-old']);
        $this->assertDatabaseHas('cache', ['key' => 'gc-test_anything\\-old']);
        $this->assertDatabaseHas('cache_locks', ['key' => $this->prefix.'expired-lock', 'owner' => 'owner']);
    }

    public function test_cleanup_is_bounded_and_makes_progress_on_every_request_without_coordination_rows(): void
    {
        for ($index = 0; $index < 70; $index++) {
            DB::table('cache')->insert(['key' => $this->prefix.'abandoned-'.$index, 'value' => 'expired', 'expiration' => now()->timestamp - 1]);
        }
        foreach ([38, 6, 0] as $remaining) {
            $this->postJson('/api/v1/login', ['email' => 'visitor@example.invalid'])->assertUnprocessable();
            $this->assertSame($remaining, DB::table('cache')->where('value', 'expired')->count());
        }
        // Only the two login budgets' counter/timer pairs were added, no GC cache key or lock row.
        $this->assertSame(4, DB::table('cache')->count());
        $this->assertSame(0, DB::table('cache_locks')->count());
    }

    public function test_another_collector_does_not_block_login_and_collection_resumes_after_lock_release(): void
    {
        DB::table('cache')->insert(['key' => $this->prefix.'abandoned', 'value' => 'expired', 'expiration' => now()->timestamp - 1]);
        $other = DB::connection('gc_concurrent');
        $other->beginTransaction();
        try {
            $other->select('SELECT pg_advisory_xact_lock(170026, 1802)');
            $started = microtime(true);
            $this->postJson('/api/v1/login', ['email' => 'visitor@example.invalid'])->assertUnprocessable();
            $this->assertLessThan(2, microtime(true) - $started);
            $this->assertDatabaseHas('cache', ['key' => $this->prefix.'abandoned']);
        } finally {
            $other->rollBack();
        }
        $this->postJson('/api/v1/login', ['email' => 'visitor@example.invalid'])->assertUnprocessable();
        $this->assertDatabaseMissing('cache', ['key' => $this->prefix.'abandoned']);
    }

    public function test_concurrent_refresh_is_skipped_and_the_refreshed_entry_is_never_deleted(): void
    {
        foreach (['refreshing', 'abandoned'] as $key) {
            DB::table('cache')->insert(['key' => $this->prefix.$key, 'value' => 'expired', 'expiration' => now()->timestamp - 1]);
        }
        $other = DB::connection('gc_concurrent');
        $other->beginTransaction();
        try {
            $other->table('cache')->where('key', $this->prefix.'refreshing')->update(['expiration' => now()->timestamp + 60]);
            $started = microtime(true);
            $this->postJson('/api/v1/login', ['email' => 'visitor@example.invalid'])->assertUnprocessable();
            $this->assertLessThan(2, microtime(true) - $started);
            $this->assertDatabaseMissing('cache', ['key' => $this->prefix.'abandoned']);
            $other->commit();
        } finally {
            if ($other->transactionLevel() > 0) {
                $other->rollBack();
            }
        }
        $this->postJson('/api/v1/login', ['email' => 'visitor@example.invalid'])->assertUnprocessable();
        $this->assertDatabaseHas('cache', ['key' => $this->prefix.'refreshing']);
    }

    public function test_cleanup_failure_is_sanitized_and_does_not_disable_login_demo_or_active_throttles(): void
    {
        $this->seed();
        DB::table('cache')->insert(['key' => $this->prefix.'abandoned', 'value' => 'expired', 'expiration' => now()->timestamp - 1]);
        DB::unprepared("CREATE FUNCTION fail_gc_delete() RETURNS trigger LANGUAGE plpgsql AS \$\$ BEGIN RAISE EXCEPTION 'private-secret-never-log'; END \$\$; CREATE TRIGGER fail_gc BEFORE DELETE ON cache FOR EACH ROW EXECUTE FUNCTION fail_gc_delete();");
        $handler = new TestHandler;
        Log::driver()->getLogger()->pushHandler($handler);
        try {
            for ($attempt = 0; $attempt < 5; $attempt++) {
                $this->postJson('/api/v1/login', ['email' => 'visitor@example.invalid'])->assertUnprocessable();
            }
            $this->postJson('/api/v1/login', ['email' => 'visitor@example.invalid'])->assertTooManyRequests();
            $this->postJson('/api/v1/demo-sessions', [])->assertCreated();
            $records = $handler->getRecords();
            $this->assertNotEmpty($records);
            $this->assertStringNotContainsString('private-secret-never-log', json_encode($records));
            $this->assertStringNotContainsString('SQLSTATE', json_encode($records));
            $this->assertDatabaseHas('cache', ['key' => $this->prefix.'abandoned']);
        } finally {
            DB::unprepared('DROP TRIGGER fail_gc ON cache; DROP FUNCTION fail_gc_delete();');
        }
    }

    public function test_startup_command_uses_configured_cache_table_and_only_one_batch(): void
    {
        DB::statement('CREATE TABLE gc_configured_cache (LIKE cache INCLUDING ALL)');
        try {
            config(['cache.stores.database.table' => 'gc_configured_cache']);
            Cache::forgetDriver('database');
            for ($index = 0; $index < 40; $index++) {
                DB::table('gc_configured_cache')->insert(['key' => $this->prefix.'expired-'.$index, 'value' => 'expired', 'expiration' => 0]);
            }
            DB::table('cache')->insert(['key' => $this->prefix.'old-table', 'value' => 'untouched', 'expiration' => 0]);
            $this->artisan('cache:prune-expired')->assertSuccessful();
            $this->assertSame(8, DB::table('gc_configured_cache')->count());
            $this->assertDatabaseHas('cache', ['key' => $this->prefix.'old-table']);
        } finally {
            DB::statement('DROP TABLE gc_configured_cache');
        }
    }

    public function test_empty_prefix_and_lock_table_configuration_never_enable_unscoped_deletion(): void
    {
        DB::table('cache')->insert(['key' => 'foreign-key', 'value' => 'untouched', 'expiration' => 0]);
        config(['cache.prefix' => '']);
        Cache::forgetDriver('database');
        $this->artisan('cache:prune-expired')->assertSuccessful();
        $this->assertDatabaseHas('cache', ['key' => 'foreign-key']);

        config(['cache.prefix' => $this->prefix, 'cache.stores.database.table' => 'cache_locks']);
        Cache::forgetDriver('database');
        DB::table('cache_locks')->insert(['key' => $this->prefix.'lock', 'owner' => 'owner', 'expiration' => 0]);
        $this->artisan('cache:prune-expired')->assertSuccessful();
        $this->assertDatabaseHas('cache_locks', ['key' => $this->prefix.'lock']);
    }

    public function test_slow_delete_times_out_without_delaying_or_disabling_login(): void
    {
        DB::table('cache')->insert(['key' => $this->prefix.'abandoned', 'value' => 'expired', 'expiration' => 0]);
        DB::unprepared('CREATE FUNCTION slow_gc_delete() RETURNS trigger LANGUAGE plpgsql AS $$ BEGIN PERFORM pg_sleep(5); RETURN OLD; END $$; CREATE TRIGGER slow_gc BEFORE DELETE ON cache FOR EACH ROW EXECUTE FUNCTION slow_gc_delete();');
        $before = DB::selectOne('SHOW statement_timeout')->statement_timeout;
        try {
            $started = microtime(true);
            $this->postJson('/api/v1/login', ['email' => 'visitor@example.invalid'])->assertUnprocessable();
            $this->assertLessThan(2, microtime(true) - $started);
            $this->assertDatabaseHas('cache', ['key' => $this->prefix.'abandoned']);
            $this->assertSame($before, DB::selectOne('SHOW statement_timeout')->statement_timeout);
        } finally {
            DB::unprepared('DROP TRIGGER slow_gc ON cache; DROP FUNCTION slow_gc_delete();');
        }
    }
}
