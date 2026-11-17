<?php

namespace App\Http\Controllers\Api;

use App\Models\AppSetting;
use Illuminate\Support\Facades\Storage;

class BrandingController
{
    public function __invoke()
    {
        $url = fn ($path) => $path ? url(Storage::disk('public')->url($path)) : null;
        $theme = \App\Support\Theme::values();

        return response()->json([
            'company_name' => AppSetting::get('company_name') ?: config('app.name'),
            'app_subtitle' => AppSetting::get('app_subtitle') ?: 'Store & Projects',
            'logo_url' => $url(AppSetting::get('logo_path')),
            'favicon_url' => $url(AppSetting::get('favicon_path')),
            // Settings > Appearance colours (#RRGGBB), so the app matches the web.
            'theme' => [
                'primary' => $theme['theme_primary'],
                'accent' => $theme['theme_accent'],
                'action' => $theme['theme_action'],
            ],
        ]);
    }
}
