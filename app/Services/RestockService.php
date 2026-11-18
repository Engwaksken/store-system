<?php

namespace App\Services;

use App\Enums\Role;
use App\Models\ActivityLog;
use App\Models\InventoryBalance;
use App\Models\MaterialRequest;
use App\Models\PurchaseRequisition;
use App\Models\PurchaseRequisitionLine;
use App\Models\Store;
use App\Models\User;
use App\Support\Decimal;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * Restocking after a short issue.
 *
 * When an approved request asks for more than the store holds, the storekeeper issues what is in stock and the
 * balance stays outstanding on the request. For every such line this raises (and submits) one purchase requisition
 * to the Overall Manager / Owner for the delivery store, linked to the request. Quantity per item:
 *
 *     balance = outstanding on the request - on hand in the store (what is left after issuing all the stock)
 *     restock = max(balance, the item's minimum stock level)
 *
 * Items already on an open restock requisition for the same request are not asked for twice.
 */
class RestockService
{
    public const OPEN = ['draft', 'submitted', 'approved'];

    public function __construct(private PurchasingService $purchasing, private WorkflowNotifier $notifier)
    {
    }

    /** False until the restock migration has run on this server; issuing then works without restock requisitions. */
    public static function available(): bool
    {
        static $ok = null;

        return $ok ??= Schema::hasColumn('purchase_requisitions', 'material_request_id');
    }

    /**
     * What the requester sees: each restock requisition with its status and any purchase orders raised from it.
     *
     * @return list<array{pr_no:string, status:string, status_label:string, status_tone:string, stage:string, orders:list<array{po_no:string, status_label:string}>, updated_at:?string}>
     */
    public function tracking(MaterialRequest $req): array
    {
        if (! self::available()) {
            return [];
        }

        return $req->restockRequisitions()->with('purchaseOrders')->get()->map(fn (PurchaseRequisition $pr) => [
            'id' => $pr->id, 'pr_no' => $pr->pr_no, 'status' => $pr->status, 'status_label' => $pr->statusLabel(), 'status_tone' => $pr->tone(),
            'stage' => self::stage($pr),
            'orders' => $pr->purchaseOrders->map(fn ($po) => ['po_no' => $po->po_no, 'status' => $po->status, 'status_label' => $po->statusLabel()])->values()->all(),
            'updated_at' => $pr->updated_at?->toIso8601String(),
        ])->values()->all();
    }

    /** Plain-language step for the requester. */
    public static function stage(PurchaseRequisition $pr): string
    {
        $po = $pr->relationLoaded('purchaseOrders') ? $pr->purchaseOrders->sortByDesc('id')->first() : $pr->purchaseOrders()->latest('id')->first();
        if ($po && in_array($po->status, ['received', 'closed'], true)) {
            return 'Stock delivered to the store; the storekeeper can now issue the balance.';
        }
        if ($po && $po->status === 'partially_received') {
            return 'Part of the order has been delivered.';
        }
        if ($po && $po->status === 'approved') {
            return 'Ordered from the supplier; waiting for delivery.';
        }

        return match ($pr->status) {
            'submitted', 'draft' => 'Waiting for the Overall Manager to approve the restock.',
            'approved' => 'Restock approved; procurement is getting quotes and ordering.',
            'ordered' => 'Purchase order raised; waiting for approval or delivery.',
            'rejected' => 'Restock was not approved'.($pr->decision_reason ? ": {$pr->decision_reason}" : '.'),
            default => 'Restock cancelled.',
        };
    }

    /** Lines of the request the store cannot cover: [request_line_id => [line, balance not covered, on_hand, restock_qty]]. */
    public function shortfall(MaterialRequest $req, int $storeId): array
    {
        $lines = $req->lines()->with('item')->orderBy('id')->get();
        $onHand = InventoryBalance::where('store_id', $storeId)->whereIn('item_id', $lines->pluck('item_id'))->pluck('on_hand', 'item_id');
        $out = [];
        foreach ($lines as $line) {
            $outstanding = $line->outstanding();
            $have = Decimal::of($onHand[$line->item_id] ?? 0, 3);
            if (Decimal::cmp($outstanding, '0') <= 0 || Decimal::cmp($have, $outstanding) >= 0) {
                continue;
            }
            $balance = bcsub($outstanding, $have, 3);
            $min = Decimal::of($line->item->min_level ?? 0, 3);
            $out[$line->id] = [$line, $balance, $have, Decimal::cmp($min, $balance) > 0 ? $min : $balance];
        }

        return $out;
    }

    /** Raise and submit the restock requisition for what the store is short of; null when nothing is short or already asked for. */
    public function requestFor(User $storekeeper, MaterialRequest $req, int $storeId): ?PurchaseRequisition
    {
        if (! self::available()) {
            Log::warning('Restock skipped: run php artisan migrate (purchase_requisitions.material_request_id is missing).');

            return null;
        }
        $short = $this->shortfall($req, $storeId);
        $already = PurchaseRequisitionLine::whereHas('requisition', fn ($q) => $q->where('material_request_id', $req->id)
            ->where('store_id', $storeId)->whereIn('status', self::OPEN))->pluck('item_id')->flip();
        $lines = [];
        foreach ($short as [$line, $balance, , $qty]) {
            if (! isset($already[$line->item_id])) {
                $lines[] = ['item_id' => $line->item_id, 'qty' => $qty, 'est_unit_cost' => (string) $line->item->avg_cost,
                    'notes' => "Not in stock for {$req->request_no}: {$balance}".(Decimal::cmp($qty, $balance) > 0 ? ' (raised to the minimum level)' : '')];
            }
        }
        if (! $lines) {
            return null;
        }
        $store = Store::findOrFail($storeId);

        return DB::transaction(function () use ($storekeeper, $req, $store, $lines) {
            $pr = $this->purchasing->createRequisition($storekeeper, [
                'store_id' => $store->id, 'project_id' => null, 'needed_on' => $req->needed_on?->toDateString(),
                'purpose' => "Restock {$store->code}: not enough stock to issue {$req->request_no} ({$req->project->code}) in full.",
            ], $lines);
            $pr->forceFill(['material_request_id' => $req->id])->save();
            ActivityLog::record('pr.restock', "Restock requisition {$pr->pr_no} raised for {$req->request_no}", $pr);

            $pr = $this->purchasing->submitRequisition($storekeeper, $pr);
            $this->notifier->send(\App\Models\User::whereKey($req->requester_id)->get(), "Restock requested for {$req->request_no}",
                "Some items on {$req->request_no} are being restocked ({$pr->pr_no}). Follow progress on the request; the store issues the balance when stock arrives.",
                route('requests.show', $req), 'Track request');

            return $pr;
        });
    }

    /** Who may raise a restock requisition from a request (the storekeeper who issues it). */
    public static function allowed(User $user, MaterialRequest $req): bool
    {
        return $user->role === Role::Storekeeper && $user->is_active && $req->status->isIssuable();
    }
}
