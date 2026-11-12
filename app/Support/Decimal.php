<?php

namespace App\Support;

use App\Exceptions\WorkflowException;

/** Exact decimal helpers (bcmath) so quantities and money never suffer float rounding. */
final class Decimal
{
    public static function of(mixed $value, int $scale = 3): string
    {
        $s = trim((string) $value);
        if (! preg_match('/^-?\d+(\.\d+)?$/', $s)) {
            throw new WorkflowException("Invalid number: {$s}");
        }

        return bcadd($s, '0', $scale);
    }

    public static function cmp(string|int|float|null $a, string|int|float|null $b, int $scale = 3): int
    {
        return bccomp((string) ($a ?? 0), (string) ($b ?? 0), $scale);
    }
}
