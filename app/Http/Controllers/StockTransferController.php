<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreTransferRequest;
use App\Models\InventoryItem;
use App\Models\StockTransfer;
use App\Models\Store;
use App\Services\StockTransferService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class StockTransferController extends Controller
{
    public function index(Request $request)
    {
        Gate::authorize('viewAny', StockTransfer::class);
        $transfers = StockTransfer::visibleTo($request->user())->with(['fromStore', 'toStore', 'dispatcher'])->withCount('lines')
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->status))
            ->latest('id')->paginate(25)->withQueryString();

        $counts = StockTransfer::visibleTo($request->user())->toBase()->selectRaw('status, count(*) as n')->groupBy('status')->pluck('n', 'status');
        $stats = ['total' => (int) $counts->sum(), 'in_transit' => (int) ($counts['in_transit'] ?? 0), 'received' => (int) ($counts['received'] ?? 0), 'cancelled' => (int) ($counts['cancelled'] ?? 0)];

        return view('transfers.index', compact('transfers', 'stats'));
    }

    public function create(Request $request)
    {
        Gate::authorize('create', StockTransfer::class);
        $from = Store::operableBy($request->user())->orderBy('id')->get();
        $fromId = (int) $request->query('from_store_id', $from->first()?->id);

        return view('transfers.create', [
            'fromStores' => $from,
            'fromId' => $fromId,
            'toStores' => Store::where('is_active', true)->orderBy('id')->get(),
            'items' => InventoryItem::with(['unit', 'balances' => fn ($q) => $q->where('store_id', $fromId)])->where('is_active', true)->orderBy('name')->get(),
        ]);
    }

    public function store(StoreTransferRequest $request, StockTransferService $service)
    {
        $data = $request->validated();
        $lines = array_values(array_filter($data['lines'], fn ($l) => ! empty($l['item_id'])));
        $transfer = $service->dispatch($request->user(), (int) $data['from_store_id'], (int) $data['to_store_id'], $lines,
            $data['notes'] ?? null, $data['idempotency_key'] ?? null);

        return redirect()->route('transfers.show', $transfer)->with('status', $transfer->wasRecentlyCreated
            ? "Transfer {$transfer->transfer_no} dispatched. The receiving store must confirm quantities on arrival."
            : "Transfer {$transfer->transfer_no} was already recorded; it was not posted twice.");
    }

    public function show(StockTransfer $transfer)
    {
        Gate::authorize('view', $transfer);

        return view('transfers.show', ['transfer' => $transfer->load(['fromStore', 'toStore', 'dispatcher', 'receiver', 'lines.item.unit'])]);
    }

    public function receive(Request $request, StockTransfer $transfer, StockTransferService $service)
    {
        Gate::authorize('receive', $transfer);
        $data = $request->validate([
            'received' => 'required|array',
            'received.*' => 'required|numeric|min:0',
            'reason' => 'nullable|array',
            'reason.*' => 'nullable|string|max:500',
            'receive_notes' => 'nullable|string|max:1000',
        ]);
        $service->receive($request->user(), $transfer, $data['received'], $data['reason'] ?? [], $data['receive_notes'] ?? null);

        return redirect()->route('transfers.show', $transfer)->with('status', "Transfer {$transfer->transfer_no} received into stock.");
    }

    public function cancel(Request $request, StockTransfer $transfer, StockTransferService $service)
    {
        Gate::authorize('cancel', $transfer);
        $data = $request->validate(['cancel_reason' => 'required|string|min:5|max:500']);
        $service->cancel($request->user(), $transfer, $data['cancel_reason']);

        return redirect()->route('transfers.show', $transfer)->with('status', 'Transfer cancelled; stock returned to the source store.');
    }
}
