<?php

namespace App\Http\Controllers;

use App\Models\InventoryItem;
use App\Models\StockReceipt;
use App\Models\Store;
use App\Services\StockService;
use App\Support\ListPage;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/** Opening balances, purchase receipts and adjustments. Issues live in IssueController. */
class StockController extends Controller
{
    public function index(Request $request)
    {
        Gate::authorize('view-stock-ledger');

        $types = StockReceipt::toBase()->selectRaw('type, count(*) as n')->groupBy('type')->pluck('n', 'type');
        $stats = [
            'total' => (int) $types->sum(),
            'purchase' => (int) ($types['purchase'] ?? 0),
            'opening' => (int) ($types['opening'] ?? 0),
            'month_value' => StockReceipt::where('received_on', '>=', now()->startOfMonth()->toDateString())->sum('total_value'),
        ];
        $query = StockReceipt::with('receiver', 'store', 'purchaseOrder')->withCount('lines')
            ->when(in_array($request->query('type'), ['purchase', 'opening'], true), fn ($q) => $q->where('type', $request->query('type')))
            ->latest('id');

        return ListPage::make($request, $query, 'Stock receipts')
            ->period('received_on', 'Received')
            // A receipt belongs to a project when it was received into that project's site store or against that project's purchase order.
            ->project(fn ($q, $id) => $q->where(fn ($w) => $w->whereHas('store', fn ($s) => $s->where('project_id', $id))
                ->orWhereHas('purchaseOrder', fn ($p) => $p->where('project_id', $id))))
            ->store('store_id')
            ->search(['receipt_no', 'supplier_name', 'reference'], 'Receipt, supplier or reference')
            ->columns([
                'Receipt' => fn ($r) => $r->receipt_no, 'Type' => fn ($r) => $r->type, 'Store' => fn ($r) => $r->store->code,
                'Date' => fn ($r) => $r->received_on->format('Y-m-d'), 'Supplier' => fn ($r) => $r->supplier_name,
                'Reference' => fn ($r) => $r->reference, 'Purchase order' => fn ($r) => $r->purchaseOrder?->po_no,
                'Lines' => [fn ($r) => $r->lines_count, 'number'], 'Value' => [fn ($r) => $r->total_value, 'money'],
                'By' => fn ($r) => $r->receiver->name,
            ])
            ->totals(['Value'])
            ->respond(fn ($receipts, $list) => view('receipts.index', ['receipts' => $receipts, 'list' => $list, 'stats' => $stats]));
    }

    public function create(Request $request)
    {
        Gate::authorize('manage-stock');

        return view('receipts.create', [
            'items' => InventoryItem::with('unit')->where('is_active', true)->orderBy('name')->get(),
            'type' => $request->query('type') === 'opening' ? 'opening' : 'purchase',
            'stores' => Store::operableBy($request->user())->orderBy('id')->get(),
        ]);
    }

    public function store(Request $request, StockService $stock)
    {
        Gate::authorize('manage-stock');
        $data = $request->validate([
            'type' => 'required|in:opening,purchase',
            'store_id' => 'nullable|integer|exists:stores,id',
            'idempotency_key' => 'nullable|string|max:64',
            'received_on' => 'required|date|before_or_equal:today',
            'supplier_name' => 'nullable|required_if:type,purchase|string|max:255',
            'reference' => 'nullable|string|max:100',
            'notes' => 'nullable|string|max:1000',
            'lines' => 'required|array|min:1',
            'lines.*.item_id' => 'nullable|exists:inventory_items,id',
            'lines.*.qty' => 'nullable|required_with:lines.*.item_id|numeric|gt:0',
            'lines.*.unit_cost' => 'nullable|required_with:lines.*.item_id|numeric|min:0',
        ]);
        $lines = array_values(array_filter($data['lines'], fn ($l) => ! empty($l['item_id'])));
        $data['store_id'] ??= Store::DEFAULT_ID;
        $receipt = $stock->receive($request->user(), $data, $lines);

        return redirect()->route('receipts.show', $receipt)->with('status', $receipt->wasRecentlyCreated
            ? "Stock recorded: {$receipt->receipt_no}." : "{$receipt->receipt_no} was already recorded; it was not posted twice.");
    }

    /** B-26: blank CSV template listing every item that has no stock history yet. */
    public function openingTemplate(Request $request)
    {
        Gate::authorize('manage-stock');
        $storeId = $request->integer('store_id', Store::DEFAULT_ID);
        $items = InventoryItem::whereDoesntHave('movements', fn ($m) => $m->where('store_id', $storeId))->where('is_active', true)->orderBy('sku')->get();

        return response()->streamDownload(function () use ($items) {
            $out = fopen('php://output', 'w');
            fputcsv($out, ['sku', 'qty', 'unit_cost', 'item_name_for_reference']);
            foreach ($items as $i) {
                fputcsv($out, [$i->sku, '', '', $i->name]);
            }
            fclose($out);
        }, 'opening-balances-template.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    /** B-26: import opening balances from CSV (sku, qty, unit_cost). All-or-nothing: any bad row rejects the file. */
    public function importOpening(Request $request, StockService $stock)
    {
        Gate::authorize('manage-stock');
        $data = $request->validate([
            'file' => 'required|file|mimes:csv,txt|max:2048',
            'store_id' => 'nullable|integer|exists:stores,id',
            'received_on' => 'required|date|before_or_equal:today',
        ]);

        $fh = fopen($request->file('file')->getRealPath(), 'r');
        $header = array_map(fn ($h) => strtolower(trim(preg_replace('/^\xEF\xBB\xBF/', '', (string) $h))), fgetcsv($fh) ?: []);
        if (! in_array('sku', $header) || ! in_array('qty', $header) || ! in_array('unit_cost', $header)) {
            return back()->withErrors(['file' => 'The first row must contain the columns: sku, qty, unit_cost.']);
        }
        $col = array_flip($header);
        $skus = InventoryItem::pluck('id', 'sku');
        $lines = $errors = [];
        $row = 1;
        while (($r = fgetcsv($fh)) !== false) {
            $row++;
            $sku = trim($r[$col['sku']] ?? '');
            $qty = trim($r[$col['qty']] ?? '');
            $cost = str_replace(',', '', trim($r[$col['unit_cost']] ?? ''));
            if ($sku === '' || $qty === '' || (float) $qty == 0.0) {
                continue; // blank template rows are skipped
            }
            if (! isset($skus[$sku])) {
                $errors[] = "Row {$row}: unknown SKU '{$sku}'.";
            } elseif (! is_numeric($qty) || $qty <= 0 || ! is_numeric($cost) || $cost < 0) {
                $errors[] = "Row {$row} ({$sku}): quantity must be above 0 and unit cost 0 or more.";
            } else {
                $lines[] = ['item_id' => $skus[$sku], 'qty' => $qty, 'unit_cost' => $cost];
            }
        }
        fclose($fh);
        if ($errors) {
            return back()->withErrors(array_slice($errors, 0, 20));
        }
        if (! $lines) {
            return back()->withErrors(['file' => 'No rows with a quantity were found.']);
        }

        $receipt = $stock->receive($request->user(), [
            'type' => 'opening', 'received_on' => $data['received_on'], 'store_id' => (int) ($data['store_id'] ?? \App\Models\Store::DEFAULT_ID),
            'notes' => 'Imported from '.$request->file('file')->getClientOriginalName(),
        ], $lines);

        return redirect()->route('receipts.show', $receipt)->with('status', count($lines)." opening balances imported as {$receipt->receipt_no}.");
    }

    public function show(StockReceipt $receipt)
    {
        Gate::authorize('view-stock-ledger');

        return view('receipts.show', ['receipt' => $receipt->load('lines.item.unit', 'receiver', 'store')]);
    }

    public function pdf(StockReceipt $receipt)
    {
        Gate::authorize('view-stock-ledger');

        return \App\Support\Pdf::download('pdf.grn', ['receipt' => $receipt->load('lines.item.unit', 'receiver', 'store', 'purchaseOrder')], $receipt->receipt_no.'.pdf');
    }

    public function createAdjustment(Request $request)
    {
        Gate::authorize('manage-stock');
        $stores = Store::operableBy($request->user())->orderBy('id')->get();
        $storeId = $request->integer('store_id', $stores->first()?->id ?? Store::DEFAULT_ID);

        return view('receipts.adjust', [
            'stores' => $stores, 'storeId' => $storeId,
            'items' => InventoryItem::with(['unit', 'balances' => fn ($q) => $q->where('store_id', $storeId)])->orderBy('name')->get(),
        ]);
    }

    public function storeAdjustment(Request $request, StockService $stock)
    {
        Gate::authorize('manage-stock');
        $data = $request->validate([
            'item_id' => 'required|exists:inventory_items,id',
            'store_id' => 'nullable|integer|exists:stores,id',
            'qty_delta' => 'required|numeric|not_in:0',
            'reason' => 'required|string|min:5|max:500',
        ]);
        $adj = $stock->adjust($request->user(), (int) $data['item_id'], (string) $data['qty_delta'], $data['reason'], (int) ($data['store_id'] ?? \App\Models\Store::DEFAULT_ID));

        return redirect()->route('items.show', $data['item_id'])->with('status', "Adjustment {$adj->adjustment_no} posted.");
    }
}
