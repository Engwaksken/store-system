<?php

namespace App\Models\Concerns;

use LogicException;

/** Append-only records: updates and deletes are refused at model level. */
trait Immutable
{
    protected static function bootImmutable(): void
    {
        static::updating(fn ($m) => throw new LogicException(class_basename($m).' records are immutable.'));
        static::deleting(fn ($m) => throw new LogicException(class_basename($m).' records cannot be deleted.'));
    }
}
