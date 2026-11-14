<?php

namespace App\Http\Controllers;

use App\Models\ActivityLog;
use App\Models\CostCode;
use App\Support\ListPage;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

class CostCodeController extends Controller
{
    public function index(Request $request)
    {
        Gate::authorize('view-cost-codes');
        $stats = [
            'total' => CostCode::count(),
            'active' => CostCode::where('is_active', true)->count(),
            'inactive' => CostCode::where('is_active', false)->count(),
            'categories' => CostCode::distinct()->count('category'),
        ];
        $query = CostCode::query()
            ->when($request->filled('category'), fn ($q) => $q->where('category', $request->query('category')))
            ->when($request->query('status') === 'active', fn ($q) => $q->where('is_active', true))
            ->when($request->query('status') === 'inactive', fn ($q) => $q->where('is_active', false))
            ->orderBy('code');

        return ListPage::make($request, $query, 'Cost codes')
            ->search(['code', 'name'], 'Code or name')->perPage(50)
            ->columns([
                'Code' => fn ($c) => $c->code, 'Name' => fn ($c) => $c->name,
                'Category' => fn ($c) => CostCode::CATEGORIES[$c->category] ?? $c->category, 'Status' => fn ($c) => $c->is_active ? 'Active' : 'Inactive',
            ])
            ->respond(fn ($codes, $list) => view('cost-codes.index', ['codes' => $codes, 'list' => $list, 'stats' => $stats]));
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
