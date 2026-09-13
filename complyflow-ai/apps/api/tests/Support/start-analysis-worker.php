<?php

use App\Models\AnalysisRun;
use App\Models\Supplier;
use App\Services\Analysis\StartAnalysis;
use App\Support\CurrentOrganization;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
config(['queue.default' => 'database']);
app(CurrentOrganization::class)->set((int) $argv[1]);
DB::select('select set_config(?, ?, false)', ['application_name', $argv[5]]);
if ($argv[6] === 'pause') {
    AnalysisRun::creating(function (): void {
        echo "ready\n";
        flush();
        fgets(STDIN);
    });
}
try {
    $run = app(StartAnalysis::class)->handle(Supplier::wherePublicIdForCurrentOrganization($argv[2])->firstOrFail(), $argv[3], [$argv[4]], 'concurrent-key');
    echo json_encode(['id' => $run->public_id, 'created' => $run->wasRecentlyCreated])."\n";
} catch (Throwable) {
    fwrite(STDERR, "Analysis worker failed.\n");
    exit(1);
}
