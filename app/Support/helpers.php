<?php

if (! function_exists('money')) {
    /** Format an amount with thousands separators in the company currency (decimals follow the currency). */
    function money(mixed $v): string
    {
        return \App\Support\Currency::format($v);
    }
}

if (! function_exists('ugx')) {
    /** Older name for money(), kept because it is used throughout the views. */
    function ugx(mixed $v): string
    {
        return money($v);
    }
}

if (! function_exists('currency')) {
    /** The company currency code chosen in Settings, e.g. UGX. */
    function currency(): string
    {
        return \App\Support\Currency::code();
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
