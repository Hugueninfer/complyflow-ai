<?php

namespace App\Services\Processor;

use App\Data\Processor\ProcessorResult;
use App\Models\AnalysisRun;

interface ResultPersister
{
    /** Task 10 must persist findings/evidence and completed status atomically. */
    public function handle(AnalysisRun $run, ProcessorResult $result): void;
}
