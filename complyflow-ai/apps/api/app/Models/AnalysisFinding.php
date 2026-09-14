<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class AnalysisFinding extends Model
{
    use BelongsToOrganization, HasUuids;

    protected $guarded = ['id', 'public_id'];

    public function uniqueIds(): array
    {
        return ['public_id'];
    }
}
