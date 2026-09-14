<?php

namespace App\Services\Maintenance;

use Illuminate\Cache\DatabaseStore;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use LogicException;
use Throwable;

final class PruneExpiredApplicationCache
{
    public function run(): int
    {
        try {
            $name = config('cache.limiter') ?? config('cache.default');
            $settings = config('cache.stores.'.$name);
            if (($settings['driver'] ?? null) !== 'database') {
                return 0;
            }
            $store = Cache::store($name)->getStore();
            if (! $store instanceof DatabaseStore || $store->getConnection()->getDriverName() !== 'pgsql') {
                return 0;
            }
            $prefix = $store->getPrefix();
            $table = $settings['table'];
            if ($prefix === '' || $table === ($settings['lock_table'] ?? 'cache_locks')) {
                throw new LogicException('A distinct application cache table and nonempty prefix are required.');
            }
            $db = $store->getConnection();

            // Keep transaction-local timeouts and locks out of an enclosing business transaction.
            if ($db->transactionLevel() !== 0) {
                return 0;
            }

            return $db->transaction(function () use ($db, $table, $prefix): int {
                $db->select("SELECT set_config('statement_timeout', '100ms', true), set_config('lock_timeout', '25ms', true)");
                if (! $db->selectOne('SELECT pg_try_advisory_xact_lock(170026, 1802) AS acquired')->acquired) {
                    return 0;
                }
                $now = now()->timestamp;
                $keys = $db->table($table)
                    ->where('expiration', '<=', $now)
                    // Literal prefix, not LIKE: configured %, _ and backslashes are not wildcards.
                    ->whereRaw('starts_with("key", ?)', [$prefix])
                    ->whereNotNull('value')
                    ->orderBy('expiration')->orderBy('key')
                    ->limit(32)->lock('FOR UPDATE SKIP LOCKED')->pluck('key');

                return $keys->isEmpty() ? 0 : $db->table($table)
                    ->whereIn('key', $keys)->where('expiration', '<=', $now)->delete();
            });
        } catch (Throwable) {
            // Maintenance is fail-open; auth/CSRF/throttle remain independent and mandatory.
            // Never log exception text, SQL, keys, connection strings or user identities.
            try {
                Log::warning('Expired application cache cleanup failed.');
            } catch (Throwable) {
                // A failed log sink must not turn optional housekeeping into an outage either.
            }

            return 0;
        }
    }
}
