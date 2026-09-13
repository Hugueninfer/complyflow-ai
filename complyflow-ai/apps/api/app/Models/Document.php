<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

#[Fillable(['supplier_id', 'original_name', 'storage_name', 'mime_type', 'size_bytes', 'sha256', 'status'])]
#[Hidden(['id', 'organization_id', 'supplier_id', 'original_name'])]
class Document extends Model
{
    use BelongsToOrganization, HasUuids, SoftDeletes;

    /** @return array<int, string> */
    public function uniqueIds(): array
    {
        return ['public_id'];
    }
}
