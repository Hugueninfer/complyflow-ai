<?php

namespace App\Http\Requests;

use App\Models\RequirementSet;
use App\Support\CurrentOrganization;
use App\Support\RequirementWeights;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreRequirementSetRequest extends FormRequest
{
    private ?RequirementSet $targetSet = null;

    public function authorize(): bool
    {
        if ($this->isMethod('post')) {
            return $this->user()?->hasPermission('requirement.create') ?? false;
        }

        $set = $this->targetSet();

        if (! ($this->user()?->hasPermission('requirement.update') ?? false)) {
            return false;
        }

        if ($set->status !== 'draft') {
            abort(409, 'Published requirement sets are immutable.');
        }

        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $required = $this->isMethod('post') ? 'required' : 'sometimes';
        $nameRules = [$required, 'string', 'max:255'];
        $set = $this->isMethod('post') ? null : $this->targetSet();

        if ($this->isMethod('post') || $set !== null) {
            $version = $set?->version ?? 1;
            $uniqueName = Rule::unique('requirement_sets', 'name')
                ->where(fn ($query) => $query
                    ->where('organization_id', app(CurrentOrganization::class)->id())
                    ->where('version', $version));

            if ($set !== null) {
                $uniqueName->ignore($set->getKey());
            }

            $nameRules[] = $uniqueName;
        }

        return [
            'name' => $nameRules,
            'requirements' => [$required, 'array', 'min:1'],
            'requirements.*.code' => ['required', 'string', 'max:255', 'distinct'],
            'requirements.*.title' => ['required', 'string', 'max:255'],
            'requirements.*.category' => ['required', 'string', 'max:255'],
            'requirements.*.weight' => ['sometimes', ...RequirementWeights::rules()],
            'requirements.*.position' => ['sometimes', 'integer', 'min:0'],
            'requirements.*.evaluation_text' => ['required', 'string'],
            'requirements.*.is_required' => ['sometimes', 'boolean'],
        ];
    }

    private function targetSet(): RequirementSet
    {
        return $this->targetSet ??= RequirementSet::query()
            ->wherePublicIdForCurrentOrganization((string) $this->route('requirementSet'))
            ->firstOrFail();
    }
}
