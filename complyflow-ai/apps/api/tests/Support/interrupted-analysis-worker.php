<?php

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Queue\WorkerOptions;
use Illuminate\Support\Facades\Http;

require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
config(['queue.default' => 'database', 'cache.default' => 'database',
    'services.processor.url' => 'http://processor:8001', 'services.processor.secret' => 'test-only-secret']);
Http::preventStrayRequests();
Http::fake(function () use ($argv) {
    echo "reached_http\n";
    flush();
    if ($argv[1] === 'crash') {
        posix_kill(getmypid(), SIGKILL);
    }
    // Let the real worker's SIGALRM handler terminate the owning reservation.
    sleep(5);
    throw new RuntimeException('Worker timeout did not fire.');
});
app('queue.worker')->daemon('database', 'default', new WorkerOptions(sleep: 0, stopWhenEmpty: true));
