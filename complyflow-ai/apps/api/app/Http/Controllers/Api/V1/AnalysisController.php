<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\StartAnalysisRequest;
use App\Models\AnalysisRun;
use App\Services\Analysis\StartAnalysis;
use Illuminate\Support\Facades\Gate;
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

    private function resource(AnalysisRun $run): array
    {
        return ['id' => $run->public_id, 'status' => $run->status, 'attempts' => $run->attempts, 'progress' => $run->progress,
            'error_code' => $run->error_code, 'error_message' => $run->error_message,
            'started_at' => $run->started_at, 'completed_at' => $run->completed_at, 'created_at' => $run->created_at];
    }
}
