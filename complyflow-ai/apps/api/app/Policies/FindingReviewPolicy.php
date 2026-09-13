<?php

namespace App\Policies;

use App\Models\User;

class FindingReviewPolicy
{
    public function create(User $user): bool
    {
        return $user->hasPermission('finding.review');
    }
}
