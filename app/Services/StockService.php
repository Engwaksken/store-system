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
use App\Models\User;
use App\Support\Decimal;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;

/**
 * All stock increases (opening balances, receipts) and corrections (adjustments).
 * Every change locks the balance row, writes one immutable stock_movements row and commits atomically.
 */
class StockService
{
    /**
     * @param  array{type:string, received_on:string, supplier_name?:?string, reference?:?string, notes?:?string}  $header
     * @param  array<int, array{item_id:int, qty:string|float, unit_cost:string|float}>  $lines
     */
    public function receive(User $actor, array $header, array $lines): StockReceipt
    {
        $this->assertStoreUser($actor);
        $type = $header['type'] === 'opening' ? 'opening' : 'purchase';
        $lines = $this->normaliseLines($lines);

        return DB::transaction(function () use ($actor, $header, $lines, $type) {
            $itemIds = array_keys($lines);
            $balances = InventoryBalance::whereIn('item_id', $itemIds)->orderBy('item_id')->lockForUpdate()->get()->keyBy('item_id');
            $items = InventoryItem::whereIn('id', $itemIds)->lockForUpdate()->get()->keyBy('id');

            if ($type === 'opening') {
                $already = StockMovement::whereIn('item_id', $itemIds)->pluck('item_id')->unique();
                if ($already->isNotEmpty()) {
                    $skus = $items->only($already->all())->pluck('sku')->implode(', ');
                    throw new WorkflowException("Opening balance not allowed: item(s) {$skus} already have stock history. Use a receipt or adjustment.");
                }
            }

            $receipt = StockReceipt::create([
                'receipt_no' => DocumentSequence::next($type === 'opening' ? 'OB' : 'GRN'),
                'type' => $type,
                'supplier_name' => $header['supplier_name'] ?? null,
                'reference' => $header['reference'] ?? null,
                'received_on' => $header['received_on'],
                'notes' => $header['notes'] ?? null,
                'total_value' => $this->total($lines),
                'received_by' => $actor->id,
            ]);

            foreach ($lines as $itemId => $l) {
                $item = $items->get($itemId) ?? throw new WorkflowException('Unknown item.');
                $balance = $balances->get($itemId);
                $onHand = (string) $balance->on_hand;
                $newOnHand = bcadd($onHand, $l['qty'], 3);

                // Weighted average cost (MVP_SCOPE D7).
                $newAvg = bccomp($onHand, '0', 3) <= 0
                    ? $l['unit_cost']
                    : bcdiv(bcadd(bcmul($onHand, (string) $item->avg_cost, 6), bcmul($l['qty'], $l['unit_cost'], 6), 6), $newOnHand, 4);

                StockReceiptLine::create([
                    'stock_receipt_id' => $receipt->id, 'item_id' => $itemId,
                    'qty' => $l['qty'], 'unit_cost' => $l['unit_cost'],
                    'line_total' => bcmul($l['qty'], $l['unit_cost'], 2),
                ]);
                $balance->update(['on_hand' => $newOnHand]);
                $item->forceFill(['avg_cost' => $newAvg])->save();

                StockMovement::create([
                    'item_id' => $itemId, 'movement_type' => $type === 'opening' ? 'opening' : 'receipt',
                    'qty_delta' => $l['qty'], 'unit_cost' => $l['unit_cost'], 'balance_after' => $newOnHand,
                    'project_id' => null, 'source_type' => 'stock_receipt', 'source_id' => $receipt->id, 'actor_id' => $actor->id,
                ]);
            }

            ActivityLog::record('stock.'.$type, "Stock {$type} {$receipt->receipt_no} recorded", $receipt);

            return $receipt;
        });
    }

    /** Signed correction with mandatory reason (MVP_SCOPE D10). Never allows negative stock. */
    public function adjust(User $actor, int $itemId, string $qtyDelta, string $reason): StockAdjustment
    {
        $this->assertStoreUser($actor);
        $qtyDelta = $this->dec($qtyDelta, 3);
        if (bccomp($qtyDelta, '0', 3) === 0) {
            throw new WorkflowException('Adjustment quantity cannot be zero.');
        }
        if (trim($reason) === '') {
            throw new WorkflowException('A reason is required for stock adjustments.');
        }

        return DB::transaction(function () use ($actor, $itemId, $qtyDelta, $reason) {
            $balance = InventoryBalance::where('item_id', $itemId)->lockForUpdate()->firstOrFail();
            $item = InventoryItem::findOrFail($itemId);
            $newOnHand = bcadd((string) $balance->on_hand, $qtyDelta, 3);
            if (bccomp($newOnHand, '0', 3) < 0) {
                throw new WorkflowException("Adjustment would make {$item->sku} negative (on hand {$balance->on_hand}).");
            }

            $adj = StockAdjustment::create([
                'adjustment_no' => DocumentSequence::next('ADJ'), 'item_id' => $itemId,
                'qty_delta' => $qtyDelta, 'reason' => $reason, 'actor_id' => $actor->id,
            ]);
            $balance->update(['on_hand' => $newOnHand]);
            StockMovement::create([
                'item_id' => $itemId, 'movement_type' => 'adjustment', 'qty_delta' => $qtyDelta,
                'unit_cost' => $item->avg_cost, 'balance_after' => $newOnHand, 'project_id' => null,
                'source_type' => 'stock_adjustment', 'source_id' => $adj->id, 'actor_id' => $actor->id,
            ]);
            ActivityLog::record('stock.adjustment', "Adjustment {$adj->adjustment_no}: {$item->sku} {$qtyDelta} ({$reason})", $adj);

            return $adj;
        });
    }

    private function assertStoreUser(User $actor): void
    {
        if (! $actor->hasRole(Role::Admin, Role::Storekeeper)) {
            throw new AuthorizationException('Only the storekeeper or admin can change stock.');
        }
    }

    /** @return array<int, array{qty:string, unit_cost:string}> keyed and sorted by item_id */
    private function normaliseLines(array $lines): array
    {
        $out = [];
        foreach ($lines as $l) {
            $id = (int) $l['item_id'];
            if (isset($out[$id])) {
                throw new WorkflowException('Each item may appear only once per receipt.');
            }
            $qty = $this->dec($l['qty'], 3);
            $cost = $this->dec($l['unit_cost'], 4);
            if (bccomp($qty, '0', 3) <= 0 || bccomp($cost, '0', 4) < 0) {
                throw new WorkflowException('Receipt quantities must be positive and costs cannot be negative.');
            }
            $out[$id] = ['qty' => $qty, 'unit_cost' => $cost];
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

    private function dec(mixed $v, int $scale): string
    {
        return Decimal::of($v, $scale);
    }
}
