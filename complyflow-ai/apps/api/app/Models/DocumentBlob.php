<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

#[Hidden(['id', 'organization_id', 'document_id', 'contents'])]
class DocumentBlob extends Model
{
    use BelongsToOrganization, HasUuids;

    /** @return array<int, string> */
    public function uniqueIds(): array
    {
        return ['public_id'];
    }
}
