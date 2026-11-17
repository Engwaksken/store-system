<?php

namespace App\Http\Controllers\Api;

use App\Models\InventoryItem;
use App\Models\Store;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class StockController extends ApiController
{
    public function items(Request $request): JsonResponse
    {
        Gate::authorize('view-stock');
        $storeId = $request->integer('store_id') ?: null;
        $query = InventoryItem::with('category', 'unit', 'balances')
            ->when($request->filled('q'), fn ($q) => $q->where(fn ($w) => $w->where('sku', 'like', $this->like($request->query('q')))
                ->orWhere('name', 'like', $this->like($request->query('q')))))
            // With a store, list items held there; out-of-stock in a store also includes items never received there.
            ->when($storeId && ! $request->boolean('out'), fn ($q) => $q->whereHas('balances', fn ($b) => $b->where('store_id', $storeId)))
            // Same rule as the web "Out of stock" filter: active items with nothing on hand (in the chosen store, else in all stores).
            ->when($request->boolean('out'), fn ($q) => $q->where('is_active', true)->whereRaw(
                '(select coalesce(sum(b.on_hand), 0) from inventory_balances b where b.item_id = inventory_items.id'.($storeId ? ' and b.store_id = ?' : '').') <= 0',
                $storeId ? [$storeId] : [],
            ))
            ->when($request->boolean('active'), fn ($q) => $q->where('is_active', true))
            // Same rule as InventoryItem::isLowStock(): a minimum is set and the total across all stores is at or below it.
            ->when($request->boolean('low'), fn ($q) => $q->where('min_level', '>', 0)
                ->whereRaw('(select coalesce(sum(b.on_hand), 0) from inventory_balances b where b.item_id = inventory_items.id) <= inventory_items.min_level'))
            ->orderBy('name');

        return $this->paginated($request, $query, fn ($i) => Present::item($i, $request->user(), $storeId));
    }

    public function item(Request $request, InventoryItem $item): JsonResponse
    {
        Gate::authorize('view-stock');

        return response()->json(['data' => Present::item($item->load('category', 'unit', 'balances.store'), $request->user(), null, true)]);
    }

    public function stores(): JsonResponse
    {
        Gate::authorize('view-stock');

        return response()->json(['data' => Store::orderBy('id')->get(['id', 'code', 'name'])]);
    }
}
