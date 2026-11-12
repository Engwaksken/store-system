<?php

namespace App\Http\Controllers;

use App\Models\ActivityLog;
use App\Models\AppSetting;
use App\Models\ExpenseCategory;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class SettingsController extends Controller
{
    public const KEYS = ['company_name', 'company_address', 'company_phone', 'company_email', 'voucher_footer'];

    public function edit()
    {
        Gate::authorize('manage-settings');

        return view('settings', [
            'values' => collect(self::KEYS)->mapWithKeys(fn ($k) => [$k => AppSetting::get($k)]),
            'expenseCategories' => ExpenseCategory::orderBy('name')->get(),
        ]);
    }

    public function update(Request $request)
    {
        Gate::authorize('manage-settings');
        $data = $request->validate([
            'company_name' => 'required|string|max:150',
            'company_address' => 'nullable|string|max:300',
            'company_phone' => 'nullable|string|max:50',
            'company_email' => 'nullable|email|max:150',
            'voucher_footer' => 'nullable|string|max:300',
            'logo' => 'nullable|image|mimes:png,jpg,jpeg|max:1024',
        ]);
        foreach (self::KEYS as $k) {
            AppSetting::put($k, $data[$k] ?? null);
        }
        if ($request->hasFile('logo')) {
            $path = $request->file('logo')->storeAs('branding', 'logo.'.$request->file('logo')->extension(), 'public');
            AppSetting::put('logo_path', $path);
        }
        ActivityLog::record('settings.updated', 'Company settings updated');

        return back()->with('status', 'Settings saved.');
    }

    public function storeExpenseCategory(Request $request)
    {
        Gate::authorize('manage-settings');
        $c = ExpenseCategory::create($request->validate(['name' => 'required|string|max:100|unique:expense_categories,name']));
        ActivityLog::record('settings.expense_category', "Expense category {$c->name} added", $c);

        return back()->with('status', 'Expense category added.');
    }
}
