<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'code',
    'title',
    'category',
    'weight',
    'position',
    'evaluation_text',
    'is_required',
])]
class Requirement extends Model
{
    use BelongsToOrganization, HasUuids;

    /** @return array<int, string> */
    public function uniqueIds(): array
    {
        return ['public_id'];
    }

    /** @return BelongsTo<RequirementSet, $this> */
    public function requirementSet(): BelongsTo
    {
        return $this->belongsTo(RequirementSet::class);
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'weight' => 'decimal:3',
            'position' => 'integer',
            'is_required' => 'boolean',
        ];
    }
}
