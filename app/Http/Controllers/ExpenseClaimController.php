<?php

namespace App\Http\Controllers;

use App\Enums\Role;
use App\Exceptions\WorkflowException;
use App\Models\CostCode;
use App\Models\Expense;
use App\Models\ExpenseCategory;
use App\Models\ExpenseClaim;
use App\Models\Project;
use App\Services\ExpenseClaimService;
use App\Support\ListPage;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;

class ExpenseClaimController extends Controller
{
    public function index(Request $request)
    {
        $counts = ExpenseClaim::visibleTo($request->user())->toBase()->selectRaw('status, count(*) as n')->groupBy('status')->pluck('n', 'status');
        $stats = [
            'submitted' => (int) ($counts['submitted'] ?? 0),
            'approved' => (int) ($counts['approved'] ?? 0),
            'paid' => (int) ($counts['paid'] ?? 0),
            'unpaid_value' => ExpenseClaim::visibleTo($request->user())->where('status', 'approved')->sum('amount'),
        ];
        $query = ExpenseClaim::visibleTo($request->user())->with('project', 'claimant', 'category')
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->status))
            ->when($request->filled('ids'), fn ($q) => $q->whereIn('id', array_map('intval', (array) $request->input('ids'))))
            ->latest('id');

        return ListPage::make($request, $query, 'Expense claims')
            ->period('incurred_on', 'Incurred')->project()->search(['claim_no', 'description'], 'Claim number or description')
            ->columns([
                'Claim' => fn ($c) => $c->claim_no, 'Date' => fn ($c) => $c->incurred_on->format('Y-m-d'), 'Project' => fn ($c) => $c->project->code,
                'Claimant' => fn ($c) => $c->claimant->name, 'Category' => fn ($c) => $c->category->name, 'Description' => fn ($c) => $c->description,
                'Amount' => [fn ($c) => $c->amount, 'money'], 'Status' => fn ($c) => $c->statusLabel(),
            ])
            ->totals(['Amount'])
            ->respond(fn ($claims, $list) => view('claims.index', ['claims' => $claims, 'list' => $list, 'stats' => $stats]));
    }

    public function create(Request $request)
    {
        Gate::authorize('submit-claim');

        return view('claims.create', $this->options($request));
    }

    public function store(Request $request, ExpenseClaimService $service)
    {
        Gate::authorize('submit-claim');
        $data = $this->validated($request);
        $claim = $service->submit($request->user(), $data);

        return redirect()->route('claims.show', $claim)->with('status', "Claim {$claim->claim_no} submitted for approval.");
    }

    public function edit(Request $request, ExpenseClaim $claim)
    {
        Gate::authorize('edit-claim', $claim);

        return view('claims.create', ['claim' => $claim] + $this->options($request));
    }

    public function update(Request $request, ExpenseClaim $claim, ExpenseClaimService $service)
    {
        Gate::authorize('edit-claim', $claim);
        $claim = $service->update($request->user(), $claim, $this->validated($request));

        return redirect()->route('claims.index')->with('status', "Claim {$claim->claim_no} updated. The approver has been told.");
    }

    public function destroy(Request $request, ExpenseClaim $claim, ExpenseClaimService $service)
    {
        Gate::authorize('delete-claim', $claim);
        $no = $claim->claim_no;
        $service->delete($request->user(), $claim);

        return redirect()->route('claims.index')->with('status', "Claim {$no} deleted.");
    }

    /**
     * Apply one action to the selected claims. Each claim is checked on its own (permission and status);
     * claims that do not qualify are skipped and listed, the rest are processed.
     */
    public function bulk(Request $request, ExpenseClaimService $service)
    {
        // Refuse read-only roles before validating; each claim is still checked on its own below.
        abort_unless(Gate::allows('submit-claim') || $request->user()->hasRole(Role::Admin, Role::OverallManager, Role::ProjectManager, Role::Accountant), 403);
        $data = $request->validate([
            'action' => 'required|in:approve,reject,pay,delete',
            'ids' => 'required|array|min:1|max:200',
            'ids.*' => 'integer',
            'reason' => 'nullable|required_if:action,reject|string|max:1000',
            'payment_mode' => ['nullable', 'required_if:action,pay', Rule::in(Expense::PAYMENT_MODES)],
            'reference' => 'nullable|string|max:100',
        ], ['ids.required' => 'Select at least one claim first.']);
        $user = $request->user();
        $claims = ExpenseClaim::visibleTo($user)->with('project', 'claimant')->whereIn('id', $data['ids'])->orderBy('id')->get();

        $done = [];
        $skipped = [];
        foreach ($claims as $claim) {
            try {
                match ($data['action']) {
                    'approve' => Gate::allows('decide-claim', $claim) ? $service->decide($user, $claim, 'approved', $data['reason'] ?? null) : throw new WorkflowException('you cannot approve it'),
                    'reject' => Gate::allows('decide-claim', $claim) ? $service->decide($user, $claim, 'rejected', $data['reason']) : throw new WorkflowException('you cannot reject it'),
                    'pay' => Gate::allows('pay-claim', $claim) ? $service->pay($user, $claim, $data['payment_mode'], $data['reference'] ?? null) : throw new WorkflowException('it is not approved or you cannot pay it'),
                    'delete' => Gate::allows('delete-claim', $claim) ? $service->delete($user, $claim) : throw new WorkflowException('only claims awaiting approval or rejected can be deleted, by the claimant or admin'),
                };
                $done[] = $claim->claim_no;
            } catch (WorkflowException|AuthorizationException $e) {
                $skipped[] = "{$claim->claim_no}: ".$e->getMessage();
            }
        }
        $verb = ['approve' => 'approved', 'reject' => 'rejected', 'pay' => 'paid', 'delete' => 'deleted'][$data['action']];
        $message = count($done).' claim(s) '.$verb.($done ? ' ('.implode(', ', $done).')' : '').'.';
        if ($skipped) {
            return back()->with('status', $message)->withErrors(['bulk' => 'Skipped '.count($skipped).': '.implode('; ', $skipped)]);
        }

        return back()->with('status', $message);
    }

    private function validated(Request $request): array
    {
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

        return $data;
    }

    public function show(ExpenseClaim $claim)
    {
        Gate::authorize('view-claim', $claim);

        return view('claims.show', ['claim' => $claim->load('project', 'claimant', 'category', 'costCode', 'approver', 'expense')]);
    }

    public function decide(Request $request, ExpenseClaim $claim, ExpenseClaimService $service)
    {
        Gate::authorize('decide-claim', $claim);
        $data = $request->validate(['decision' => 'required|in:approved,rejected', 'reason' => 'nullable|required_if:decision,rejected|string|max:1000', 'seen_at' => 'nullable|string']);
        $service->decide($request->user(), $claim, $data['decision'], $data['reason'] ?? null, $data['seen_at'] ?? null);

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
