<?php

namespace App\Support;

use App\Models\AppSetting;

/**
 * Look and feel chosen by the admin (Settings > Appearance): colours, font, text size, corners and sidebar style.
 * Rendered as CSS custom properties that override the defaults at the top of public/css/app.css.
 * Fonts are system font stacks only: the Content-Security-Policy allows fonts from this site alone.
 */
class Theme
{
    public const DEFAULTS = [
        'theme_primary' => '#12243E',   // sidebar, headings
        'theme_accent' => '#D4A33A',    // highlights, badges, active tab
        'theme_action' => '#0F766E',    // buttons and links
        'theme_font' => 'system',
        'theme_size' => 'normal',
        'theme_corners' => 'rounded',
        'theme_sidebar' => 'dark',
    ];

    public const FONTS = [
        'system' => ['System default (Segoe UI / Roboto)', 'Inter, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif'],
        'arial' => ['Arial / Helvetica', 'Arial, "Helvetica Neue", Helvetica, sans-serif'],
        'verdana' => ['Verdana (wide, very legible)', 'Verdana, Geneva, Tahoma, sans-serif'],
        'tahoma' => ['Tahoma', 'Tahoma, Verdana, "Segoe UI", sans-serif'],
        'trebuchet' => ['Trebuchet MS', '"Trebuchet MS", "Lucida Grande", "Segoe UI", sans-serif'],
        'calibri' => ['Calibri (Office style)', 'Calibri, Carlito, "Segoe UI", Arial, sans-serif'],
        'georgia' => ['Georgia (serif)', 'Georgia, "Times New Roman", Times, serif'],
    ];

    /** Built-in font stacks plus the admin's custom font, when one is installed. */
    public static function fonts(): array
    {
        $custom = CustomFont::current();

        return $custom ? self::FONTS + ['custom' => [$custom['family'].' (custom)', CustomFont::stack($custom['family'])]] : self::FONTS;
    }

    /** Stylesheet URL of the custom font when it is the active font, else null. */
    public static function fontCssUrl(): ?string
    {
        return self::values()['theme_font'] === 'custom' ? CustomFont::cssUrl() : null;
    }

    public const SIZES = ['small' => ['Small', '14px'], 'normal' => ['Normal', '15px'], 'large' => ['Large', '16px']];

    public const CORNERS = ['square' => ['Square', '2px', '2px'], 'rounded' => ['Rounded', '8px', '6px'], 'soft' => ['Soft', '14px', '10px']];

    public const SIDEBARS = ['dark' => 'Dark (primary colour)', 'light' => 'Light (white)'];

    /** @return array<string, string> current values, falling back to the defaults */
    public static function values(): array
    {
        $out = [];
        foreach (self::DEFAULTS as $key => $default) {
            $v = rescue(fn () => AppSetting::get($key), null, false);
            $out[$key] = self::valid($key, $v) ? $v : $default;
        }

        return $out;
    }

    public static function valid(string $key, ?string $v): bool
    {
        return match ($key) {
            'theme_primary', 'theme_accent', 'theme_action' => is_string($v) && preg_match('/^#[0-9a-fA-F]{6}$/', $v) === 1,
            'theme_font' => isset(self::fonts()[$v]),
            'theme_size' => isset(self::SIZES[$v]),
            'theme_corners' => isset(self::CORNERS[$v]),
            'theme_sidebar' => isset(self::SIDEBARS[$v]),
            default => false,
        };
    }

    public static function isDefault(): bool
    {
        return self::values() === self::DEFAULTS;
    }

    /** CSS overriding the stylesheet variables; empty when nothing was changed. Every value is validated above. */
    public static function css(): string
    {
        if (self::isDefault()) {
            return '';
        }
        $t = self::values();
        $vars = [
            '--navy' => $t['theme_primary'],
            '--navy-2' => self::mix($t['theme_primary'], '#FFFFFF', 0.09),
            '--gold' => $t['theme_accent'],
            '--on-gold' => self::readableOn($t['theme_accent'], $t['theme_primary']),
            '--teal' => $t['theme_action'],
            '--on-teal' => self::readableOn($t['theme_action'], '#1F2937'),
            '--font' => self::fonts()[$t['theme_font']][1],
            '--base-size' => self::SIZES[$t['theme_size']][1],
            '--radius' => self::CORNERS[$t['theme_corners']][1],
            '--radius-sm' => self::CORNERS[$t['theme_corners']][2],
        ];
        if ($t['theme_sidebar'] === 'light') {
            $vars += [
                '--side-bg' => '#FFFFFF', '--side-2' => self::mix($t['theme_primary'], '#FFFFFF', 0.92),
                '--side-text' => '#334155', '--side-muted' => '#64748B', '--side-strong' => $t['theme_primary'],
            ];
        } elseif (self::luminance($t['theme_primary']) > 0.45) {
            // A pale "dark" sidebar needs dark text to stay readable.
            $vars += ['--side-text' => '#1F2937', '--side-muted' => '#475569', '--side-strong' => '#0F172A'];
        }
        $body = implode(' ', array_map(fn ($k, $v) => "{$k}: {$v};", array_keys($vars), $vars));
        $extra = $t['theme_sidebar'] === 'light' ? ' .sidebar { border-right: 1px solid var(--line); }' : '';

        return ":root { {$body} }".$extra;
    }

    /** White text on dark colours, the given dark colour on light ones (WCAG relative luminance). */
    public static function readableOn(string $bg, string $dark): string
    {
        $contrastWhite = 1.05 / (self::luminance($bg) + 0.05);
        $contrastDark = (self::luminance($bg) + 0.05) / (self::luminance($dark) + 0.05);

        return $contrastWhite >= $contrastDark ? '#FFFFFF' : $dark;
    }

    public static function luminance(string $hex): float
    {
        [$r, $g, $b] = array_map(function ($c) {
            $c /= 255;

            return $c <= 0.03928 ? $c / 12.92 : (($c + 0.055) / 1.055) ** 2.4;
        }, sscanf($hex, '#%02x%02x%02x'));

        return 0.2126 * $r + 0.7152 * $g + 0.0722 * $b;
    }

    /** Blend $hex towards $with by $amount (0..1). */
    public static function mix(string $hex, string $with, float $amount): string
    {
        $a = sscanf($hex, '#%02x%02x%02x');
        $b = sscanf($with, '#%02x%02x%02x');

        return sprintf('#%02X%02X%02X', ...array_map(fn ($x, $y) => (int) round($x + ($y - $x) * $amount), $a, $b));
    }
}
