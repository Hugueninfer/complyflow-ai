<?php

namespace App\Jobs;

use App\Models\AnalysisRun;
use App\Services\Processor\ProcessorClient;
use App\Services\Processor\ProcessorException;
use App\Services\Processor\ResultPersister;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Facades\DB;
use Throwable;

class ProcessAnalysis implements ShouldQueue
{
    use Queueable;

    public int $tries = 4;

    public int $timeout = 75;

    public bool $failOnTimeout = true;

    public function __construct(public int $analysisRunId) {}

    public function backoff(): array
    {
        return [10, 30, 90];
    }

    public function middleware(): array
    {
        return [(new WithoutOverlapping('analysis:'.$this->analysisRunId))->releaseAfter(10)->expireAfter(85)];
    }

    public function handle(ProcessorClient $client, ResultPersister $persister): void
    {
        $run = DB::transaction(function () {
            $run = AnalysisRun::whereKey($this->analysisRunId)->lockForUpdate()->first();
            if (! $run || in_array($run->status, ['completed', 'failed'], true)) {
                return null;
            }
            $run->update(['status' => 'processing', 'attempts' => $run->attempts + 1, 'started_at' => $run->started_at ?? now(),
                'completed_at' => null, 'error_code' => null, 'error_message' => null]);

            return $run;
        });
        if (! $run) {
            return;
        }
        try {
            $persister->handle($run, $client->analyze($run));
        } catch (ProcessorException $error) {
            $terminal = ! $error->retryable || $run->attempts >= $this->tries;
            AnalysisRun::whereKey($run->id)->where('status', 'processing')->where('attempts', $run->attempts)
                ->update(['status' => $terminal ? 'failed' : 'pending', 'error_code' => $error->publicCode,
                    'error_message' => $error->getMessage(), 'completed_at' => $terminal ? now() : null]);
            if ($error->retryable) {
                throw $error;
            }
        } catch (Throwable) {
            // Do not let a framework exception serialize document-bearing bindings to failed_jobs.
            $error = new ProcessorException('analysis_failed', true);
            AnalysisRun::whereKey($run->id)->where('status', 'processing')->where('attempts', $run->attempts)
                ->update(['status' => $run->attempts >= $this->tries ? 'failed' : 'pending', 'error_code' => $error->publicCode,
                    'error_message' => $error->getMessage(), 'completed_at' => $run->attempts >= $this->tries ? now() : null]);
            throw $error;
        }
    }

    public function failed(?Throwable $error): void
    {
        AnalysisRun::whereKey($this->analysisRunId)->whereNotIn('status', ['completed', 'failed'])->update([
            'status' => 'failed', 'error_code' => 'analysis_failed', 'error_message' => 'Não foi possível processar a análise.', 'completed_at' => now(),
        ]);
    }
}
