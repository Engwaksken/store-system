<?php

namespace App\Http\Controllers;

use App\Models\ActivityLog;
use App\Models\CostCode;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

class CostCodeController extends Controller
{
    public function index()
    {
        Gate::authorize('view-cost-codes');

        return view('cost-codes.index', ['codes' => CostCode::orderBy('code')->get()]);
    }

    public function store(Request $request)
    {
        Gate::authorize('manage-cost-codes');
        $data = $request->validate([
            'code' => 'required|string|max:20|unique:cost_codes,code',
            'name' => 'required|string|max:150',
            'category' => ['required', Rule::in(array_keys(CostCode::CATEGORIES))],
        ]);
        $c = CostCode::create($data);
        ActivityLog::record('costcode.created', "Cost code {$c->code} added", $c);

        return back()->with('status', "Cost code {$c->code} added.");
    }

    public function update(Request $request, CostCode $costCode)
    {
        Gate::authorize('manage-cost-codes');
        $data = $request->validate([
            'name' => 'required|string|max:150',
            'category' => ['required', Rule::in(array_keys(CostCode::CATEGORIES))],
        ]);
        $costCode->update($data + ['is_active' => $request->boolean('is_active')]);
        ActivityLog::record('costcode.updated', "Cost code {$costCode->code} updated", $costCode);

        return back()->with('status', "Cost code {$costCode->code} updated.");
    }
}
