<?php

namespace App\Services\Audit;

use App\Models\AuditLog;
use App\Models\FindingReview;
use App\Models\Organization;
use App\Models\SupplierDecision;
use App\Support\CurrentOrganization;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

class AuditLogger
{
    public function __construct(private AuditHash $hash) {}

    public function record(AuditEvent $event): AuditLog
    {
        Validator::make(['action' => $event->action], ['action' => ['required', 'in:finding.reviewed,supplier.decided']])->validate();
        $review = $event->action === 'finding.reviewed';
        Gate::forUser($event->actor)->authorize('create', $review ? FindingReview::class : SupplierDecision::class);
        $fields = $review
            ? ['finding_id' => ['required', 'uuid'], 'analysis_id' => ['required', 'uuid'], 'status' => ['required', 'in:met,partial,missing,inconclusive']]
            : ['supplier_id' => ['required', 'uuid'], 'analysis_id' => ['required', 'uuid'], 'requirement_set_id' => ['required', 'uuid'], 'decision' => ['required', 'in:approved,rejected,conditional']];
        // Only fixed enums and public UUIDs enter the log; human prose stays in history tables.
        Validator::make(['target_type' => $event->targetType, 'target_id' => $event->targetId, 'metadata' => $event->metadata], [
            'target_type' => ['required', 'in:'.($review ? 'finding_review' : 'supplier_decision')],
            'target_id' => ['required', 'uuid'],
            'metadata' => ['required', 'array:'.implode(',', array_keys($fields))],
            ...collect($fields)->mapWithKeys(fn ($rules, $key) => ['metadata.'.$key => $rules])->all(),
        ])->validate();

        return DB::transaction(function () use ($event): AuditLog {
            // Lock an existing tenant row, including when its chain has no first event yet.
            $organization = Organization::whereKey(app(CurrentOrganization::class)->id())->lockForUpdate()->firstOrFail();
            $log = new AuditLog([
                'public_id' => (string) Str::uuid(),
                'organization_id' => $organization->id,
                'organization_public_id' => $organization->public_id,
                'actor_id' => $event->actor->id,
                'actor_public_id' => $event->actor->public_id,
                'action' => $event->action,
                'target_type' => $event->targetType,
                'target_public_id' => $event->targetId,
                'metadata' => $event->metadata,
                'previous_hash' => AuditLog::forCurrentOrganization()->orderByDesc('id')->value('event_hash'),
                'occurred_at' => now()->utc()->startOfSecond(),
            ]);
            $log->event_hash = $this->hash->make($log);
            $log->save();

            return $log;
        }, 3);
    }
}
