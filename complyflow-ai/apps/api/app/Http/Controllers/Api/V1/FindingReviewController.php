<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\FindingReview;
use App\Services\Review\RecordFindingReview;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;

class FindingReviewController extends Controller
{
    public function store(Request $request, string $finding, RecordFindingReview $service)
    {
        Gate::authorize('create', FindingReview::class);
        $input = $request->validate([
            'status' => ['required', 'in:met,partial,missing,inconclusive'],
            'justification' => ['required', 'string', 'max:10000'],
            'note' => ['nullable', 'string', 'max:10000'],
        ]);
        $key = Validator::make(['key' => $request->header('Idempotency-Key')], ['key' => ['required', 'string', 'max:128', 'regex:/^[A-Za-z0-9._:-]+$/']])->validate()['key'];
        $review = $service->handle($request->user(), $finding, $input, $key);

        return response()->json(['data' => [
            'id' => $review->public_id, 'finding_id' => $finding,
            'status' => $review->status, 'justification' => $review->justification,
            'note' => $review->notes, 'reviewed_at' => $review->reviewed_at->toISOString(),
        ]], $review->wasRecentlyCreated ? 201 : 200);
    }
}
