<?php

namespace App\Services\Audit;

use App\Models\User;

final readonly class AuditEvent
{
    public function __construct(
        public User $actor,
        public string $action,
        public string $targetType,
        public string $targetId,
        public array $metadata,
    ) {}
}
