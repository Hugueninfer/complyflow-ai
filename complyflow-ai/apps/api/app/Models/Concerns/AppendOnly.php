<?php

namespace App\Models\Concerns;

use LogicException;

trait AppendOnly
{
    protected static function bootAppendOnly(): void
    {
        static::updating(fn () => throw new LogicException('History records are append-only.'));
        static::deleting(fn () => throw new LogicException('History records are append-only.'));
    }
}
