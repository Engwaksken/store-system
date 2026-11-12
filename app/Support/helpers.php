<?php

if (! function_exists('ugx')) {
    /** Format money as whole Uganda shillings. */
    function ugx(mixed $v): string
    {
        return number_format((float) ($v ?? 0), 0);
    }
}

if (! function_exists('qty')) {
    /** Format a quantity, trimming trailing zeros (12.500 -> 12.5). */
    function qty(mixed $v): string
    {
        $s = number_format((float) ($v ?? 0), 3, '.', ',');

        return str_contains($s, '.') ? rtrim(rtrim($s, '0'), '.') : $s;
    }
}
