<?php

namespace App\Policies;

use App\Models\RequirementSet;
use App\Models\User;

class RequirementSetPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermission('requirement.view');
    }

    public function view(User $user, RequirementSet $requirementSet): bool
    {
        return $user->hasPermission('requirement.view');
    }

    public function create(User $user): bool
    {
        return $user->hasPermission('requirement.create');
    }

    public function update(User $user, RequirementSet $requirementSet): bool
    {
        return $user->hasPermission('requirement.update');
    }

    public function publish(User $user, RequirementSet $requirementSet): bool
    {
        return $user->hasPermission('requirement.publish');
    }

    public function createVersion(User $user, RequirementSet $requirementSet): bool
    {
        return $user->hasPermission('requirement.create');
    }

    public function delete(User $user, RequirementSet $requirementSet): bool
    {
        return $user->hasPermission('requirement.update');
    }
}
