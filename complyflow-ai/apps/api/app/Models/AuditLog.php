<?php

namespace App\Models;

use App\Models\Concerns\AppendOnly;
use App\Models\Concerns\BelongsToOrganization;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class AuditLog extends Model
{
    use AppendOnly, BelongsToOrganization, HasUuids;

    protected $guarded = ['id'];

    public function uniqueIds(): array
    {
        return ['public_id'];
    }

    protected function casts(): array
    {
        return ['metadata' => 'array', 'occurred_at' => 'immutable_datetime'];
    }

    public function setOccurredAtAttribute(mixed $value): void
    {
        $this->attributes['occurred_at'] = CarbonImmutable::parse($value)->utc()->format('Y-m-d H:i:s');
    }
}
