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
