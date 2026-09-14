<?php

// Hold a database session lock through migration and seeding, including first deploy.
require '/app/apps/api/vendor/autoload.php';
// A restarted container can retain its previous runtime cache.
if (is_file('/app/apps/api/bootstrap/cache/config.php')) {
    unlink('/app/apps/api/bootstrap/cache/config.php');
}
$app = require '/app/apps/api/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
try {
    $kernel->bootstrap();
    $db = Illuminate\Support\Facades\DB::connection();
    $db->statement("SET statement_timeout = '60s'");
    $db->select('SELECT pg_advisory_lock(170026, 18)');
    try {
        $db->statement('CREATE EXTENSION IF NOT EXISTS vector');
        foreach ([['migrate', ['--force' => true]], ['db:seed', ['--force' => true]], ['demo:purge-expired', []], ['config:cache', []], ['route:cache', []], ['view:cache', []]] as [$command, $arguments]) {
            if ($kernel->call($command, $arguments) !== 0) {
                throw new RuntimeException('Bootstrap command failed');
            }
        }
    } finally {
        $db->select('SELECT pg_advisory_unlock(170026, 18)');
    }
    fwrite(STDOUT, "Database initialized; application caches ready.\n");
} catch (Throwable) {
    // Never print command output or database exceptions (they can contain secrets).
    fwrite(STDERR, "Application initialization failed; check database availability and deployment configuration.\n");
    exit(1);
}
