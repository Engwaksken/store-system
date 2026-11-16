<?php

namespace App\Http\Controllers\Api;

use App\Models\AppSetting;
use Illuminate\Support\Facades\Storage;

class BrandingController
{
    public function __invoke()
    {
        $url = fn ($path) => $path ? url(Storage::disk('public')->url($path)) : null;

        return response()->json([
            'company_name' => AppSetting::get('company_name') ?: config('app.name'),
            'logo_url' => $url(AppSetting::get('logo_path')),
            'favicon_url' => $url(AppSetting::get('favicon_path')),
        ]);
    }
}
