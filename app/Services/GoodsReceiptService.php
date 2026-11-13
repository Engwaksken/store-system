<?php

namespace App\Services;

use App\Enums\Role;
use App\Exceptions\WorkflowException;
use App\Models\PurchaseOrder;
use App\Models\StockReceipt;
use App\Models\Store;
use App\Models\User;
use App\Support\Decimal;
use App\Support\Idempotency;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;

/**
 * Goods received note (GRN) against an approved purchase order.
 * Lock order: PO row → PO lines → items → balances. Quantity per line ≤ ordered − already received.
 * Stock enters the PO's delivery store at the PO unit price (which updates the weighted average cost).
 */
class GoodsReceiptService
{
    public function __construct(private StockService $stock)
    {
    }

    /** @param array<int|string, mixed> $qtys purchase_order_line_id => qty received now */
    public function receive(User $user, PurchaseOrder $po, array $qtys, array $header = []): StockReceipt
    {
        if (! $user->is_active || ! $user->hasRole(Role::Storekeeper, Role::Admin)) {
            throw new AuthorizationException('Only the storekeeper or admin can receive goods.');
        }
        Store::assertOperable($user, $po->store_id);
        $wanted = [];
        foreach ($qtys as $lineId => $q) {
            if ($q === null || $q === '') {
                continue;
            }
            $q = Decimal::of($q, 3);
            if (bccomp($q, '0', 3) < 0) {
                throw new WorkflowException('Quantities cannot be negative.');
            }
            if (bccomp($q, '0', 3) > 0) {
                $wanted[(int) $lineId] = $q;
            }
        }
        if (! $wanted) {
            throw new WorkflowException('Enter a received quantity for at least one line.');
        }

        return Idempotency::run(StockReceipt::class, $header['idempotency_key'] ?? null, fn () => DB::transaction(function () use ($user, $po, $wanted, $header) {
            $po = PurchaseOrder::whereKey($po->id)->lockForUpdate()->firstOrFail();
            if (! $po->isReceivable()) {
                throw new WorkflowException("{$po->po_no} is {$po->statusLabel()} and cannot be received against.");
            }
            $lines = $po->lines()->with('item')->orderBy('id')->lockForUpdate()->get()->keyBy('id');
            $stockLines = [];
            foreach ($wanted as $lineId => $q) {
                $line = $lines->get($lineId) ?? throw new WorkflowException('Line does not belong to this purchase order.');
                if (bccomp($q, $line->outstanding(), 3) > 0) {
                    throw new WorkflowException("{$line->item->sku}: cannot receive {$q}; only {$line->outstanding()} still on order.");
                }
                $stockLines[] = ['item_id' => $line->item_id, 'qty' => $q, 'unit_cost' => (string) $line->unit_price, 'po_line_id' => $line->id];
            }

            $receipt = $this->stock->postReceipt($user, [
                'type' => 'purchase', 'store_id' => $po->store_id, 'received_on' => $header['received_on'] ?? now()->toDateString(),
                'supplier_name' => $po->supplier->name, 'reference' => $header['reference'] ?? $po->po_no, 'notes' => $header['notes'] ?? null,
                'idempotency_key' => $header['idempotency_key'] ?? null,
            ], $this->stock->normaliseLines($stockLines), ['supplier_id' => $po->supplier_id, 'purchase_order_id' => $po->id]);

            foreach ($wanted as $lineId => $q) {
                $line = $lines[$lineId];
                $line->forceFill(['received_qty' => bcadd((string) $line->received_qty, $q, 3)])->save();
            }
            $complete = $lines->every(fn ($l) => bccomp($l->outstanding(), '0', 3) <= 0);
            $po->forceFill(['status' => $complete ? 'received' : 'partially_received'])->save();

            return $receipt;
        }));
    }
}
