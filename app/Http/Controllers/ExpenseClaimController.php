<?php

namespace App\Http\Controllers;

use App\Enums\Role;
use App\Models\CostCode;
use App\Models\Expense;
use App\Models\ExpenseCategory;
use App\Models\ExpenseClaim;
use App\Models\Project;
use App\Services\ExpenseClaimService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;

class ExpenseClaimController extends Controller
{
    public function index(Request $request)
    {
        $claims = ExpenseClaim::visibleTo($request->user())->with('project', 'claimant', 'category')
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->status))
            ->latest('id')->paginate(25)->withQueryString();

        return view('claims.index', compact('claims'));
    }

    public function create(Request $request)
    {
        Gate::authorize('submit-claim');

        return view('claims.create', $this->options($request));
    }

    public function store(Request $request, ExpenseClaimService $service)
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
        if ($request->hasFile('receipt')) {
            $data['receipt_path'] = $request->file('receipt')->store('claim-receipts', 'local');
        }
        $claim = $service->submit($request->user(), $data);

        return redirect()->route('claims.show', $claim)->with('status', "Claim {$claim->claim_no} submitted for approval.");
    }

    public function show(ExpenseClaim $claim)
    {
        Gate::authorize('view-claim', $claim);

        return view('claims.show', ['claim' => $claim->load('project', 'claimant', 'category', 'costCode', 'approver', 'expense')]);
    }

    public function decide(Request $request, ExpenseClaim $claim, ExpenseClaimService $service)
    {
        Gate::authorize('decide-claim', $claim);
        $data = $request->validate(['decision' => 'required|in:approved,rejected', 'reason' => 'nullable|required_if:decision,rejected|string|max:1000']);
        $service->decide($request->user(), $claim, $data['decision'], $data['reason'] ?? null);

        return back()->with('status', 'Claim '.$data['decision'].'.');
    }

    public function pay(Request $request, ExpenseClaim $claim, ExpenseClaimService $service)
    {
        Gate::authorize('pay-claim', $claim);
        $data = $request->validate(['payment_mode' => ['required', Rule::in(Expense::PAYMENT_MODES)], 'reference' => 'nullable|string|max:100']);
        $service->pay($request->user(), $claim, $data['payment_mode'], $data['reference'] ?? null);

        return back()->with('status', 'Payment recorded; the claim now appears once in project expenses.');
    }

    public function receipt(ExpenseClaim $claim)
    {
        Gate::authorize('view-claim', $claim);
        abort_unless($claim->receipt_path && Storage::disk('local')->exists($claim->receipt_path), 404);

        return Storage::disk('local')->download($claim->receipt_path);
    }

    private function options(Request $request): array
    {
        $user = $request->user();
        $projects = $user->hasRole(Role::Admin, Role::Accountant, Role::OverallManager)
            ? Project::whereIn('status', ['planned', 'active', 'on_hold'])->orderBy('code')->get()
            : Project::requestableBy($user)->orderBy('code')->get();

        return [
            'projects' => $projects,
            'categories' => ExpenseCategory::where('is_active', true)->orderBy('name')->get(),
            'costCodes' => CostCode::where('is_active', true)->orderBy('code')->get(),
        ];
    }
}
