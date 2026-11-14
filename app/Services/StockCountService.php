<?php

namespace App\Services;

use App\Enums\Role;
use App\Exceptions\WorkflowException;
use App\Models\ActivityLog;
use App\Models\DocumentSequence;
use App\Models\InventoryBalance;
use App\Models\InventoryItem;
use App\Models\StockCount;
use App\Models\StockCountLine;
use App\Models\Store;
use App\Models\User;
use App\Support\Decimal;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;

/**
 * Stock count & reconciliation.
 *
 *  create  – storekeeper (assigned to the store) or admin opens a count sheet; book quantities are frozen.
 *  record  – counted quantities and variance reasons are entered while in draft.
 *  submit  – every line counted; every non-zero variance has a reason.
 *  approve – Admin or Overall Manager, never the counter. Each variance posts as a stock adjustment
 *            (variance = counted − frozen book qty, applied to the live balance; never below zero).
 *  reject  – with a mandatory reason; nothing is posted.
 */
class StockCountService
{
    public function __construct(private StockService $stock)
    {
    }

    /** @param array<int>|null $itemIds null = every active item plus anything with stock in the store */
    public function create(User $actor, int $storeId, ?array $itemIds = null, ?string $notes = null): StockCount
    {
        if (! $actor->hasRole(Role::Admin, Role::Storekeeper)) {
            throw new AuthorizationException('Only the storekeeper or admin can start a stock count.');
        }
        Store::assertOperable($actor, $storeId);
        if (StockCount::where('store_id', $storeId)->whereIn('status', ['draft', 'submitted'])->exists()) {
            throw new WorkflowException('This store already has an open count. Finish or reject it first.');
        }

        return DB::transaction(function () use ($actor, $storeId, $itemIds, $notes) {
            $balances = InventoryBalance::where('store_id', $storeId)->pluck('on_hand', 'item_id');
            $ids = $itemIds ?? InventoryItem::where('is_active', true)->pluck('id')
                ->merge($balances->filter(fn ($q) => bccomp((string) $q, '0', 3) !== 0)->keys())->unique()->all();
            if (! $ids) {
                throw new WorkflowException('There are no items to count.');
            }
            $count = StockCount::create([
                'count_no' => DocumentSequence::next('SC'), 'store_id' => $storeId, 'status' => 'draft',
                'counted_by' => $actor->id, 'notes' => $notes,
            ]);
            foreach (collect($ids)->sort() as $itemId) {
                StockCountLine::create(['stock_count_id' => $count->id, 'item_id' => $itemId, 'system_qty' => $balances[$itemId] ?? 0]);
            }
            ActivityLog::record('count.created', "Stock count {$count->count_no} started", $count);

            return $count;
        });
    }

    /** @param array<int|string, array{counted_qty?:mixed, reason?:?string}> $entries line_id => values */
    public function record(User $actor, StockCount $count, array $entries): StockCount
    {
        $this->assertCounter($actor, $count);
        if ($count->status !== 'draft') {
            throw new WorkflowException('Only draft counts can be edited.');
        }
        DB::transaction(function () use ($count, $entries) {
            $lines = $count->lines()->get()->keyBy('id');
            foreach ($entries as $lineId => $e) {
                $line = $lines->get((int) $lineId) ?? throw new WorkflowException('Line does not belong to this count.');
                $raw = $e['counted_qty'] ?? null;
                $counted = ($raw === null || $raw === '') ? null : Decimal::of($raw, 3);
                if ($counted !== null && bccomp($counted, '0', 3) < 0) {
                    throw new WorkflowException('Counted quantities cannot be negative.');
                }
                $line->update(['counted_qty' => $counted, 'reason' => trim((string) ($e['reason'] ?? '')) ?: null]);
            }
        });

        return $count->fresh();
    }

    public function submit(User $actor, StockCount $count): StockCount
    {
        $this->assertCounter($actor, $count);

        return DB::transaction(function () use ($count) {
            $count = StockCount::whereKey($count->id)->lockForUpdate()->firstOrFail();
            if ($count->status !== 'draft') {
                throw new WorkflowException('Only draft counts can be submitted.');
            }
            foreach ($count->lines()->with('item')->get() as $line) {
                if ($line->counted_qty === null) {
                    throw new WorkflowException("Enter a counted quantity for {$line->item->sku} (0 if none).");
                }
                if (bccomp($line->variance(), '0', 3) !== 0 && ! $line->reason) {
                    throw new WorkflowException("Give a reason for the variance on {$line->item->sku}.");
                }
            }
            $count->update(['status' => 'submitted', 'submitted_at' => now()]);
            ActivityLog::record('count.submitted', "Stock count {$count->count_no} submitted for approval", $count);
            app(WorkflowNotifier::class)->countSubmitted($count);

            return $count;
        });
    }

    public function decide(User $actor, StockCount $count, string $decision, ?string $reason = null): StockCount
    {
        if (! $actor->is_active || ! $actor->hasRole(Role::Admin, Role::OverallManager)) {
            throw new AuthorizationException('Only the Owner/Admin or Overall Manager can approve stock counts.');
        }
        if ($actor->id === $count->counted_by) {
            throw new AuthorizationException('The person who counted cannot approve their own count.');
        }
        if (! in_array($decision, ['approved', 'rejected'], true)) {
            throw new WorkflowException('Unknown decision.');
        }
        if ($decision === 'rejected' && trim((string) $reason) === '') {
            throw new WorkflowException('A reason is required to reject a count.');
        }

        return DB::transaction(function () use ($actor, $count, $decision, $reason) {
            $count = StockCount::whereKey($count->id)->lockForUpdate()->firstOrFail();
            if ($count->status !== 'submitted') {
                throw new WorkflowException('Only submitted counts can be decided.');
            }
            if ($decision === 'approved') {
                foreach ($count->lines()->orderBy('item_id')->get() as $line) {
                    $variance = $line->variance();
                    if (bccomp($variance, '0', 3) !== 0) {
                        $this->stock->postAdjustment($actor, $line->item_id, $variance,
                            "Count {$count->count_no}: {$line->reason}", $count->store_id, $count->id);
                    }
                }
            }
            $count->update(['status' => $decision, 'decided_by' => $actor->id, 'decided_at' => now(), 'decision_reason' => $reason]);
            ActivityLog::record('count.'.$decision, "Stock count {$count->count_no} {$decision}", $count);
            app(WorkflowNotifier::class)->countDecided($count);

            return $count;
        });
    }

    private function assertCounter(User $actor, StockCount $count): void
    {
        if ($actor->id !== $count->counted_by && ! $actor->isAdmin()) {
            throw new AuthorizationException('Only the person who opened the count (or the admin) can edit or submit it.');
        }
    }
}
