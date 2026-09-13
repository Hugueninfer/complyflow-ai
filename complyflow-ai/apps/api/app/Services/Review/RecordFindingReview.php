<?php

namespace App\Services\Review;

use App\Models\AnalysisRun;
use App\Models\FindingReview;
use App\Models\Organization;
use App\Models\Requirement;
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

class RecordFindingReview
{
    public function __construct(private AuditLogger $audit) {}

    public function handle(User $actor, string $findingId, array $input, string $key): FindingReview
    {
        return DB::transaction(function () use ($actor, $findingId, $input, $key): FindingReview {
            $tenant = app(CurrentOrganization::class)->id();
            Organization::whereKey($tenant)->lockForUpdate()->firstOrFail();
            abort_unless(Str::isUuid($findingId), 404);
            $finding = DB::table('analysis_findings')->where('organization_id', $tenant)->where('public_id', $findingId)->first();
            abort_unless($finding, 404);
            Gate::forUser($actor)->authorize('create', FindingReview::class);
            $run = AnalysisRun::forCurrentOrganization()->whereKey($finding->analysis_run_id)->firstOrFail();
            Requirement::forCurrentOrganization()->whereKey($finding->requirement_id)->where('requirement_set_id', $run->requirement_set_id)->firstOrFail();
            Supplier::forCurrentOrganization()->whereKey($run->supplier_id)->firstOrFail();
            $hash = hash('sha256', AuditHash::canonicalJson([$actor->public_id, $findingId, $input]));
            $existing = FindingReview::forCurrentOrganization()->where('idempotency_key', $key)->first();
            if ($existing) {
                abort_unless(hash_equals($existing->request_hash, $hash), 409, 'Idempotency key already used with different input.');

                return $existing;
            }
            abort_unless($run->status === 'completed', 409, 'Only completed analyses can be reviewed.');
            abort_if(SupplierDecision::forCurrentOrganization()->where('analysis_run_id', $run->id)->exists(), 409, 'This analysis already has a final decision.');
            $review = FindingReview::create([
                'analysis_finding_id' => $finding->id, 'reviewer_id' => $actor->id,
                'status' => $input['status'], 'justification' => $input['justification'], 'notes' => $input['note'] ?? null,
                'reviewed_at' => now(), 'idempotency_key' => $key, 'request_hash' => $hash,
            ]);
            $this->audit->record(new AuditEvent($actor, 'finding.reviewed', 'finding_review', $review->public_id, [
                'finding_id' => $finding->public_id, 'analysis_id' => $run->public_id, 'status' => $review->status,
            ]));

            return $review;
        }, 3);
    }
}
