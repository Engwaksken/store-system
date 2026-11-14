<?php

namespace App\Http\Controllers;

use App\Models\ActivityLog;
use App\Models\AppSetting;
use App\Models\ExpenseCategory;
use App\Support\Currency;
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
        ]);
        $before = Currency::code();
        $data['currency'] ??= $before;
        foreach (self::KEYS as $k) {
            AppSetting::put($k, $data[$k] ?? null);
        }
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
        ActivityLog::record('settings.updated', 'Company settings updated'.($before !== $data['currency'] ? " (currency {$before} -> {$data['currency']})" : ''));

        return back()->with('status', 'Settings saved.');
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
