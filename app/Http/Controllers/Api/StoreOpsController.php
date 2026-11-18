<?php

namespace App\Http\Controllers\Api;

use App\Http\Requests\StoreTransferRequest;
use App\Models\InventoryItem;
use App\Models\MaterialIssue;
use App\Models\StockReceipt;
use App\Models\StockReturn;
use App\Models\StockTransfer;
use App\Models\Store;
use App\Services\StockReturnService;
use App\Services\StockService;
use App\Services\StockTransferService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;

/** Stock receipts, adjustments, transfers and returns on the mobile API (same services and gates as the web). */
class StoreOpsController extends ApiController
{
    /** Stores this user operates (receipts, adjustments, dispatch, returns) and all active stores (transfer destination). */
    public function options(Request $request): JsonResponse
    {
        Gate::authorize('view-stock');

        return response()->json([
            'operable_stores' => Store::operableBy($request->user())->orderBy('id')->get()->map(fn ($s) => PresentOps::store($s))->values(),
            'active_stores' => Store::where('is_active', true)->orderBy('id')->get()->map(fn ($s) => PresentOps::store($s))->values(),
        ]);
    }

    // ------------------------------------------------------------------ receipts

    public function receipts(Request $request): JsonResponse
    {
        Gate::authorize('view-stock-ledger');
        $query = StockReceipt::with('receiver', 'store', 'purchaseOrder')->withCount('lines')
            ->when(in_array($request->query('type'), ['purchase', 'opening'], true), fn ($q) => $q->where('type', $request->query('type')))
            ->when($request->filled('store_id'), fn ($q) => $q->where('store_id', $request->integer('store_id')))
            ->when($request->filled('q'), fn ($q) => $q->where(fn ($w) => $w->where('receipt_no', 'like', $this->like($request->query('q')))
                ->orWhere('supplier_name', 'like', $this->like($request->query('q')))->orWhere('reference', 'like', $this->like($request->query('q')))))
            ->latest('id');

        return $this->paginated($request, $query, fn ($r) => PresentOps::receiptSummary($r, $request->user()));
    }

    public function receipt(Request $request, StockReceipt $receipt): JsonResponse
    {
        Gate::authorize('view-stock-ledger');

        return response()->json(['data' => PresentOps::receiptDetail($receipt, $request->user())]);
    }

    public function storeReceipt(Request $request, StockService $stock): JsonResponse
    {
        Gate::authorize('manage-stock');
        $data = $request->validate([
            'store_id' => 'required|integer|exists:stores,id',
            'idempotency_key' => 'nullable|string|max:64',
            'received_on' => 'required|date|before_or_equal:today',
            'supplier_name' => 'required|string|max:255',
            'reference' => 'nullable|string|max:100',
            'notes' => 'nullable|string|max:1000',
            'lines' => 'required|array|min:1|max:100',
            'lines.*.item_id' => 'required|integer|distinct|exists:inventory_items,id',
            'lines.*.qty' => 'required|numeric|gt:0',
            'lines.*.unit_cost' => 'required|numeric|min:0',
        ]);
        $receipt = $stock->receive($request->user(), ['type' => 'purchase'] + $data, $data['lines']);

        return response()->json(['data' => PresentOps::receiptDetail($receipt->fresh(), $request->user()), 'replayed' => ! $receipt->wasRecentlyCreated],
            $receipt->wasRecentlyCreated ? 201 : 200);
    }

    public function storeAdjustment(Request $request, StockService $stock): JsonResponse
    {
        Gate::authorize('manage-stock');
        $data = $request->validate([
            'item_id' => 'required|integer|exists:inventory_items,id',
            'store_id' => 'required|integer|exists:stores,id',
            'qty_delta' => 'required|numeric|not_in:0',
            'reason' => 'required|string|min:5|max:500',
        ]);
        $adj = $stock->adjust($request->user(), (int) $data['item_id'], (string) $data['qty_delta'], $data['reason'], (int) $data['store_id']);
        $item = InventoryItem::with('balances')->findOrFail($data['item_id']);

        return response()->json(['data' => [
            'id' => $adj->id, 'adjustment_no' => $adj->adjustment_no, 'qty_delta' => Present::qty($adj->qty_delta),
            'on_hand' => Present::qty($item->onHand((int) $data['store_id'])),
        ]], 201);
    }

    // ------------------------------------------------------------------ transfers

    public function transfers(Request $request): JsonResponse
    {
        Gate::authorize('viewAny', StockTransfer::class);
        $query = StockTransfer::visibleTo($request->user())->with('fromStore', 'toStore', 'dispatcher', 'receiver')->withCount('lines')
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->query('status')))
            ->when($request->filled('q'), fn ($q) => $q->where('transfer_no', 'like', $this->like($request->query('q'))))
            ->latest('id');

        return $this->paginated($request, $query, fn ($t) => PresentOps::transferSummary($t));
    }

    public function transfer(Request $request, StockTransfer $transfer): JsonResponse
    {
        Gate::authorize('view', $transfer);

        return response()->json(['data' => PresentOps::transferDetail($transfer, $request->user())]);
    }

    public function storeTransfer(Request $request, StockTransferService $service): JsonResponse
    {
        Gate::authorize('create', StockTransfer::class);
        $data = Validator::make($request->all(), (new StoreTransferRequest())->rules())->validate();
        $lines = array_values(array_filter($data['lines'], fn ($l) => ! empty($l['item_id'])));
        $t = $service->dispatch($request->user(), (int) $data['from_store_id'], (int) $data['to_store_id'], $lines, $data['notes'] ?? null, $data['idempotency_key'] ?? null);

        return response()->json(['data' => PresentOps::transferDetail($t->fresh(), $request->user()), 'replayed' => ! $t->wasRecentlyCreated], $t->wasRecentlyCreated ? 201 : 200);
    }

    public function receiveTransfer(Request $request, StockTransfer $transfer, StockTransferService $service): JsonResponse
    {
        Gate::authorize('receive', $transfer);
        $data = $request->validate([
            'received' => 'required|array',
            'received.*' => 'required|numeric|min:0',
            'reason' => 'nullable|array',
            'reason.*' => 'nullable|string|max:500',
            'receive_notes' => 'nullable|string|max:1000',
        ]);
        $t = $service->receive($request->user(), $transfer, $data['received'], $data['reason'] ?? [], $data['receive_notes'] ?? null);

        return response()->json(['data' => PresentOps::transferDetail($t->fresh(), $request->user())]);
    }

    public function cancelTransfer(Request $request, StockTransfer $transfer, StockTransferService $service): JsonResponse
    {
        Gate::authorize('cancel', $transfer);
        $data = $request->validate(['reason' => 'required|string|min:5|max:500']);
        $t = $service->cancel($request->user(), $transfer, $data['reason']);

        return response()->json(['data' => PresentOps::transferDetail($t->fresh(), $request->user())]);
    }

    // ------------------------------------------------------------------ returns

    public function returns(Request $request): JsonResponse
    {
        Gate::authorize('view-returns');
        $query = StockReturn::with('issue', 'project', 'store', 'receiver')
            ->when($request->filled('q'), fn ($q) => $q->where(fn ($w) => $w->where('return_no', 'like', $this->like($request->query('q')))
                ->orWhere('returned_by_name', 'like', $this->like($request->query('q')))))
            ->latest('id');

        return $this->paginated($request, $query, fn ($r) => PresentOps::returnSummary($r, $request->user()));
    }

    public function stockReturn(Request $request, StockReturn $return): JsonResponse
    {
        Gate::authorize('view-returns');

        return response()->json(['data' => PresentOps::returnDetail($return, $request->user())]);
    }

    public function storeReturn(Request $request, MaterialIssue $issue, StockReturnService $service): JsonResponse
    {
        Gate::authorize('record-return', $issue);
        $data = $request->validate([
            'qty' => 'required|array',
            'qty.*' => 'nullable|numeric|min:0',
            'store_id' => 'required|integer|exists:stores,id',
            'returned_by_name' => 'required|string|max:255',
            'reason' => 'required|string|min:5|max:1000',
            'idempotency_key' => 'nullable|string|max:64',
        ]);
        $r = $service->record($request->user(), $issue, $data['qty'], $data['returned_by_name'], $data['reason'], (int) $data['store_id'], $data['idempotency_key'] ?? null);

        return response()->json(['data' => PresentOps::returnDetail($r->fresh(), $request->user()), 'replayed' => ! $r->wasRecentlyCreated], $r->wasRecentlyCreated ? 201 : 200);
    }
}
