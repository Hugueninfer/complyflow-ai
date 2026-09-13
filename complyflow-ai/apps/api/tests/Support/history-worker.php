<?php

use App\Models\AuditLog;
use App\Models\User;
use App\Services\Audit\AuditEvent;
use App\Services\Audit\AuditLogger;
use App\Services\Review\RecordSupplierDecision;
use App\Support\CurrentOrganization;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
[$script, $organization, $actor, $mode, $target, $analysis, $name, $pause, $key] = $argv;
app(CurrentOrganization::class)->set((int) $organization);
DB::select('select set_config(?, ?, false)', ['application_name', $name]);
if ($pause === 'pause') {
    AuditLog::creating(function (): void {
        echo "ready\n";
        flush();
        fgets(STDIN);
    });
}
try {
    $user = User::findOrFail($actor);
    $record = $mode === 'decision'
        ? app(RecordSupplierDecision::class)->handle($user, $target, ['analysis_id' => $analysis, 'decision' => 'approved', 'reason' => 'Reviewed'], $key)
        : app(AuditLogger::class)->record(new AuditEvent($user, 'finding.reviewed', 'finding_review', $target, ['finding_id' => $target, 'analysis_id' => $analysis, 'status' => 'met']));
    echo json_encode(['status' => $record->wasRecentlyCreated ? 201 : 200, 'id' => $record->public_id])."\n";
} catch (HttpExceptionInterface $error) {
    echo json_encode(['status' => $error->getStatusCode()])."\n";
} catch (Throwable $error) {
    fwrite(STDERR, get_class($error)."\n");
    exit(1);
}
