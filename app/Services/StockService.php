<?php

namespace App\Services;

use App\Enums\Role;
use App\Exceptions\WorkflowException;
use App\Models\ActivityLog;
use App\Models\DocumentSequence;
use App\Models\InventoryBalance;
use App\Models\InventoryItem;
use App\Models\StockAdjustment;
use App\Models\StockMovement;
use App\Models\StockReceipt;
use App\Models\StockReceiptLine;
use App\Models\Store;
use App\Models\User;
use App\Support\Decimal;
use App\Support\Idempotency;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;

/**
 * All stock increases (opening balances, receipts) and corrections (adjustments), per store.
 * Every change locks the balance row, writes one immutable stock_movements row and commits atomically.
 */
class StockService
{
    /**
     * @param  array{type:string, received_on:string, store_id?:int, supplier_name?:?string, reference?:?string, notes?:?string, idempotency_key?:?string}  $header
     * @param  array<int, array{item_id:int, qty:string|float, unit_cost:string|float}>  $lines
     * @param  array<string, mixed>  $extra  additional receipt columns (used by procurement GRNs)
     */
    public function receive(User $actor, array $header, array $lines, array $extra = []): StockReceipt
    {
        $this->assertStoreUser($actor);
        $storeId = (int) ($header['store_id'] ?? Store::DEFAULT_ID);
        Store::assertOperable($actor, $storeId);
        $lines = $this->normaliseLines($lines);

        return Idempotency::run(StockReceipt::class, $header['idempotency_key'] ?? null,
            fn () => DB::transaction(fn () => $this->postReceipt($actor, $header, $lines, $extra)));
    }

    /**
     * Writes the receipt, lines, balances, average cost and ledger. Must run inside a transaction; the caller has
     * already authorized the actor for the store (receive() or GoodsReceiptService).
     *
     * @param  array<int, array{qty:string, unit_cost:string, po_line_id?:int}>  $lines  normalised by normaliseLines()
     */
    public function postReceipt(User $actor, array $header, array $lines, array $extra = []): StockReceipt
    {
        $storeId = (int) ($header['store_id'] ?? Store::DEFAULT_ID);
        $type = $header['type'] === 'opening' ? 'opening' : 'purchase';
        $itemIds = array_keys($lines);
        $items = InventoryItem::whereIn('id', $itemIds)->orderBy('id')->lockForUpdate()->get()->keyBy('id');
        $balances = InventoryBalance::lockRows($storeId, $itemIds);

        if ($type === 'opening') {
            $already = StockMovement::where('store_id', $storeId)->whereIn('item_id', $itemIds)->pluck('item_id')->unique();
            if ($already->isNotEmpty()) {
                $skus = $items->only($already->all())->pluck('sku')->implode(', ');
                throw new WorkflowException("Opening balance not allowed: item(s) {$skus} already have stock history in this store. Use a receipt or adjustment.");
            }
        }

        $receipt = StockReceipt::create([
            'receipt_no' => DocumentSequence::next($type === 'opening' ? 'OB' : 'GRN'),
            'type' => $type,
            'store_id' => $storeId,
            'supplier_name' => $header['supplier_name'] ?? null,
            'reference' => $header['reference'] ?? null,
            'received_on' => $header['received_on'],
            'notes' => $header['notes'] ?? null,
            'total_value' => $this->total($lines),
            'received_by' => $actor->id,
            'idempotency_key' => $header['idempotency_key'] ?? null,
            ...$extra,
        ]);

        foreach ($lines as $itemId => $l) {
            $item = $items->get($itemId) ?? throw new WorkflowException('Unknown item.');
            $balance = $balances->get($itemId);
            $newOnHand = bcadd((string) $balance->on_hand, $l['qty'], 3);
            $this->revalueOnReceipt($item, $l['qty'], $l['unit_cost']);

            StockReceiptLine::create([
                'stock_receipt_id' => $receipt->id, 'item_id' => $itemId,
                'qty' => $l['qty'], 'unit_cost' => $l['unit_cost'],
                'line_total' => bcmul($l['qty'], $l['unit_cost'], 2),
                ...(isset($l['po_line_id']) ? ['purchase_order_line_id' => $l['po_line_id']] : []),
            ]);
            $balance->update(['on_hand' => $newOnHand]);

            StockMovement::create([
                'item_id' => $itemId, 'store_id' => $storeId, 'movement_type' => $type === 'opening' ? 'opening' : 'receipt',
                'qty_delta' => $l['qty'], 'unit_cost' => $l['unit_cost'], 'balance_after' => $newOnHand,
                'project_id' => null, 'source_type' => 'stock_receipt', 'source_id' => $receipt->id, 'actor_id' => $actor->id,
            ]);
        }

        ActivityLog::record('stock.'.$type, "Stock {$type} {$receipt->receipt_no} recorded", $receipt);

        return $receipt;
    }

    /**
     * Weighted average cost (MVP_SCOPE D7) is company-wide: it is recomputed from total on-hand across all
     * stores whenever stock comes in at a cost (receipts, returns). Transfers move stock at the current average.
     * Call inside a transaction with the item row locked.
     */
    public function revalueOnReceipt(InventoryItem $item, string $qty, string $unitCost): void
    {
        $onHand = $item->balances()->get()->reduce(fn ($c, $b) => bcadd($c, (string) $b->on_hand, 3), '0.000');
        $newTotal = bcadd($onHand, $qty, 3);
        $newAvg = bccomp($onHand, '0', 3) <= 0
            ? $unitCost
            : bcdiv(bcadd(bcmul($onHand, (string) $item->avg_cost, 6), bcmul($qty, $unitCost, 6), 6), $newTotal, 4);
        $item->forceFill(['avg_cost' => $newAvg])->save();
    }

    /** Signed correction with mandatory reason (MVP_SCOPE D10). Never allows negative stock. */
    public function adjust(User $actor, int $itemId, string $qtyDelta, string $reason, ?int $storeId = null): StockAdjustment
    {
        $this->assertStoreUser($actor);
        $storeId ??= Store::DEFAULT_ID;
        Store::assertOperable($actor, $storeId);

        return DB::transaction(fn () => $this->postAdjustment($actor, $itemId, $qtyDelta, $reason, $storeId));
    }

    /**
     * Writes one adjustment + ledger movement. Callers must run inside a transaction and have authorized
     * the actor (direct adjustments: storekeeper/admin of the store; count variances: the count approver).
     */
    public function postAdjustment(User $actor, int $itemId, string $qtyDelta, string $reason, int $storeId, ?int $stockCountId = null): StockAdjustment
    {
        $qtyDelta = Decimal::of($qtyDelta, 3);
        if (bccomp($qtyDelta, '0', 3) === 0) {
            throw new WorkflowException('Adjustment quantity cannot be zero.');
        }
        if (trim($reason) === '') {
            throw new WorkflowException('A reason is required for stock adjustments.');
        }
        $item = InventoryItem::findOrFail($itemId);
        $balance = InventoryBalance::lockRows($storeId, [$itemId])->get($itemId);
        $newOnHand = bcadd((string) $balance->on_hand, $qtyDelta, 3);
        if (bccomp($newOnHand, '0', 3) < 0) {
            throw new WorkflowException("Adjustment would make {$item->sku} negative (on hand {$balance->on_hand}).");
        }

        $adj = StockAdjustment::create([
            'adjustment_no' => DocumentSequence::next('ADJ'), 'item_id' => $itemId, 'store_id' => $storeId,
            'stock_count_id' => $stockCountId, 'qty_delta' => $qtyDelta, 'reason' => $reason, 'actor_id' => $actor->id,
        ]);
        $balance->update(['on_hand' => $newOnHand]);
        StockMovement::create([
            'item_id' => $itemId, 'store_id' => $storeId, 'movement_type' => 'adjustment', 'qty_delta' => $qtyDelta,
            'unit_cost' => $item->avg_cost, 'balance_after' => $newOnHand, 'project_id' => null,
            'source_type' => 'stock_adjustment', 'source_id' => $adj->id, 'actor_id' => $actor->id,
        ]);
        ActivityLog::record('stock.adjustment', "Adjustment {$adj->adjustment_no}: {$item->sku} {$qtyDelta} ({$reason})", $adj);

        return $adj;
    }

    private function assertStoreUser(User $actor): void
    {
        if (! $actor->is_active || ! $actor->hasRole(Role::Admin, Role::Storekeeper)) {
            throw new AuthorizationException('Only the storekeeper or admin can change stock.');
        }
    }

    /** @return array<int, array{qty:string, unit_cost:string, po_line_id?:int}> keyed and sorted by item_id */
    public function normaliseLines(array $lines): array
    {
        $out = [];
        foreach ($lines as $l) {
            $id = (int) $l['item_id'];
            if (isset($out[$id])) {
                throw new WorkflowException('Each item may appear only once per receipt.');
            }
            $qty = Decimal::of($l['qty'], 3);
            $cost = Decimal::of($l['unit_cost'], 4);
            if (bccomp($qty, '0', 3) <= 0 || bccomp($cost, '0', 4) < 0) {
                throw new WorkflowException('Receipt quantities must be positive and costs cannot be negative.');
            }
            $out[$id] = ['qty' => $qty, 'unit_cost' => $cost] + (isset($l['po_line_id']) ? ['po_line_id' => (int) $l['po_line_id']] : []);
        }
        if (! $out) {
            throw new WorkflowException('Add at least one line.');
        }
        ksort($out);

        return $out;
    }

    private function total(array $lines): string
    {
        $t = '0';
        foreach ($lines as $l) {
            $t = bcadd($t, bcmul($l['qty'], $l['unit_cost'], 4), 4);
        }

        return bcadd($t, '0', 2);
    }
}
