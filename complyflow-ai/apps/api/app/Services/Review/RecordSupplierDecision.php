<?php

namespace App\Services\Review;

use App\Models\AnalysisRun;
use App\Models\Organization;
use App\Models\RequirementSet;
use App\Models\Supplier;
use App\Models\SupplierDecision;
use App\Models\User;
use App\Services\Audit\AuditEvent;
use App\Services\Audit\AuditHash;
use App\Services\Audit\AuditLogger;
use App\Support\CurrentOrganization;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;

class RecordSupplierDecision
{
    public function __construct(private AuditLogger $audit) {}

    public function handle(User $actor, string $supplierId, array $input, string $key): SupplierDecision
    {
        return DB::transaction(function () use ($actor, $supplierId, $input, $key): SupplierDecision {
            $tenant = app(CurrentOrganization::class)->id();
            // Serialize tenant writers while allowing FK KEY SHARE checks by a
            // version creator that may already hold the checklist root.
            Organization::whereKey($tenant)->lock('for no key update')->firstOrFail();
            abort_unless(Str::isUuid($supplierId), 404);
            $supplier = Supplier::wherePublicIdForCurrentOrganization($supplierId)->lockForUpdate()->firstOrFail();
            Gate::forUser($actor)->authorize('create', SupplierDecision::class);
            $run = AnalysisRun::wherePublicIdForCurrentOrganization($input['analysis_id'])->where('supplier_id', $supplier->id)->firstOrFail();
            $hash = hash('sha256', AuditHash::canonicalJson([$actor->public_id, $supplierId, $input]));
            $existing = SupplierDecision::forCurrentOrganization()->where('idempotency_key', $key)->first();
            if ($existing) {
                abort_unless(hash_equals($existing->request_hash, $hash), 409, 'Idempotency key already used with different input.');

                return $existing;
            }
            abort_if(SupplierDecision::forCurrentOrganization()->where('analysis_run_id', $run->id)->exists(), 409, 'This analysis already has a final decision.');
            abort_unless($run->status === 'completed', 409, 'A completed analysis is required.');
            abort_if(AnalysisRun::forCurrentOrganization()->where('supplier_id', $supplier->id)->where('id', '>', $run->id)->exists(), 409, 'Select the latest analysis.');
            $set = RequirementSet::forCurrentOrganization()->whereKey($run->requirement_set_id)->firstOrFail();
            $rootId = $set->parent_id ?? $set->id;
            RequirementSet::forCurrentOrganization()->whereKey($rootId)->lockForUpdate()->firstOrFail();
            $lineage = RequirementSet::forCurrentOrganization()->where(fn ($query) => $query->where('id', $rootId)->orWhere('parent_id', $rootId))->orderBy('id')->lockForUpdate()->get();
            $set = $lineage->firstWhere('id', $set->id);
            abort_unless($set?->status === 'published' && $lineage->where('status', 'published')->max('version') === $set->version, 409, 'Select the current published checklist.');
            $unreviewed = DB::table('requirements as r')->where('r.organization_id', $tenant)->where('r.requirement_set_id', $set->id)->where('r.is_required', true)
                ->whereNotExists(function ($query) use ($run, $tenant) {
                    $query->selectRaw('1')->from('analysis_findings as f')->join('finding_reviews as v', 'v.analysis_finding_id', '=', 'f.id')
                        ->whereColumn('f.requirement_id', 'r.id')->where('f.organization_id', $tenant)->where('v.organization_id', $tenant)->where('f.analysis_run_id', $run->id);
                })->exists();
            abort_if($unreviewed, 409, 'All required findings must be reviewed.');
            $decision = SupplierDecision::create([
                'supplier_id' => $supplier->id, 'analysis_run_id' => $run->id, 'decided_by' => $actor->id,
                'decision' => $input['decision'], 'justification' => $input['reason'], 'decided_at' => now(),
                'idempotency_key' => $key, 'request_hash' => $hash,
            ]);
            $this->audit->record(new AuditEvent($actor, 'supplier.decided', 'supplier_decision', $decision->public_id, [
                'supplier_id' => $supplier->public_id, 'analysis_id' => $run->public_id,
                'requirement_set_id' => $set->public_id, 'decision' => $decision->decision,
            ]));

            return $decision;
        }, 3);
    }
}
