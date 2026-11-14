<?php

namespace App\Http\Controllers;

use App\Models\ActivityLog;
use App\Models\AppSetting;
use App\Models\ExpenseCategory;
use App\Support\Currency;
use App\Support\CustomFont;
use App\Support\Theme;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;

class SettingsController extends Controller
{
    public const KEYS = ['company_name', 'app_subtitle', 'company_address', 'company_phone', 'company_email', 'voucher_footer', 'po_owner_threshold', 'currency'];

    public function edit()
    {
        Gate::authorize('manage-settings');

        return view('settings', [
            'values' => collect(self::KEYS)->mapWithKeys(fn ($k) => [$k => AppSetting::get($k)]),
            'expenseCategories' => ExpenseCategory::orderBy('name')->get(),
            'currentCurrency' => Currency::code(),
            'theme' => Theme::values(),
            'customFont' => CustomFont::current(),
        ]);
    }

    public function update(Request $request)
    {
        Gate::authorize('manage-settings');
        $data = $request->validate([
            'company_name' => 'required|string|max:150',
            'app_subtitle' => 'nullable|string|max:60',
            'company_address' => 'nullable|string|max:300',
            'company_phone' => 'nullable|string|max:50',
            'company_email' => 'nullable|email|max:150',
            'voucher_footer' => 'nullable|string|max:300',
            'po_owner_threshold' => 'nullable|numeric|min:0',
            'currency' => ['nullable', Rule::in(array_keys(Currency::LIST))],
            'logo' => 'nullable|image|mimes:png,jpg,jpeg|max:1024',
            'favicon' => 'nullable|file|mimes:png,ico|max:256',
            'remove_logo' => 'nullable|boolean',
            'remove_favicon' => 'nullable|boolean',
            'theme_primary' => ['nullable', 'regex:/^#[0-9a-fA-F]{6}$/'],
            'theme_accent' => ['nullable', 'regex:/^#[0-9a-fA-F]{6}$/'],
            'theme_action' => ['nullable', 'regex:/^#[0-9a-fA-F]{6}$/'],
            'theme_font' => ['nullable', Rule::in(array_keys(Theme::fonts()))],
            'theme_size' => ['nullable', Rule::in(array_keys(Theme::SIZES))],
            'theme_corners' => ['nullable', Rule::in(array_keys(Theme::CORNERS))],
            'theme_sidebar' => ['nullable', Rule::in(array_keys(Theme::SIDEBARS))],
            'reset_theme' => 'nullable|boolean',
        ]);
        $before = Currency::code();
        $data['currency'] ??= $before;
        foreach (self::KEYS as $k) {
            AppSetting::put($k, $data[$k] ?? null);
        }
        $themeBefore = Theme::values();
        foreach (array_keys(Theme::DEFAULTS) as $k) {
            if ($request->boolean('reset_theme')) {
                AppSetting::put($k, null);
            } elseif (! empty($data[$k])) {
                AppSetting::put($k, str_starts_with($data[$k], '#') ? strtoupper($data[$k]) : $data[$k]);
            }
        }
        $themeChanged = $themeBefore !== Theme::values();
        foreach (['logo', 'favicon'] as $asset) {
            if ($request->hasFile($asset)) {
                // A new file name on every upload, so browsers do not keep showing the old cached image.
                $path = $request->file($asset)->storeAs('branding', $asset.'-'.now()->format('YmdHis').'.'.$request->file($asset)->extension(), 'public');
                $this->removeAsset($asset.'_path');
                AppSetting::put($asset.'_path', $path);
            } elseif ($request->boolean('remove_'.$asset)) {
                $this->removeAsset($asset.'_path');
                AppSetting::put($asset.'_path', null);
            }
        }
        ActivityLog::record('settings.updated', 'Company settings updated'
            .($before !== $data['currency'] ? " (currency {$before} -> {$data['currency']})" : '')
            .($themeChanged ? ($request->boolean('reset_theme') ? ' (appearance reset to default)' : ' (appearance changed)') : ''));

        return back()->with('status', 'Settings saved.');
    }

    /** Add a custom font family: fetched from Google Fonts by name, or uploaded files. Replaces any previous custom font. */
    public function storeFont(Request $request)
    {
        Gate::authorize('manage-settings');
        $data = $request->validate([
            'source' => 'required|in:google,upload',
            'family' => ['required', 'string', 'regex:'.CustomFont::NAME_PATTERN],
            'regular' => 'required_if:source,upload|nullable|file|extensions:woff2,woff,ttf|max:2048',
            'bold' => 'nullable|file|extensions:woff2,woff,ttf|max:2048',
            'use_now' => 'nullable|boolean',
        ], ['family.regex' => 'Use letters, digits and spaces only for the font name (e.g. Lato, Open Sans).']);

        try {
            $data['source'] === 'google'
                ? CustomFont::installFromGoogle($data['family'])
                : CustomFont::installFromUpload($data['family'], $request->file('regular'), $request->file('bold'));
        } catch (\RuntimeException $e) {
            return back()->withInput()->withErrors(['family' => $e->getMessage()]);
        } catch (\Illuminate\Http\Client\ConnectionException) {
            return back()->withInput()->withErrors(['family' => 'The server could not reach Google Fonts. Upload the font files instead.']);
        }
        if ($request->boolean('use_now', true)) {
            AppSetting::put('theme_font', 'custom');
        }
        ActivityLog::record('settings.font', "Custom font {$data['family']} added (".($data['source'] === 'google' ? 'Google Fonts' : 'uploaded').')');

        return redirect()->to(route('settings.edit').'#appearance')->with('status', "Font {$data['family']} added".($request->boolean('use_now', true) ? ' and applied.' : '.'));
    }

    public function destroyFont()
    {
        Gate::authorize('manage-settings');
        $font = CustomFont::current();
        CustomFont::remove();
        if ($font) {
            ActivityLog::record('settings.font', "Custom font {$font['family']} removed");
        }

        return redirect()->to(route('settings.edit').'#appearance')->with('status', 'Custom font removed. The system font is used instead.');
    }

    private function removeAsset(string $key): void
    {
        $old = AppSetting::get($key);
        if ($old && str_starts_with($old, 'branding/')) {
            Storage::disk('public')->delete($old);
        }
    }

    public function storeExpenseCategory(Request $request)
    {
        Gate::authorize('manage-settings');
        $c = ExpenseCategory::create($request->validate(['name' => 'required|string|max:100|unique:expense_categories,name']));
        ActivityLog::record('settings.expense_category', "Expense category {$c->name} added", $c);

        return back()->with('status', 'Expense category added.');
    }
}
