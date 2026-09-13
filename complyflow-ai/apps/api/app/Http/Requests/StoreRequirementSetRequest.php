<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreRequirementSetRequest extends FormRequest
{
    public function authorize(): bool
    {
        $permission = $this->isMethod('post') ? 'requirement.create' : 'requirement.update';

        return $this->user()?->hasPermission($permission) ?? false;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $required = $this->isMethod('post') ? 'required' : 'sometimes';

        return [
            'name' => [$required, 'string', 'max:255'],
            'requirements' => [$required, 'array', 'min:1'],
            'requirements.*.code' => ['required', 'string', 'max:255', 'distinct'],
            'requirements.*.title' => ['required', 'string', 'max:255'],
            'requirements.*.category' => ['required', 'string', 'max:255'],
            'requirements.*.weight' => ['sometimes', 'numeric', 'min:0', 'max:999.999'],
            'requirements.*.position' => ['sometimes', 'integer', 'min:0'],
            'requirements.*.evaluation_text' => ['required', 'string'],
            'requirements.*.is_required' => ['sometimes', 'boolean'],
        ];
    }
}
