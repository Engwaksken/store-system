<?php

namespace App\Support;

use App\Models\AppSetting;

/**
 * The company's reporting currency, chosen by the admin in Settings.
 * Changing it relabels and reformats amounts; it does not convert stored values.
 */
class Currency
{
    public const DEFAULT = 'UGX';

    /** code => [name, decimal places] */
    public const LIST = [
        'UGX' => ['Ugandan shilling', 0],
        'KES' => ['Kenyan shilling', 2],
        'TZS' => ['Tanzanian shilling', 0],
        'RWF' => ['Rwandan franc', 0],
        'BIF' => ['Burundian franc', 0],
        'SSP' => ['South Sudanese pound', 2],
        'CDF' => ['Congolese franc', 2],
        'ETB' => ['Ethiopian birr', 2],
        'ZAR' => ['South African rand', 2],
        'NGN' => ['Nigerian naira', 2],
        'GHS' => ['Ghanaian cedi', 2],
        'USD' => ['US dollar', 2],
        'EUR' => ['Euro', 2],
        'GBP' => ['British pound', 2],
        'AED' => ['UAE dirham', 2],
        'CNY' => ['Chinese yuan', 2],
        'INR' => ['Indian rupee', 2],
    ];

    public static function code(): string
    {
        $code = rescue(fn () => AppSetting::get('currency'), null, false);

        return isset(self::LIST[$code]) ? $code : self::DEFAULT;
    }

    public static function decimals(): int
    {
        return self::LIST[self::code()][1];
    }

    public static function format(mixed $v): string
    {
        return number_format((float) ($v ?? 0), self::decimals());
    }
}
