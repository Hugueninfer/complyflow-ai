<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Services\Audit\AuditHash;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class AuditLogController extends Controller
{
    public function index(Request $request)
    {
        Gate::authorize('viewAny', AuditLog::class);
        $input = $request->validate(['per_page' => ['sometimes', 'integer', 'min:1', 'max:100'], 'page' => ['sometimes', 'integer', 'min:1', 'max:2147483647']]);
        $logs = AuditLog::forCurrentOrganization()->orderByDesc('id')->paginate($input['per_page'] ?? 25);
        $integrity = $logs->isEmpty() ? 'empty' : 'verified';
        $events = $logs->getCollection();
        $boundary = $events->isNotEmpty() ? AuditLog::forCurrentOrganization()->where('id', '<', $events->last()->id)->orderByDesc('id')->first() : null;
        foreach ($events as $index => $event) {
            if (! $event->organization_public_id || ! $event->event_hash) {
                if ($integrity !== 'broken') {
                    $integrity = 'unverifiable';
                }

                continue;
            }
            $older = $events->get($index + 1) ?? $boundary;
            if (! hash_equals($event->event_hash, app(AuditHash::class)->make($event))) {
                $integrity = 'broken';
            }
            if ($older && (! $older->organization_public_id || ! $older->event_hash)) {
                if ($integrity !== 'broken') {
                    $integrity = 'unverifiable';
                }
            } elseif ($event->previous_hash !== $older?->event_hash) {
                $integrity = 'broken';
            }
        }

        return response()->json([
            'data' => $logs->getCollection()->map(fn (AuditLog $log) => [
                'id' => $log->public_id, 'organization_public_id' => $log->organization_public_id,
                'actor_public_id' => $log->actor_public_id, 'action' => $log->action,
                'target_type' => $log->target_type, 'target_id' => $log->target_public_id,
                'metadata' => $log->metadata, 'previous_hash' => $log->previous_hash,
                'event_hash' => $log->event_hash, 'occurred_at' => $log->occurred_at->toISOString(),
            ]),
            'meta' => ['current_page' => $logs->currentPage(), 'last_page' => $logs->lastPage(), 'per_page' => $logs->perPage(), 'total' => $logs->total(), 'integrity' => ['status' => $integrity, 'scope' => 'page']],
        ]);
    }
}
