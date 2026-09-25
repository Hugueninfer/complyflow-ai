<?php

namespace App\Http\Requests;

use App\Models\Supplier;
use App\Support\CurrentOrganization;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class StoreSupplierRequest extends FormRequest
{
    public function authorize(): bool
    {
        $permission = $this->isMethod('post') ? 'supplier.create' : 'supplier.update';

        return $this->user()?->hasPermission($permission) ?? false;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $required = $this->isMethod('post') ? 'required' : 'sometimes';
        $taxIdRules = ['sometimes', 'nullable', 'string', 'max:255'];
        $supplierId = (string) $this->route('supplier');
        if (! $this->isMethod('post')) {
            abort_unless(Str::isUuid($supplierId), 404);
        }
        $supplier = $this->isMethod('post')
            ? null
            : Supplier::query()
                ->wherePublicIdForCurrentOrganization($supplierId)
                ->first();

        if ($this->isMethod('post') || $supplier !== null) {
            $uniqueTaxId = Rule::unique('suppliers', 'tax_id')
                ->where(fn ($query) => $query->where(
                    'organization_id',
                    app(CurrentOrganization::class)->id(),
                ));

            if ($supplier !== null) {
                $uniqueTaxId->ignore($supplier->getKey());
            }

            $taxIdRules[] = $uniqueTaxId;
        }

        return [
            'name' => [$required, 'string', 'max:255'],
            'tax_id' => $taxIdRules,
            'risk_level' => ['sometimes', Rule::in(['low', 'medium', 'high'])],
        ];
    }
}
