<?php

namespace App\Http\Controllers;

use App\Models\AppSetting;
use App\Support\PwaIcon;
use App\Support\Theme;
use Illuminate\Support\Str;

/**
 * Installable web app (PWA) for phones and desktops until the store apps are published:
 * the web manifest and the home-screen icons, branded from Settings (company name, colours, favicon).
 * Public routes: browsers fetch these without the session cookie.
 */
class PwaController extends Controller
{
    public function manifest()
    {
        $name = rescue(fn () => AppSetting::get('company_name'), null, false) ?: config('app.name');
        $subtitle = rescue(fn () => AppSetting::get('app_subtitle'), null, false) ?: 'Store & Projects';
        $v = PwaIcon::version();
        $icon = fn (string $route, int $size, string $purpose) => [
            'src' => route($route, ['size' => $size, 'v' => $v], false),
            'sizes' => "{$size}x{$size}",
            'type' => 'image/png',
            'purpose' => $purpose,
        ];

        $manifest = [
            'id' => '/',
            'name' => $name,
            'short_name' => Str::limit($name, 12, ''),
            'description' => "{$name} · {$subtitle}",
            'start_url' => '/?source=pwa',
            'scope' => '/',
            'display' => 'standalone',
            'orientation' => 'any',
            'background_color' => '#FFFFFF',
            'theme_color' => Theme::values()['theme_primary'],
            'icons' => [
                $icon('pwa.icon', 192, 'any'),
                $icon('pwa.icon', 512, 'any'),
                $icon('pwa.maskable', 192, 'maskable'),
                $icon('pwa.maskable', 512, 'maskable'),
            ],
        ];

        return response()->json($manifest, 200, [
            'Content-Type' => 'application/manifest+json',
            'Cache-Control' => 'public, max-age=3600',
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    public function icon(int $size)
    {
        return $this->png($size, false);
    }

    public function maskable(int $size)
    {
        return $this->png($size, true);
    }

    /** iOS asks for /apple-touch-icon.png on its own as well as through the <link> tag. */
    public function appleTouchIcon()
    {
        return $this->png(180, false);
    }

    private function png(int $size, bool $maskable)
    {
        abort_unless(in_array($size, PwaIcon::SIZES, true), 404);

        return response(PwaIcon::png($size, $maskable), 200, [
            'Content-Type' => 'image/png',
            // URLs carry ?v=<source hash>, so a long cache is safe; the bare URL still refreshes within a day.
            'Cache-Control' => request()->has('v') ? 'public, max-age=31536000, immutable' : 'public, max-age=86400',
        ]);
    }
}
