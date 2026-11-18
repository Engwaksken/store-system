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

    public function __construct(private PurchasingService $purchasing)
    {
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

            return $this->purchasing->submitRequisition($storekeeper, $pr);
        });
    }

    /** Who may raise a restock requisition from a request (the storekeeper who issues it). */
    public static function allowed(User $user, MaterialRequest $req): bool
    {
        return $user->role === Role::Storekeeper && $user->is_active && $req->status->isIssuable();
    }
}
