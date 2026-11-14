<?php

namespace App\Http\Controllers\Api;

use App\Enums\Role;
use App\Models\CostCode;
use App\Models\ExpenseCategory;
use App\Models\ExpenseClaim;
use App\Models\Project;
use App\Services\ExpenseClaimService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;

class ClaimController extends ApiController
{
    public function index(Request $request): JsonResponse
    {
        $query = ExpenseClaim::visibleTo($request->user())->with('project', 'claimant', 'category', 'costCode')
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->query('status')))
            ->when($request->filled('project_id'), fn ($q) => $q->where('project_id', $request->integer('project_id')))
            ->when($request->filled('q'), fn ($q) => $q->where(fn ($w) => $w->where('claim_no', 'like', $this->like($request->query('q')))
                ->orWhere('description', 'like', $this->like($request->query('q')))))
            ->latest('id');

        return $this->paginated($request, $query, fn ($c) => Present::claim($c, $request->user()));
    }

    public function show(Request $request, ExpenseClaim $claim): JsonResponse
    {
        Gate::authorize('view-claim', $claim);

        return response()->json(['data' => Present::claim($claim->load('project', 'claimant', 'category', 'costCode'), $request->user())]);
    }

    public function receipt(ExpenseClaim $claim)
    {
        Gate::authorize('view-claim', $claim);
        abort_unless($claim->receipt_path && Storage::disk('local')->exists($claim->receipt_path), 404);

        return Storage::disk('local')->download($claim->receipt_path);
    }

    public function options(Request $request): JsonResponse
    {
        Gate::authorize('submit-claim');
        $user = $request->user();
        $projects = $user->hasRole(Role::Admin, Role::Accountant, Role::OverallManager)
            ? Project::whereIn('status', ['planned', 'active', 'on_hold'])->orderBy('code')->get()
            : Project::requestableBy($user)->orderBy('code')->get();

        return response()->json([
            'projects' => $projects->map(fn ($p) => Present::project($p))->values(),
            'categories' => ExpenseCategory::where('is_active', true)->orderBy('name')->get(['id', 'name']),
            'cost_codes' => CostCode::where('is_active', true)->orderBy('code')->get(['id', 'code', 'name']),
        ]);
    }

    public function store(Request $request, ExpenseClaimService $service): JsonResponse
    {
        Gate::authorize('submit-claim');
        $data = $request->validate([
            'project_id' => 'required|integer|exists:projects,id',
            'category_id' => ['required', Rule::exists('expense_categories', 'id')->where('is_active', true)],
            'cost_code_id' => ['nullable', Rule::exists('cost_codes', 'id')->where('is_active', true)],
            'incurred_on' => 'required|date|before_or_equal:today',
            'amount' => 'required|numeric|gt:0|max:9999999999999',
            'description' => 'required|string|max:1000',
            'receipt' => 'nullable|file|mimes:pdf,jpg,jpeg,png|max:4096',
        ]);
        unset($data['receipt']);
        if ($request->hasFile('receipt')) {
            $data['receipt_path'] = $request->file('receipt')->store('claim-receipts', 'local');
        }
        $claim = $service->submit($request->user(), $data);

        return response()->json(['data' => Present::claim($claim->fresh()->load('project', 'claimant', 'category', 'costCode'), $request->user())], 201);
    }

    public function decide(Request $request, ExpenseClaim $claim, ExpenseClaimService $service): JsonResponse
    {
        Gate::authorize('view-claim', $claim);
        $data = $request->validate([
            'decision' => 'required|in:approved,rejected',
            'reason' => 'nullable|required_if:decision,rejected|string|max:1000',
            'seen_at' => 'nullable|string',
        ]);
        $service->decide($request->user(), $claim, $data['decision'], $data['reason'] ?? null, $data['seen_at'] ?? null);

        return response()->json(['data' => Present::claim($claim->fresh()->load('project', 'claimant', 'category', 'costCode'), $request->user())]);
    }
}
