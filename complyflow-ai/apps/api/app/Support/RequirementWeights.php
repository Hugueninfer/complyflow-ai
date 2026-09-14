<?php

namespace App\Support;

use App\Models\RequirementSet;
use Illuminate\Support\Facades\Validator;

class RequirementWeights
{
    public static function rules(): array
    {
        // decimal(6,3): smaller positive values can round to zero on persistence.
        return ['numeric', 'min:0.001', 'max:999.999'];
    }

    public static function validate(RequirementSet $set): void
    {
        Validator::make(
            ['requirements' => $set->requirements()->forCurrentOrganization()->get(['weight'])->toArray()],
            ['requirements.*.weight' => ['required', ...self::rules()]],
        )->validate();
    }
}
