<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\UploadDocumentRequest;
use App\Models\Document;
use App\Services\Documents\StorePdf;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;

class DocumentController extends Controller
{
    public function store(UploadDocumentRequest $request, StorePdf $storePdf): JsonResponse
    {
        $document = $storePdf->handle($request->supplier(), $request->file('file'));

        return response()->json(['data' => $this->data($document)], $document->wasRecentlyCreated ? 201 : 200);
    }

    public function show(string $document): JsonResponse
    {
        abort_unless(Str::isUuid($document), 404);
        $model = Document::query()->wherePublicIdForCurrentOrganization($document)->firstOrFail();
        Gate::authorize('view', $model);

        return response()->json(['data' => $this->data($model)]);
    }

    /** @return array<string, mixed> */
    private function data(Document $document): array
    {
        return [
            'id' => $document->public_id,
            'storage_name' => $document->storage_name,
            'mime_type' => $document->mime_type,
            'size_bytes' => $document->size_bytes,
            'sha256' => $document->sha256,
            'status' => $document->status,
        ];
    }
}
