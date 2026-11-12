<?php

namespace App\Http\Controllers;

use App\Models\InventoryItem;
use App\Models\StockReceipt;
use App\Services\StockService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/** Opening balances, purchase receipts and adjustments. Issues live in IssueController. */
class StockController extends Controller
{
    public function index()
    {
        Gate::authorize('view-stock-ledger');

        return view('receipts.index', ['receipts' => StockReceipt::with('receiver')->withCount('lines')->latest('id')->paginate(25)]);
    }

    public function create(Request $request)
    {
        Gate::authorize('manage-stock');

        return view('receipts.create', [
            'items' => InventoryItem::with('unit')->where('is_active', true)->orderBy('name')->get(),
            'type' => $request->query('type') === 'opening' ? 'opening' : 'purchase',
        ]);
    }

    public function store(Request $request, StockService $stock)
    {
        Gate::authorize('manage-stock');
        $data = $request->validate([
            'type' => 'required|in:opening,purchase',
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
        $receipt = $stock->receive($request->user(), $data, $lines);

        return redirect()->route('receipts.show', $receipt)->with('status', "Stock recorded: {$receipt->receipt_no}.");
    }

    /** B-26: blank CSV template listing every item that has no stock history yet. */
    public function openingTemplate()
    {
        Gate::authorize('manage-stock');
        $items = InventoryItem::whereDoesntHave('movements')->where('is_active', true)->orderBy('sku')->get();

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
            'type' => 'opening', 'received_on' => $data['received_on'],
            'notes' => 'Imported from '.$request->file('file')->getClientOriginalName(),
        ], $lines);

        return redirect()->route('receipts.show', $receipt)->with('status', count($lines)." opening balances imported as {$receipt->receipt_no}.");
    }

    public function show(StockReceipt $receipt)
    {
        Gate::authorize('view-stock-ledger');

        return view('receipts.show', ['receipt' => $receipt->load('lines.item.unit', 'receiver')]);
    }

    public function createAdjustment()
    {
        Gate::authorize('manage-stock');

        return view('receipts.adjust', ['items' => InventoryItem::with('balance', 'unit')->orderBy('name')->get()]);
    }

    public function storeAdjustment(Request $request, StockService $stock)
    {
        Gate::authorize('manage-stock');
        $data = $request->validate([
            'item_id' => 'required|exists:inventory_items,id',
            'qty_delta' => 'required|numeric|not_in:0',
            'reason' => 'required|string|min:5|max:500',
        ]);
        $adj = $stock->adjust($request->user(), (int) $data['item_id'], (string) $data['qty_delta'], $data['reason']);

        return redirect()->route('items.show', $data['item_id'])->with('status', "Adjustment {$adj->adjustment_no} posted.");
    }
}
