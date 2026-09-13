<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class AnalysisRun extends Model
{
    use BelongsToOrganization, HasUuids;

    protected $guarded = ['id', 'public_id'];

    protected $hidden = ['id', 'organization_id', 'supplier_id', 'requirement_set_id', 'idempotency_key', 'document_ids', 'document_set_hash'];

    public function uniqueIds(): array
    {
        return ['public_id'];
    }

    protected function casts(): array
    {
        return ['document_ids' => 'array', 'attempts' => 'integer', 'progress' => 'integer', 'started_at' => 'immutable_datetime', 'completed_at' => 'immutable_datetime'];
    }
}
