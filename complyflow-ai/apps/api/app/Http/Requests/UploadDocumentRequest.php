<?php

namespace App\Http\Requests;

use App\Models\Document;
use App\Models\Supplier;
use App\Rules\ValidPdfSignature;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;

class UploadDocumentRequest extends FormRequest
{
    private Supplier $resolvedSupplier;

    public function authorize(): bool
    {
        $publicId = (string) $this->route('supplier');
        abort_unless(Str::isUuid($publicId), 404);
        $this->resolvedSupplier = Supplier::query()->wherePublicIdForCurrentOrganization($publicId)->firstOrFail();

        if (! Gate::allows('create', [Document::class, $this->resolvedSupplier])) {
            return false;
        }

        $file = $this->file('file');
        abort_if($file instanceof UploadedFile && in_array($file->getError(), [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true),
            413, 'PDF exceeds the 5 MiB limit.');

        return true;
    }

    /** @return array<string, array<mixed>> */
    public function rules(): array
    {
        return ['file' => ['bail', 'required', 'file', new ValidPdfSignature]];
    }

    public function supplier(): Supplier
    {
        return $this->resolvedSupplier;
    }
}
