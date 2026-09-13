<?php

namespace App\Jobs;

use App\Models\AnalysisRun;
use App\Services\Processor\ProcessorClient;
use App\Services\Processor\ProcessorException;
use App\Services\Processor\ResultPersister;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Jobs\DatabaseJob;
use Illuminate\Queue\MaxAttemptsExceededException;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Facades\Cache;
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
                'completed_at' => null, 'error_code' => null, 'error_message' => null,
                'owner_message_uuid' => $this->job?->uuid(), 'owner_reservation_id' => $this->job ? (string) $this->job->getJobId() : null,
                'owner_reservation_attempt' => $this->job?->attempts()]);

            return $run;
        });
        if (! $run) {
            return;
        }
        try {
            $result = $client->analyze($run);
            DB::transaction(function () use ($persister, $run, $result) {
                // HTTP runs outside this transaction. A callback may have terminated
                // or superseded this reservation while the response was in flight.
                $ownedRun = $this->ownedAttempt($run)->lockForUpdate()->first();
                if ($ownedRun) {
                    $persister->handle($ownedRun, $result);
                }
            });
        } catch (ProcessorException $error) {
            $terminal = ! $error->retryable || $run->attempts >= $this->tries;
            $this->ownedAttempt($run)
                ->update(['status' => $terminal ? 'failed' : 'pending', 'error_code' => $error->publicCode,
                    'error_message' => $error->getMessage(), 'completed_at' => $terminal ? now() : null]);
            if ($error->retryable) {
                throw $error;
            }
        } catch (Throwable) {
            // Do not let a framework exception serialize document-bearing bindings to failed_jobs.
            $error = new ProcessorException('analysis_failed', true);
            $this->ownedAttempt($run)
                ->update(['status' => $run->attempts >= $this->tries ? 'failed' : 'pending', 'error_code' => $error->publicCode,
                    'error_message' => $error->getMessage(), 'completed_at' => $run->attempts >= $this->tries ? now() : null]);
            throw $error;
        }
    }

    public function failed(?Throwable $error): void
    {
        // Laravel reconstructs this command from the queued payload. Correlate
        // using the actual Job metadata, not state assigned only during handle().
        if (! $this->job?->uuid() || $this->job->isReleased()) {
            return;
        }
        $query = AnalysisRun::whereKey($this->analysisRunId)->whereNotIn('status', ['completed', 'failed'])
            ->where('owner_message_uuid', $this->job->uuid())
            ->where('owner_reservation_id', (string) $this->job->getJobId());
        $failure = [
            'status' => 'failed', 'error_code' => 'analysis_failed', 'error_message' => 'Não foi possível processar a análise.', 'completed_at' => now(),
        ];
        $updated = (clone $query)->where('owner_reservation_attempt', $this->job->attempts())->update($failure);
        if (! $updated && $error instanceof MaxAttemptsExceededException && $this->job instanceof DatabaseJob) {
            // DatabaseQueue re-reserves an expired row with the SAME ID, whereas
            // release/backoff creates a NEW row. Only that interrupted reservation
            // may be recovered; a free lock alone does not prove a crash.
            $lock = Cache::lock($this->middleware()[0]->getLockKey($this), 85);
            if ($lock->get()) {
                try {
                    $query->where('status', 'processing')
                        ->where('owner_reservation_attempt', $this->job->attempts() - 1)->update($failure);
                } finally {
                    $lock->release();
                }
            }
        }
    }

    private function ownedAttempt(AnalysisRun $run): Builder
    {
        return AnalysisRun::whereKey($run->id)->where('status', 'processing')->where('attempts', $run->attempts)
            ->where('owner_message_uuid', $run->owner_message_uuid)
            ->where('owner_reservation_id', $run->owner_reservation_id)
            ->where('owner_reservation_attempt', $run->owner_reservation_attempt);
    }
}
