<?php

namespace App\Services\Audit;

use App\Models\AuditLog;

final class AuditHash
{
    public function make(AuditLog $log): string
    {
        return hash('sha256', self::canonicalJson([
            'event_id' => $log->public_id,
            'organization_id' => $log->organization_public_id,
            'actor_id' => $log->actor_public_id,
            'action' => $log->action,
            'target_type' => $log->target_type,
            'target_id' => $log->target_public_id,
            'occurred_at' => $log->occurred_at->utc()->format('Y-m-d\TH:i:s\Z'),
            'payload' => $log->metadata,
            'previous_hash' => $log->previous_hash,
        ]));
    }

    public function verifyChain(iterable $logs): bool
    {
        $previous = null;
        $tenant = null;
        foreach ($logs as $log) {
            $tenant ??= $log->organization_public_id;
            if ($log->organization_public_id !== $tenant || $log->previous_hash !== $previous || ! hash_equals($log->event_hash, $this->make($log))) {
                return false;
            }
            $previous = $log->event_hash;
        }

        return true;
    }

    public static function canonicalJson(mixed $value): string
    {
        return json_encode(self::sort($value), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    private static function sort(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }
        if (! array_is_list($value)) {
            ksort($value, SORT_STRING);
        }

        return array_map(self::sort(...), $value);
    }
}
