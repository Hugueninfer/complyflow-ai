<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
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

        return [
            'name' => [$required, 'string', 'max:255'],
            'tax_id' => ['sometimes', 'nullable', 'string', 'max:255'],
            'risk_level' => ['sometimes', Rule::in(['low', 'medium', 'high'])],
        ];
    }
}
