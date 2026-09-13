<?php

use App\Models\Document;
use App\Models\Supplier;
use App\Services\Documents\StorePdf;
use App\Support\CurrentOrganization;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
app(CurrentOrganization::class)->set((int) $argv[1]);
DB::select('select set_config(?, ?, false)', ['application_name', $argv[4]]);

if ($argv[5] === 'pause') {
    Document::creating(function (): void {
        echo "ready\n";
        flush();
        fgets(STDIN);
    });
}

try {
    $supplier = Supplier::query()->wherePublicIdForCurrentOrganization($argv[2])->firstOrFail();
    $document = app(StorePdf::class)->handle($supplier, new UploadedFile($argv[3], 'private.pdf', null, null, true));
    echo json_encode(['status' => $document->wasRecentlyCreated ? 201 : 200, 'id' => $document->public_id])."\n";
} catch (Throwable $exception) {
    echo json_encode(['status' => $exception instanceof HttpExceptionInterface ? $exception->getStatusCode() : 500])."\n";
}
