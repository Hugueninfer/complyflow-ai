<?php

namespace App\Http\Requests;

use App\Models\AnalysisRun;
use App\Models\Supplier;
use App\Support\CurrentOrganization;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class StartAnalysisRequest extends FormRequest
{
    private Supplier $resolvedSupplier;

    protected function prepareForValidation(): void
    {
        $this->merge(['idempotency_key' => $this->header('Idempotency-Key')]);
    }

    public function authorize(): bool
    {
        $id = (string) $this->route('supplier');
        abort_unless(Str::isUuid($id), 404);
        $this->resolvedSupplier = Supplier::wherePublicIdForCurrentOrganization($id)->firstOrFail();

        return Gate::allows('create', [AnalysisRun::class, $this->resolvedSupplier]);
    }

    public function rules(): array
    {
        $tenant = app(CurrentOrganization::class)->id();

        return [
            'idempotency_key' => ['required', 'string', 'max:255', 'regex:/\A[A-Za-z0-9_.:-]+\z/D'],
            'requirement_set_id' => ['required', 'uuid', Rule::exists('requirement_sets', 'public_id')->where('organization_id', $tenant)->where('status', 'published')->whereNull('deleted_at')],
            'document_ids' => ['required', 'array', 'min:1', 'max:10'],
            'document_ids.*' => ['required', 'uuid', 'distinct', Rule::exists('documents', 'public_id')->where('organization_id', $tenant)->where('supplier_id', $this->resolvedSupplier->id)->whereNull('deleted_at')],
        ];
    }

    public function supplier(): Supplier
    {
        return $this->resolvedSupplier;
    }
}
