<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\AnalysisRun;
use App\Models\RequirementSet;
use App\Models\SupplierDecision;
use App\Services\Review\RecordSupplierDecision;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;

class SupplierDecisionController extends Controller
{
    public function store(Request $request, string $supplier, RecordSupplierDecision $service)
    {
        Gate::authorize('create', SupplierDecision::class);
        $input = $request->validate([
            'analysis_id' => ['required', 'uuid'],
            'decision' => ['required', 'in:approved,rejected,conditional'],
            'reason' => ['required', 'string', 'max:10000'],
        ]);
        $key = Validator::make(['key' => $request->header('Idempotency-Key')], ['key' => ['required', 'string', 'max:128', 'regex:/^[A-Za-z0-9._:-]+$/']])->validate()['key'];
        $decision = $service->handle($request->user(), $supplier, $input, $key);
        $run = AnalysisRun::forCurrentOrganization()->findOrFail($decision->analysis_run_id);
        $set = RequirementSet::forCurrentOrganization()->findOrFail($run->requirement_set_id);

        return response()->json(['data' => [
            'id' => $decision->public_id, 'supplier_id' => $supplier,
            'analysis_id' => $run->public_id, 'requirement_set_id' => $set->public_id,
            'decision' => $decision->decision, 'reason' => $decision->justification,
            'decided_at' => $decision->decided_at->toISOString(),
        ]], $decision->wasRecentlyCreated ? 201 : 200);
    }
}
