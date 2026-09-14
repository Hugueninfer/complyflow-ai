<?php

use App\Http\Controllers\Api\V1\RequirementSetController;
use App\Models\RequirementSet;
use App\Models\Supplier;
use App\Models\User;
use App\Services\Analysis\StartAnalysis;
use App\Services\Review\RecordSupplierDecision;
use App\Support\CurrentOrganization;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
[$script, $organization, $actorId, $mode, $supplier, $analysis, $source, $name, $barrier, $document] = $argv;
app(CurrentOrganization::class)->set((int) $organization);
$actor = User::findOrFail($actorId);
Auth::login($actor);
DB::select('select set_config(?, ?, false)', ['application_name', $name]);
// Make the non-retrying version endpoint the victim if a circular wait exists.
DB::select('select set_config(?, ?, false)', ['deadlock_timeout', $mode === 'version' ? '200ms' : '5s']);
$paused = false;
DB::listen(function (QueryExecuted $query) use (&$paused, $barrier): void {
    if ($barrier !== 'insert' && ! $paused && str_contains($query->sql, '"requirement_sets"') && str_contains($query->sql, 'for update')) {
        $paused = true;
        echo "ready\n";
        flush();
        fgets(STDIN);
    }
});
if ($barrier === 'insert') {
    RequirementSet::creating(function (): void {
        echo "ready\n";
        flush();
        fgets(STDIN);
    });
}

try {
    if ($mode === 'version') {
        $response = app(RequirementSetController::class)->createVersion($source);
        $result = ['status' => $response->getStatusCode(), 'data' => $response->getData(true)['data']];
    } elseif ($mode === 'start') {
        config(['queue.default' => 'database']);
        $run = app(StartAnalysis::class)->handle(Supplier::wherePublicIdForCurrentOrganization($supplier)->firstOrFail(), $source, [$document], 'version-race-start');
        $result = ['status' => 201, 'id' => $run->public_id];
    } else {
        $decision = app(RecordSupplierDecision::class)->handle($actor, $supplier, ['analysis_id' => $analysis, 'decision' => 'approved', 'reason' => 'Reviewed'], 'version-race');
        $result = ['status' => 201, 'id' => $decision->public_id];
    }
    echo json_encode($result)."\n";
} catch (QueryException $error) {
    echo json_encode(['status' => 500, 'sqlstate' => $error->errorInfo[0] ?? $error->getCode()])."\n";
} catch (HttpExceptionInterface $error) {
    echo json_encode(['status' => $error->getStatusCode()])."\n";
} catch (Throwable $error) {
    fwrite(STDERR, get_class($error)."\n");
    exit(1);
}
