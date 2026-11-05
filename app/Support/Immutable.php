<?php

namespace App\Support;

use LogicException;

/** Rows that may be inserted but never changed or removed (approval decisions, rule lifecycle history). */
trait Immutable
{
    public static function bootImmutable(): void
    {
        static::updating(fn () => throw new LogicException(static::class . ' rows are immutable.'));
        static::deleting(fn () => throw new LogicException(static::class . ' rows are immutable.'));
    }
}
