<?php

namespace App\Policies;

use App\Models\User;

class SupplierDecisionPolicy
{
    public function create(User $user): bool
    {
        return $user->hasPermission('supplier.decide');
    }
}
