<?php

use App\Models\AnalysisRun;
use App\Models\RequirementSet;
use App\Services\Analysis\PersistProcessorResult;
use App\Services\Processor\ProcessorException;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Tests\Support\ProcessorResultFactory;

require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
DB::select('select set_config(?, ?, false)', ['application_name', $argv[2]]);
$run = AnalysisRun::findOrFail($argv[1]);
if ($argv[3] === 'archive') {
    RequirementSet::findOrFail($run->requirement_set_id)->update(['status' => 'archived']);
    echo "archived\n";
    exit(0);
}
$result = ProcessorResultFactory::valid($run);
if ($argv[3] !== 'run') {
    DB::listen(function ($query) use ($argv): void {
        if (str_starts_with($query->sql, 'insert into "document_pages"')) {
            echo "ready\n";
            flush();
            fgets(STDIN);
        }
        if ($argv[3] === 'rollback' && str_starts_with($query->sql, 'insert into "finding_citations"')) {
            throw new RuntimeException('Injected failure after citation write.');
        }
    });
}
try {
    app(PersistProcessorResult::class)->handle($run, $result);
    echo json_encode(['status' => $run->fresh()->status])."\n";
} catch (ProcessorException $error) {
    echo json_encode(['error' => $error->publicCode])."\n";
}
