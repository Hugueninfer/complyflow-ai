<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\StartAnalysisRequest;
use App\Models\AnalysisRun;
use App\Models\RequirementSet;
use App\Models\Supplier;
use App\Services\Analysis\StartAnalysis;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

class AnalysisController extends Controller
{
    public function store(StartAnalysisRequest $request)
    {
        $run = app(StartAnalysis::class)->handle($request->supplier(), $request->validated('requirement_set_id'), $request->validated('document_ids'), $request->validated('idempotency_key'));

        return response()->json(['data' => $this->resource($run->fresh())], $run->wasRecentlyCreated ? 202 : 200);
    }

    public function show(string $analysis)
    {
        abort_unless(Str::isUuid($analysis), 404);
        $run = AnalysisRun::wherePublicIdForCurrentOrganization($analysis)->firstOrFail();
        Gate::authorize('view', $run);

        return response()->json(['data' => $this->resource($run)]);
    }

    public function retry(Request $request, string $analysis)
    {
        abort_unless(Str::isUuid($analysis), 404);
        $original = AnalysisRun::wherePublicIdForCurrentOrganization($analysis)->firstOrFail();
        $supplier = Supplier::forCurrentOrganization()->whereKey($original->supplier_id)->firstOrFail();
        Gate::authorize('create', [AnalysisRun::class, $supplier]);
        abort_unless($original->status === 'failed', 409);
        $key = Validator::make(['key' => $request->header('Idempotency-Key')], ['key' => ['required', 'string', 'max:128', 'regex:/^[A-Za-z0-9._:-]+$/']])->validate()['key'];
        $set = RequirementSet::forCurrentOrganization()->whereKey($original->requirement_set_id)->firstOrFail();
        Validator::make(['document_ids' => $original->document_ids], [
            'document_ids' => ['required', 'array', 'min:1', 'max:10'],
            'document_ids.*' => ['required', 'uuid', 'distinct'],
        ])->validate();
        // Namespace retries to their immutable source, so the original key cannot resurrect it.
        $run = app(StartAnalysis::class)->handle($supplier, $set->public_id, $original->document_ids ?? [], 'retry:'.$original->public_id.':'.$key);

        return response()->json(['data' => $this->resource($run->fresh())], $run->wasRecentlyCreated ? 202 : 200);
    }

    private function resource(AnalysisRun $run): array
    {
        return ['id' => $run->public_id, 'status' => $run->status, 'attempts' => $run->attempts, 'progress' => $run->progress,
            'error_code' => $run->error_code, 'error_message' => $run->error_message,
            'started_at' => $run->started_at, 'completed_at' => $run->completed_at, 'created_at' => $run->created_at];
    }
}
