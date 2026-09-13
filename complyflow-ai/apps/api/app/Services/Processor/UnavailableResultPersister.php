<?php

namespace App\Services\Processor;

use App\Data\Processor\ProcessorResult;
use App\Models\AnalysisRun;

class UnavailableResultPersister implements ResultPersister
{
    public function handle(AnalysisRun $run, ProcessorResult $result): void
    {
        throw new ProcessorException('result_persistence_unavailable');
    }
}
