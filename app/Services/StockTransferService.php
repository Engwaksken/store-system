<?php

namespace App\Services;

use App\Enums\Role;
use App\Exceptions\WorkflowException;
use App\Models\ActivityLog;
use App\Models\DocumentSequence;
use App\Models\InventoryBalance;
use App\Models\InventoryItem;
use App\Models\StockMovement;
use App\Models\StockTransfer;
use App\Models\StockTransferLine;
use App\Models\Store;
use App\Models\User;
use App\Support\Decimal;
use App\Support\Idempotency;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;

/**
 * Inter-store transfers with sending and receiving confirmation.
 *
 *   dispatch: source storekeeper sends goods → stock leaves the source (transfer_out), status in_transit.
 *   receive:  a different user assigned to the destination confirms quantities → stock enters the destination
 *             (transfer_in). Any shortfall needs a reason and is written off (recorded on the line).
 *   cancel:   while in transit, the dispatcher or admin reverses it → stock returns to the source (transfer_cancel).
 *
 * Reconciliation invariant per line: qty dispatched = qty received + shortfall (or fully reversed on cancel).
 */
class StockTransferService
{
    /** @param array<int, array{item_id:int, qty:string|float}> $lines */
    public function dispatch(User $actor, int $fromStoreId, int $toStoreId, array $lines, ?string $notes = null, ?string $idempotencyKey = null): StockTransfer
    {
        $this->assertStoreRole($actor);
        if ($fromStoreId === $toStoreId) {
            throw new WorkflowException('Source and destination stores must be different.');
        }
        Store::assertOperable($actor, $fromStoreId);
        if (! Store::whereKey($toStoreId)->where('is_active', true)->exists()) {
            throw new WorkflowException('The destination store must exist and be active.');
        }
        $quantities = [];
        foreach ($lines as $line) {
            $id = (int) $line['item_id'];
            if (isset($quantities[$id])) {
                throw new WorkflowException('Each item may appear only once per transfer.');
            }
            $qty = Decimal::of($line['qty'], 3);
            if (bccomp($qty, '0', 3) <= 0) {
                throw new WorkflowException('Transfer quantities must be positive.');
            }
            $quantities[$id] = $qty;
        }
        if (! $quantities) {
            throw new WorkflowException('Add at least one item to transfer.');
        }
        ksort($quantities);

        return Idempotency::run(StockTransfer::class, $idempotencyKey, fn () => DB::transaction(function () use ($actor, $fromStoreId, $toStoreId, $quantities, $notes, $idempotencyKey) {
            $items = InventoryItem::whereIn('id', array_keys($quantities))->orderBy('id')->lockForUpdate()->get()->keyBy('id');
            if ($items->count() !== count($quantities)) {
                throw new WorkflowException('Unknown inventory item.');
            }
            $balances = InventoryBalance::lockRows($fromStoreId, array_keys($quantities));
            foreach ($quantities as $itemId => $qty) {
                if (bccomp((string) $balances[$itemId]->on_hand, $qty, 3) < 0) {
                    throw new WorkflowException("Insufficient stock of {$items[$itemId]->sku} at the source store (on hand {$balances[$itemId]->on_hand}).");
                }
            }

            $transfer = StockTransfer::create([
                'transfer_no' => DocumentSequence::next('TR'), 'from_store_id' => $fromStoreId, 'to_store_id' => $toStoreId,
                'status' => 'in_transit', 'dispatched_by' => $actor->id, 'dispatched_at' => now(), 'notes' => $notes,
                'idempotency_key' => $idempotencyKey,
            ]);
            foreach ($quantities as $itemId => $qty) {
                $item = $items[$itemId];
                $after = bcsub((string) $balances[$itemId]->on_hand, $qty, 3);
                $balances[$itemId]->update(['on_hand' => $after]);
                StockTransferLine::create(['stock_transfer_id' => $transfer->id, 'item_id' => $itemId, 'qty' => $qty, 'unit_cost' => $item->avg_cost]);
                $this->move($actor, $transfer, $itemId, $fromStoreId, 'transfer_out', '-'.$qty, (string) $item->avg_cost, $after);
            }
            ActivityLog::record('transfer.dispatched', "Transfer {$transfer->transfer_no} dispatched", $transfer);

            return $transfer;
        }));
    }

    /**
     * @param array<int|string, mixed> $receivedQtys transfer_line_id => quantity received
     * @param array<int|string, ?string> $reasons transfer_line_id => reason (required where received < dispatched)
     */
    public function receive(User $actor, StockTransfer $transfer, array $receivedQtys, array $reasons = [], ?string $notes = null): StockTransfer
    {
        $this->assertStoreRole($actor);
        Store::assertOperable($actor, $transfer->to_store_id);
        if ($actor->id === $transfer->dispatched_by) {
            throw new AuthorizationException('The person who dispatched a transfer cannot also confirm its receipt.');
        }

        return DB::transaction(function () use ($actor, $transfer, $receivedQtys, $reasons, $notes) {
            $transfer = StockTransfer::whereKey($transfer->id)->lockForUpdate()->firstOrFail();
            if ($transfer->status !== 'in_transit') {
                throw new WorkflowException("Transfer {$transfer->transfer_no} is already {$transfer->statusLabel()}.");
            }
            $lines = $transfer->lines()->orderBy('item_id')->get();
            $plan = [];
            foreach ($lines as $line) {
                $raw = $receivedQtys[$line->id] ?? null;
                if ($raw === null || $raw === '') {
                    throw new WorkflowException("Enter the quantity received for every line (use 0 if nothing arrived).");
                }
                $got = Decimal::of($raw, 3);
                if (bccomp($got, '0', 3) < 0 || bccomp($got, (string) $line->qty, 3) > 0) {
                    throw new WorkflowException("Received quantity for {$line->item->sku} must be between 0 and {$line->qty} dispatched.");
                }
                $reason = trim((string) ($reasons[$line->id] ?? ''));
                if (bccomp($got, (string) $line->qty, 3) < 0 && $reason === '') {
                    throw new WorkflowException("Explain the shortfall for {$line->item->sku}.");
                }
                $plan[$line->id] = [$line, $got, $reason ?: null];
            }

            InventoryItem::whereIn('id', $lines->pluck('item_id'))->orderBy('id')->lockForUpdate()->get();
            $balances = InventoryBalance::lockRows($transfer->to_store_id, $lines->pluck('item_id')->all());
            foreach ($plan as [$line, $got, $reason]) {
                $line->update(['received_qty' => $got, 'shortfall_reason' => $reason]);
                if (bccomp($got, '0', 3) > 0) {
                    $after = bcadd((string) $balances[$line->item_id]->on_hand, $got, 3);
                    $balances[$line->item_id]->update(['on_hand' => $after]);
                    $this->move($actor, $transfer, $line->item_id, $transfer->to_store_id, 'transfer_in', $got, (string) $line->unit_cost, $after);
                }
            }
            $transfer->update(['status' => 'received', 'received_by' => $actor->id, 'received_at' => now(), 'receive_notes' => $notes]);
            $short = collect($plan)->filter(fn ($p) => bccomp($p[1], (string) $p[0]->qty, 3) < 0)->count();
            ActivityLog::record('transfer.received', "Transfer {$transfer->transfer_no} received".($short ? " with {$short} short line(s)" : ''), $transfer);

            return $transfer;
        });
    }

    public function cancel(User $actor, StockTransfer $transfer, string $reason): StockTransfer
    {
        $this->assertStoreRole($actor);
        if (! $actor->isAdmin() && $actor->id !== $transfer->dispatched_by) {
            throw new AuthorizationException('Only the dispatcher or the admin can cancel a transfer.');
        }
        if (trim($reason) === '') {
            throw new WorkflowException('A reason is required to cancel a transfer.');
        }

        return DB::transaction(function () use ($actor, $transfer, $reason) {
            $transfer = StockTransfer::whereKey($transfer->id)->lockForUpdate()->firstOrFail();
            if ($transfer->status !== 'in_transit') {
                throw new WorkflowException("Transfer {$transfer->transfer_no} is already {$transfer->statusLabel()}.");
            }
            $lines = $transfer->lines()->orderBy('item_id')->get();
            InventoryItem::whereIn('id', $lines->pluck('item_id'))->orderBy('id')->lockForUpdate()->get();
            $balances = InventoryBalance::lockRows($transfer->from_store_id, $lines->pluck('item_id')->all());
            foreach ($lines as $line) {
                $after = bcadd((string) $balances[$line->item_id]->on_hand, (string) $line->qty, 3);
                $balances[$line->item_id]->update(['on_hand' => $after]);
                $this->move($actor, $transfer, $line->item_id, $transfer->from_store_id, 'transfer_cancel', (string) $line->qty, (string) $line->unit_cost, $after);
            }
            $transfer->update(['status' => 'cancelled', 'received_by' => $actor->id, 'received_at' => now(), 'receive_notes' => 'Cancelled: '.$reason]);
            ActivityLog::record('transfer.cancelled', "Transfer {$transfer->transfer_no} cancelled: {$reason}", $transfer);

            return $transfer;
        });
    }

    private function move(User $actor, StockTransfer $t, int $itemId, int $storeId, string $type, string $delta, string $cost, string $after): void
    {
        StockMovement::create([
            'item_id' => $itemId, 'store_id' => $storeId, 'movement_type' => $type, 'qty_delta' => $delta,
            'unit_cost' => $cost, 'balance_after' => $after, 'project_id' => null,
            'source_type' => 'stock_transfer', 'source_id' => $t->id, 'actor_id' => $actor->id,
        ]);
    }

    private function assertStoreRole(User $actor): void
    {
        if (! $actor->is_active || ! $actor->hasRole(Role::Admin, Role::Storekeeper)) {
            throw new AuthorizationException('Only an active storekeeper or admin can move stock between stores.');
        }
    }
}
