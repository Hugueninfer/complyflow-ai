<?php

namespace App\Policies;

use App\Models\Document;
use App\Models\Supplier;
use App\Models\User;
use App\Support\CurrentOrganization;

class DocumentPolicy
{
    public function viewAny(User $user, Supplier $supplier): bool
    {
        return $supplier->organization_id === app(CurrentOrganization::class)->id()
            && $user->hasPermission('document.view');
    }

    public function create(User $user, Supplier $supplier): bool
    {
        return $supplier->organization_id === app(CurrentOrganization::class)->id()
            && $user->hasPermission('document.upload');
    }

    public function view(User $user, Document $document): bool
    {
        return $document->organization_id === app(CurrentOrganization::class)->id()
            && $user->hasPermission('document.view');
    }
}
