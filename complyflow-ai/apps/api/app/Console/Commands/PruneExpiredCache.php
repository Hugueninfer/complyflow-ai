<?php

namespace App\Console\Commands;

use App\Services\Maintenance\PruneExpiredApplicationCache;
use Illuminate\Console\Command;

final class PruneExpiredCache extends Command
{
    protected $signature = 'cache:prune-expired';

    protected $description = 'Prune one bounded batch of expired application cache entries';

    public function handle(PruneExpiredApplicationCache $cleanup): int
    {
        $this->info('Pruned '.$cleanup->run().' expired application cache entries.');

        return self::SUCCESS;
    }
}
