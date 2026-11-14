<?php

namespace App\Http\Controllers;

use App\Models\InventoryItem;
use App\Models\Project;
use App\Models\PurchaseRequisition;
use App\Models\Store;
use App\Models\Supplier;
use App\Models\SupplierQuote;
use App\Services\PurchasingService;
use App\Support\ListPage;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class PurchaseRequisitionController extends Controller
{
    public function index(Request $request)
    {
        Gate::authorize('view-purchasing');
        $query = PurchaseRequisition::visibleTo($request->user())->with('project', 'store', 'requester')->withCount('lines')
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->status))
            ->latest('id');

        $counts = PurchaseRequisition::visibleTo($request->user())->toBase()->selectRaw('status, count(*) as n')->groupBy('status')->pluck('n', 'status');
        $stats = ['total' => (int) $counts->sum(), 'submitted' => (int) ($counts['submitted'] ?? 0), 'approved' => (int) ($counts['approved'] ?? 0), 'ordered' => (int) ($counts['ordered'] ?? 0)];

        return ListPage::make($request, $query, 'Purchase requisitions')
            ->period('created_at', 'Raised')->project()->store()->search(['pr_no', 'purpose'], 'PR number or purpose')
            ->columns([
                'PR' => fn ($pr) => $pr->pr_no, 'Raised' => fn ($pr) => $pr->created_at->format('Y-m-d'), 'Project' => fn ($pr) => $pr->project?->code ?? 'Stock',
                'Deliver to' => fn ($pr) => $pr->store->code, 'Purpose' => fn ($pr) => $pr->purpose, 'Requested by' => fn ($pr) => $pr->requester->name,
                'Needed on' => fn ($pr) => $pr->needed_on?->format('Y-m-d'), 'Lines' => [fn ($pr) => $pr->lines_count, 'number'],
                'Est. total' => [fn ($pr) => $pr->estimated_total, 'money'], 'Status' => fn ($pr) => $pr->statusLabel(),
            ])
            ->totals(['Est. total'])
            ->respond(fn ($prs, $list) => view('prs.index', ['prs' => $prs, 'list' => $list, 'stats' => $stats]));
    }

    public function create(Request $request)
    {
        Gate::authorize('create-pr');

        return view('prs.create', [
            'projects' => Project::visibleTo($request->user())->whereIn('status', ['planned', 'active'])->orderBy('code')->get(),
            'stores' => Store::where('is_active', true)->orderBy('id')->get(),
            'items' => InventoryItem::with('unit')->where('is_active', true)->orderBy('name')->get(),
        ]);
    }

    public function store(Request $request, PurchasingService $service)
    {
        Gate::authorize('create-pr');
        $data = $request->validate([
            'project_id' => 'nullable|integer|exists:projects,id',
            'store_id' => 'required|integer|exists:stores,id',
            'purpose' => 'required|string|max:1000',
            'needed_on' => 'nullable|date',
            'lines' => 'required|array|min:1',
            'lines.*.item_id' => 'nullable|integer|exists:inventory_items,id',
            'lines.*.qty' => 'nullable|required_with:lines.*.item_id|numeric|gt:0',
            'lines.*.est_unit_cost' => 'nullable|numeric|min:0',
        ]);
        $lines = array_values(array_filter($data['lines'], fn ($l) => ! empty($l['item_id'])));
        $pr = $service->createRequisition($request->user(), $data, $lines);
        if ($request->input('action') === 'submit') {
            $service->submitRequisition($request->user(), $pr);
        }

        return redirect()->route('prs.show', $pr)->with('status', "Requisition {$pr->pr_no} ".($request->input('action') === 'submit' ? 'submitted for approval.' : 'saved as draft.'));
    }

    public function show(PurchaseRequisition $pr)
    {
        Gate::authorize('view-pr', $pr);
        $pr->load('project', 'store', 'requester', 'decider', 'lines.item.unit', 'quotes.supplier', 'quotes.lines', 'purchaseOrders');

        return view('prs.show', [
            'pr' => $pr,
            'suppliers' => Gate::allows('buy') ? Supplier::where('is_active', true)->whereNotIn('id', $pr->quotes->pluck('supplier_id'))->orderBy('name')->get() : collect(),
            'cheapest' => $pr->quotes->min('total'),
        ]);
    }

    public function submit(Request $request, PurchaseRequisition $pr, PurchasingService $service)
    {
        Gate::authorize('view-pr', $pr);
        $service->submitRequisition($request->user(), $pr);

        return back()->with('status', 'Requisition submitted for approval.');
    }

    public function decide(Request $request, PurchaseRequisition $pr, PurchasingService $service)
    {
        Gate::authorize('decide-pr', $pr);
        $data = $request->validate(['decision' => 'required|in:approved,rejected', 'reason' => 'nullable|required_if:decision,rejected|string|max:1000']);
        $service->decideRequisition($request->user(), $pr, $data['decision'], $data['reason'] ?? null);

        return back()->with('status', 'Requisition '.$data['decision'].'.');
    }

    public function cancel(Request $request, PurchaseRequisition $pr, PurchasingService $service)
    {
        Gate::authorize('view-pr', $pr);
        $service->cancelRequisition($request->user(), $pr);

        return back()->with('status', 'Requisition cancelled.');
    }

    public function storeQuote(Request $request, PurchaseRequisition $pr, PurchasingService $service)
    {
        Gate::authorize('buy');
        $data = $request->validate([
            'supplier_id' => 'required|integer|exists:suppliers,id',
            'quote_ref' => 'nullable|string|max:100',
            'quoted_on' => 'required|date|before_or_equal:today',
            'valid_until' => 'nullable|date|after_or_equal:quoted_on',
            'price' => 'required|array',
            'price.*' => 'required|numeric|min:0',
        ]);
        $service->addQuote($request->user(), $pr, (int) $data['supplier_id'], $data['price'], $data);

        return back()->with('status', 'Quote recorded.');
    }

    public function selectQuote(Request $request, SupplierQuote $quote, PurchasingService $service)
    {
        Gate::authorize('buy');
        $data = $request->validate(['selection_reason' => 'nullable|string|max:1000']);
        $service->selectQuote($request->user(), $quote, $data['selection_reason'] ?? null);

        return back()->with('status', "{$quote->supplier->name} selected.");
    }
}
