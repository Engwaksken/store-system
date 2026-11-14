<?php

namespace App\Http\Controllers;

use App\Models\InventoryItem;
use App\Models\Project;
use App\Models\PurchaseOrder;
use App\Models\PurchaseRequisition;
use App\Models\Store;
use App\Models\Supplier;
use App\Services\GoodsReceiptService;
use App\Services\PurchasingService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class PurchaseOrderController extends Controller
{
    public function index(Request $request)
    {
        Gate::authorize('view-purchasing');
        $orders = PurchaseOrder::visibleTo($request->user())->with('supplier', 'store', 'project')
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->status))
            ->when($request->filled('supplier_id'), fn ($q) => $q->where('supplier_id', $request->integer('supplier_id')))
            ->latest('id')->paginate(25)->withQueryString();

        $counts = PurchaseOrder::visibleTo($request->user())->toBase()->selectRaw('status, count(*) as n')->groupBy('status')->pluck('n', 'status');
        $stats = [
            'total' => (int) $counts->sum(),
            'pending' => (int) ($counts['pending_approval'] ?? 0),
            'open' => (int) ($counts['approved'] ?? 0) + (int) ($counts['partially_received'] ?? 0),
            'open_value' => PurchaseOrder::visibleTo($request->user())->whereIn('status', ['approved', 'partially_received'])->sum('total'),
        ];

        return view('pos.index', ['orders' => $orders, 'suppliers' => Supplier::orderBy('name')->get(), 'stats' => $stats]);
    }

    public function create(Request $request)
    {
        Gate::authorize('buy');
        $pr = $request->filled('pr') ? PurchaseRequisition::with('lines.item', 'quotes.lines.requisitionLine')->findOrFail($request->integer('pr')) : null;
        $quote = $pr?->quotes->firstWhere('is_selected', true);
        $prefill = $pr ? $pr->lines->map(fn ($l) => [
            'item_id' => $l->item_id, 'qty' => (float) $l->qty,
            'unit_price' => (float) ($quote?->lines->firstWhere('purchase_requisition_line_id', $l->id)?->unit_price ?? $l->est_unit_cost),
        ])->all() : [];

        return view('pos.create', [
            'pr' => $pr, 'quote' => $quote, 'prefill' => $prefill,
            'suppliers' => Supplier::where('is_active', true)->orderBy('name')->get(),
            'stores' => Store::where('is_active', true)->orderBy('id')->get(),
            'projects' => Project::whereIn('status', ['planned', 'active', 'on_hold'])->orderBy('code')->get(),
            'items' => InventoryItem::with('unit')->where('is_active', true)->orderBy('name')->get(),
            'costCodes' => \App\Models\CostCode::where('is_active', true)->orderBy('code')->get(),
        ]);
    }

    public function store(Request $request, PurchasingService $service)
    {
        Gate::authorize('buy');
        $data = $request->validate([
            'purchase_requisition_id' => 'nullable|integer|exists:purchase_requisitions,id',
            'supplier_id' => 'required|integer|exists:suppliers,id',
            'store_id' => 'required|integer|exists:stores,id',
            'project_id' => 'nullable|integer|exists:projects,id',
            'cost_code_id' => 'nullable|integer|exists:cost_codes,id',
            'order_date' => 'required|date',
            'expected_on' => 'nullable|date|after_or_equal:order_date',
            'terms' => 'nullable|string|max:1000',
            'notes' => 'nullable|string|max:1000',
            'lines' => 'required|array|min:1',
            'lines.*.item_id' => 'nullable|integer|exists:inventory_items,id',
            'lines.*.qty' => 'nullable|required_with:lines.*.item_id|numeric|gt:0',
            'lines.*.unit_price' => 'nullable|required_with:lines.*.item_id|numeric|min:0',
        ]);
        $lines = array_values(array_filter($data['lines'], fn ($l) => ! empty($l['item_id'])));
        $po = $service->createOrder($request->user(), array_filter($data, fn ($v) => $v !== null), $lines);

        return redirect()->route('pos.show', $po)->with('status', "Purchase order {$po->po_no} raised and sent for approval.");
    }

    public function show(PurchaseOrder $po, PurchasingService $service)
    {
        Gate::authorize('view-po', $po);
        $po->load('supplier', 'store', 'project', 'requisition', 'quote', 'creator', 'decider', 'lines.item.unit', 'receipts', 'invoices');

        return view('pos.show', ['po' => $po, 'threshold' => $service->ownerThreshold()]);
    }

    public function pdf(PurchaseOrder $po)
    {
        Gate::authorize('view-po', $po);

        return \App\Support\Pdf::download('pdf.po', ['po' => $po->load('supplier', 'store', 'project', 'creator', 'decider', 'lines.item.unit')], $po->po_no.'.pdf');
    }

    public function decide(Request $request, PurchaseOrder $po, PurchasingService $service)
    {
        Gate::authorize('decide-po', $po);
        $data = $request->validate([
            'decision' => 'required|in:approved,rejected',
            'reason' => 'nullable|required_if:decision,rejected|string|max:1000',
            'lock_version' => 'required|integer',
        ]);
        $service->decideOrder($request->user(), $po, $data['decision'], $data['reason'] ?? null, (int) $data['lock_version']);

        return back()->with('status', "Purchase order {$data['decision']}.");
    }

    public function cancel(Request $request, PurchaseOrder $po, PurchasingService $service)
    {
        Gate::authorize('buy');
        $data = $request->validate(['reason' => 'required|string|min:5|max:1000']);
        $service->cancelOrder($request->user(), $po, $data['reason']);

        return back()->with('status', 'Purchase order cancelled.');
    }

    public function close(Request $request, PurchaseOrder $po, PurchasingService $service)
    {
        Gate::authorize('buy');
        $data = $request->validate(['reason' => 'required|string|min:5|max:1000']);
        $service->closeOrder($request->user(), $po, $data['reason']);

        return back()->with('status', 'Purchase order closed; no further deliveries expected.');
    }

    public function receiveForm(PurchaseOrder $po)
    {
        Gate::authorize('receive-po', $po);

        return view('pos.receive', ['po' => $po->load('supplier', 'store', 'lines.item.unit')]);
    }

    public function receive(Request $request, PurchaseOrder $po, GoodsReceiptService $service)
    {
        Gate::authorize('receive-po', $po);
        $data = $request->validate([
            'qty' => 'required|array',
            'qty.*' => 'nullable|numeric|min:0',
            'received_on' => 'required|date|before_or_equal:today',
            'reference' => 'nullable|string|max:100',
            'notes' => 'nullable|string|max:1000',
            'idempotency_key' => 'nullable|string|max:64',
        ]);
        $receipt = $service->receive($request->user(), $po, $data['qty'], $data);

        return redirect()->route('receipts.show', $receipt)->with('status', $receipt->wasRecentlyCreated
            ? "GRN {$receipt->receipt_no} posted against {$po->po_no}." : "{$receipt->receipt_no} was already posted; nothing was received twice.");
    }
}
