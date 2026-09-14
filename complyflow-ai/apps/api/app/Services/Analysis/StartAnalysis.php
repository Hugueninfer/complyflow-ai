<?php

namespace App\Services\Analysis;

use App\Jobs\ProcessAnalysis;
use App\Models\AnalysisRun;
use App\Models\DemoSession;
use App\Models\Document;
use App\Models\Organization;
use App\Models\RequirementSet;
use App\Models\Supplier;
use App\Support\CurrentOrganization;
use Illuminate\Support\Facades\DB;

class StartAnalysis
{
    public function handle(Supplier $supplier, string $setId, array $documentIds, string $key): AnalysisRun
    {
        return DB::transaction(function () use ($supplier, $setId, $documentIds, $key) {
            // Serialize tenant keys and demo quotas, including requests for different suppliers.
            $tenant = app(CurrentOrganization::class)->id();
            $demo = DemoSession::where('organization_id', $tenant)->lockForUpdate()->first();
            abort_if($demo?->expires_at->lessThanOrEqualTo(now()), 401, 'Demo session expired.');
            // Keep tenant writers serialized without blocking FK checks by a
            // checklist version creator holding the selected checklist.
            Organization::whereKey($tenant)->lock('for no key update')->firstOrFail();
            $supplier = Supplier::wherePublicIdForCurrentOrganization($supplier->public_id)->lockForUpdate()->firstOrFail();
            $set = RequirementSet::wherePublicIdForCurrentOrganization($setId)->where('status', 'published')->lockForUpdate()->firstOrFail();
            $requirementCount = $set->requirements()->forCurrentOrganization()->count();
            abort_unless($requirementCount >= 1 && $requirementCount <= 100, 422, 'Checklist must contain between 1 and 100 requirements.');
            $documents = Document::forCurrentOrganization()->where('supplier_id', $supplier->id)
                ->whereIn('public_id', $documentIds)->orderBy('sha256')->lockForUpdate()->get();
            abort_unless($documents->count() === count($documentIds), 422, 'Invalid document selection.');
            abort_if($documents->sum('size_bytes') > 15 * 1024 * 1024, 422, 'Selected PDFs must not exceed 15 MiB in total.');
            $fingerprint = AnalysisFingerprint::make($supplier, $set, $documents->pluck('sha256')->all());
            $run = AnalysisRun::forCurrentOrganization()->firstOrCreate(
                ['idempotency_key' => $key],
                ['supplier_id' => $supplier->id, 'requirement_set_id' => $set->id, 'document_set_hash' => $fingerprint, 'document_ids' => $documents->pluck('public_id')->all(), 'status' => 'pending'],
            );
            abort_unless(hash_equals($run->document_set_hash, $fingerprint), 409, 'Idempotency key already used with different input.');
            if ($run->wasRecentlyCreated) {
                abort_if($demo && AnalysisRun::forCurrentOrganization()->count() > $demo->analysis_quota, 429, 'Demo analysis quota exceeded.');
                $demo?->increment('analyses_used');
                ProcessAnalysis::dispatch($run->id)->afterCommit();
            }

            return $run;
        }, 3);
    }
}
