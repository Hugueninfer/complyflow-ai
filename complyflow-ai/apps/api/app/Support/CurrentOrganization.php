<?php

namespace App\Support;

use App\Models\Organization;
use LogicException;

class CurrentOrganization
{
    private ?int $organizationId = null;

    public function set(Organization|int $organization): void
    {
        $this->organizationId = $organization instanceof Organization
            ? (int) $organization->getKey()
            : $organization;
    }

    public function id(): int
    {
        if ($this->organizationId === null) {
            throw new LogicException('Current organization has not been resolved.');
        }

        return $this->organizationId;
    }

    public function has(): bool
    {
        return $this->organizationId !== null;
    }

    public function clear(): void
    {
        $this->organizationId = null;
    }
}
