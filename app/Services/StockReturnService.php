<?php

namespace App\Services;

use App\Enums\Role;
use App\Exceptions\WorkflowException;
use App\Models\ActivityLog;
use App\Models\DocumentSequence;
use App\Models\InventoryBalance;
use App\Models\InventoryItem;
use App\Models\MaterialIssue;
use App\Models\StockMovement;
use App\Models\StockReturn;
use App\Models\StockReturnLine;
use App\Models\Store;
use App\Models\User;
use App\Support\Decimal;
use App\Support\Idempotency;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;

/**
 * Returns of unused material from a project back into a store, always against the issue voucher.
 *
 * - Quantity returned per voucher line can never exceed quantity issued minus earlier returns
 *   (checked after locking the voucher row, so concurrent returns serialize).
 * - Valued at the voucher's unit cost snapshot, so the project is credited exactly what it was charged.
 * - Stock comes back in at that cost and the item's weighted average is recomputed.
 */
class StockReturnService
{
    public function __construct(private StockService $stock)
    {
    }

    /** @param array<int|string, mixed> $qtys material_issue_line_id => qty returned */
    public function record(User $actor, MaterialIssue $issue, array $qtys, string $returnedByName, string $reason, ?int $storeId = null, ?string $idempotencyKey = null): StockReturn
    {
        if (! $actor->is_active || ! $actor->hasRole(Role::Storekeeper, Role::Admin)) {
            throw new AuthorizationException('Only the storekeeper or admin can receive returns.');
        }
        $storeId ??= $issue->store_id;
        Store::assertOperable($actor, $storeId);
        if (trim($reason) === '' || trim($returnedByName) === '') {
            throw new WorkflowException('The name of the person returning and a reason are required.');
        }
        $wanted = [];
        foreach ($qtys as $lineId => $qty) {
            if ($qty === null || $qty === '') {
                continue;
            }
            $qty = Decimal::of($qty, 3);
            if (bccomp($qty, '0', 3) < 0) {
                throw new WorkflowException('Return quantities cannot be negative.');
            }
            if (bccomp($qty, '0', 3) > 0) {
                $wanted[(int) $lineId] = $qty;
            }
        }
        if (! $wanted) {
            throw new WorkflowException('Enter a quantity for at least one item.');
        }

        return Idempotency::run(StockReturn::class, $idempotencyKey, fn () => DB::transaction(function () use ($actor, $issue, $wanted, $returnedByName, $reason, $storeId, $idempotencyKey) {
            $issue = MaterialIssue::whereKey($issue->id)->lockForUpdate()->firstOrFail();
            $lines = $issue->lines()->with('returnLines', 'item')->get()->keyBy('id');
            $total = '0';
            foreach ($wanted as $lineId => $qty) {
                $line = $lines->get($lineId) ?? throw new WorkflowException('Line does not belong to this voucher.');
                $returnable = bcsub((string) $line->qty, $line->returnedQty(), 3);
                if (bccomp($qty, $returnable, 3) > 0) {
                    throw new WorkflowException("{$line->item->name}: cannot return {$qty}; only {$returnable} of this voucher line is still out.");
                }
                $total = bcadd($total, bcmul($qty, (string) $line->unit_cost_snapshot, 2), 2);
            }

            $itemIds = collect($wanted)->keys()->map(fn ($id) => $lines[$id]->item_id)->unique()->sort()->values()->all();
            $items = InventoryItem::whereIn('id', $itemIds)->orderBy('id')->lockForUpdate()->get()->keyBy('id');
            $balances = InventoryBalance::lockRows($storeId, $itemIds);

            $return = StockReturn::create([
                'return_no' => DocumentSequence::next('RET'), 'material_issue_id' => $issue->id, 'project_id' => $issue->project_id,
                'store_id' => $storeId, 'received_by' => $actor->id, 'returned_by_name' => trim($returnedByName),
                'reason' => $reason, 'total_value' => $total, 'returned_at' => now(), 'idempotency_key' => $idempotencyKey,
            ]);
            foreach ($wanted as $lineId => $qty) {
                $line = $lines[$lineId];
                $cost = (string) $line->unit_cost_snapshot;
                $this->stock->revalueOnReceipt($items[$line->item_id], $qty, $cost);
                $after = bcadd((string) $balances[$line->item_id]->on_hand, $qty, 3);
                $balances[$line->item_id]->update(['on_hand' => $after]);
                StockReturnLine::create([
                    'stock_return_id' => $return->id, 'material_issue_line_id' => $line->id, 'item_id' => $line->item_id,
                    'qty' => $qty, 'unit_cost' => $cost, 'line_value' => bcmul($qty, $cost, 2),
                ]);
                StockMovement::create([
                    'item_id' => $line->item_id, 'store_id' => $storeId, 'movement_type' => 'return', 'qty_delta' => $qty,
                    'unit_cost' => $cost, 'balance_after' => $after, 'project_id' => $issue->project_id,
                    'source_type' => 'stock_return', 'source_id' => $return->id, 'actor_id' => $actor->id,
                ]);
            }
            ActivityLog::record('stock.return', "Return {$return->return_no} against {$issue->issue_no} ({$returnedByName})", $return, ['value' => $total]);

            return $return;
        }));
    }
}
