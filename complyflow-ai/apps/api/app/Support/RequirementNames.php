<?php

namespace App\Support;

use App\Models\Organization;
use App\Models\RequirementSet;
use Illuminate\Validation\ValidationException;

class RequirementNames
{
    public static function lockOrganization(): void
    {
        // Always before checklist locks. Compatible with FK KEY SHARE checks
        // and the same tenant writer order used by analyses and decisions.
        Organization::whereKey(app(CurrentOrganization::class)->id())
            ->lock('for no key update')->firstOrFail();
    }

    public static function validate(string $name, ?RequirementSet $set = null): void
    {
        // Exact, case-sensitive database equality; deleted versions reserve names.
        $owners = RequirementSet::withTrashed()->forCurrentOrganization()->where('name', $name);
        if ($set !== null) {
            $owners->whereRaw('COALESCE(parent_id, id) <> ?', [$set->parent_id ?? $set->id]);
        }
        if ($owners->exists()) {
            throw ValidationException::withMessages([
                'name' => ['This name belongs to another requirement set lineage in this organization.'],
            ]);
        }
    }
}
