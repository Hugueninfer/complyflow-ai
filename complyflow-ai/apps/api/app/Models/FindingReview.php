<?php

namespace App\Models;

use App\Models\Concerns\AppendOnly;
use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class FindingReview extends Model
{
    use AppendOnly, BelongsToOrganization, HasUuids;

    protected $guarded = ['id', 'public_id'];

    public function uniqueIds(): array
    {
        return ['public_id'];
    }

    protected function casts(): array
    {
        return ['reviewed_at' => 'immutable_datetime'];
    }
}
