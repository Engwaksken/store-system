<?php

namespace App\Http\Controllers;

use App\Models\ActivityLog;
use App\Models\Expense;
use App\Models\ExpenseCategory;
use App\Models\Project;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;

/** Cash project expenses. Store purchases are NOT entered here (they are stock receipts). */
class ExpenseController extends Controller
{
    public function index(Request $request)
    {
        Gate::authorize('view-expenses');
        $base = Expense::visibleTo($request->user())
            ->when($request->filled('project_id'), fn ($q) => $q->where('project_id', $request->integer('project_id')))
            ->when($request->filled('category_id'), fn ($q) => $q->where('category_id', $request->integer('category_id')))
            ->when($request->filled('from'), fn ($q) => $q->whereDate('incurred_on', '>=', $request->date('from')))
            ->when($request->filled('to'), fn ($q) => $q->whereDate('incurred_on', '<=', $request->date('to')));

        return view('expenses.index', [
            'expenses' => (clone $base)->with('project', 'category', 'recorder')->latest('incurred_on')->latest('id')->paginate(25)->withQueryString(),
            'total' => (clone $base)->sum('amount'),
            'stats' => [
                'count' => (clone $base)->count(),
                'month' => Expense::visibleTo($request->user())->where('incurred_on', '>=', now()->startOfMonth()->toDateString())->sum('amount'),
                'no_proof' => (clone $base)->whereNull('receipt_path')->count(),
            ],
            'projects' => Project::visibleTo($request->user())->orderBy('code')->get(),
            'categories' => ExpenseCategory::orderBy('name')->get(),
        ]);
    }

    public function create()
    {
        Gate::authorize('record-expense');

        return view('expenses.form', ['expense' => new Expense(['incurred_on' => now(), 'payment_mode' => 'cash']), ...$this->options()]);
    }

    public function store(Request $request)
    {
        Gate::authorize('record-expense');
        $data = $this->validated($request);
        if ($request->hasFile('proof')) {
            $data['receipt_path'] = $request->file('proof')->store('expense-proofs', 'local');
        }
        $expense = Expense::create($data + ['recorded_by' => $request->user()->id]);
        ActivityLog::record('expense.created', "Expense UGX ".number_format((float) $expense->amount)." recorded for {$expense->project->code}", $expense);

        return redirect()->route('expenses.index')->with('status', 'Expense recorded.');
    }

    public function edit(Expense $expense)
    {
        Gate::authorize('record-expense');

        return view('expenses.form', ['expense' => $expense, ...$this->options()]);
    }

    public function update(Request $request, Expense $expense)
    {
        Gate::authorize('record-expense');
        $data = $this->validated($request);
        if ($request->hasFile('proof')) {
            $data['receipt_path'] = $request->file('proof')->store('expense-proofs', 'local');
        }
        $before = $expense->only('project_id', 'amount', 'incurred_on', 'category_id');
        $expense->update($data);
        ActivityLog::record('expense.updated', "Expense #{$expense->id} edited", $expense, ['before' => $before, 'after' => $expense->only('project_id', 'amount', 'incurred_on', 'category_id')]);

        return redirect()->route('expenses.index')->with('status', 'Expense updated.');
    }

    /** Proofs are on the private disk and served only after an authorization check. */
    public function proof(Expense $expense)
    {
        Gate::authorize('view-expense', $expense);
        abort_unless($expense->receipt_path && Storage::disk('local')->exists($expense->receipt_path), 404);

        return Storage::disk('local')->download($expense->receipt_path);
    }

    private function options(): array
    {
        return [
            'projects' => Project::orderBy('code')->get(),
            'categories' => ExpenseCategory::where('is_active', true)->orderBy('name')->get(),
            'costCodes' => \App\Models\CostCode::where('is_active', true)->orderBy('code')->get(),
        ];
    }

    private function validated(Request $request): array
    {
        return $request->validate([
            'project_id' => 'required|exists:projects,id',
            'category_id' => ['required', Rule::exists('expense_categories', 'id')->where('is_active', true)],
            'cost_code_id' => ['nullable', Rule::exists('cost_codes', 'id')->where('is_active', true)],
            'incurred_on' => 'required|date|before_or_equal:today',
            'amount' => 'required|numeric|gt:0|max:9999999999999',
            'payment_mode' => ['required', Rule::in(Expense::PAYMENT_MODES)],
            'reference' => 'nullable|string|max:100',
            'description' => 'required|string|max:1000',
            'proof' => 'nullable|file|mimes:pdf,jpg,jpeg,png|max:4096',
        ]);
    }
}
