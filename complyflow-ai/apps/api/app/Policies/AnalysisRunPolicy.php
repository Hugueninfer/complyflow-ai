<?php

namespace App\Policies;

use App\Models\AnalysisRun;
use App\Models\Supplier;
use App\Models\User;
use App\Support\CurrentOrganization;

class AnalysisRunPolicy
{
    public function create(User $user, Supplier $supplier): bool
    {
        return $supplier->organization_id === app(CurrentOrganization::class)->id() && $user->hasPermission('analysis.run');
    }

    public function view(User $user, AnalysisRun $run): bool
    {
        return $run->organization_id === app(CurrentOrganization::class)->id() && $user->hasPermission('analysis.view');
    }
}
