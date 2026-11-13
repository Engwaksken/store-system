<?php

namespace App\Services;

use App\Enums\Role;
use App\Exceptions\WorkflowException;
use App\Models\ActivityLog;
use App\Models\AppSetting;
use App\Models\DocumentSequence;
use App\Models\InventoryItem;
use App\Models\Project;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderLine;
use App\Models\PurchaseRequisition;
use App\Models\PurchaseRequisitionLine;
use App\Models\Store;
use App\Models\Supplier;
use App\Models\SupplierQuote;
use App\Models\SupplierQuoteLine;
use App\Models\User;
use App\Support\Decimal;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;

/**
 * Purchasing approval policy (separate from material-issue approval):
 *
 *  Purchase requisition (PR): raised by Procurement, Storekeeper, Project Manager (own projects) or Admin;
 *     approved/rejected by Admin or Overall Manager, never by the requester. Rejection needs a reason.
 *  Quotations: recorded by Procurement/Admin on an approved PR. Selecting a quote that is not the cheapest
 *     needs a written justification.
 *  Purchase order (PO): raised by Procurement/Admin. Approved by Overall Manager or Admin, never by the PO's
 *     creator. POs above the owner threshold (setting `po_owner_threshold`) need the Owner/Admin.
 *     Approval uses an optimistic lock (lock_version) so two approvers cannot both act on a stale view.
 */
class PurchasingService
{
    public const DEFAULT_OWNER_THRESHOLD = '20000000';

    // ------------------------------------------------------------------ requisitions

    /** @param array<int, array{item_id:int, qty:mixed, est_unit_cost?:mixed, notes?:?string}> $lines */
    public function createRequisition(User $user, array $header, array $lines): PurchaseRequisition
    {
        if (! $user->is_active || ! $user->hasRole(Role::Admin, Role::Procurement, Role::Storekeeper, Role::ProjectManager)) {
            throw new AuthorizationException('You cannot raise purchase requisitions.');
        }
        $projectId = $header['project_id'] ?? null;
        if ($projectId && ! Project::visibleTo($user)->whereKey($projectId)->exists()) {
            throw new AuthorizationException('You are not assigned to that project.');
        }
        if ($user->role === Role::ProjectManager && ! $projectId) {
            throw new WorkflowException('Project managers raise requisitions for one of their projects.');
        }
        if (! Store::whereKey($header['store_id'])->where('is_active', true)->exists()) {
            throw new WorkflowException('Choose an active delivery store.');
        }
        $norm = [];
        foreach ($lines as $l) {
            $id = (int) $l['item_id'];
            if (isset($norm[$id])) {
                throw new WorkflowException('Each item may appear only once.');
            }
            $qty = Decimal::of($l['qty'], 3);
            $cost = Decimal::of($l['est_unit_cost'] ?? 0, 4);
            if (bccomp($qty, '0', 3) <= 0 || bccomp($cost, '0', 4) < 0) {
                throw new WorkflowException('Quantities must be positive and estimated costs cannot be negative.');
            }
            $norm[$id] = ['qty' => $qty, 'est_unit_cost' => $cost, 'notes' => $l['notes'] ?? null];
        }
        if (! $norm) {
            throw new WorkflowException('Add at least one line.');
        }

        return DB::transaction(function () use ($user, $header, $norm, $projectId) {
            $pr = PurchaseRequisition::create([
                'pr_no' => DocumentSequence::next('PR'), 'project_id' => $projectId, 'store_id' => $header['store_id'],
                'requested_by' => $user->id, 'purpose' => $header['purpose'], 'needed_on' => $header['needed_on'] ?? null,
                'estimated_total' => collect($norm)->reduce(fn ($c, $l) => bcadd($c, bcmul($l['qty'], $l['est_unit_cost'], 2), 2), '0'),
            ]);
            foreach ($norm as $itemId => $l) {
                PurchaseRequisitionLine::create(['purchase_requisition_id' => $pr->id, 'item_id' => $itemId] + $l);
            }
            ActivityLog::record('pr.created', "Purchase requisition {$pr->pr_no} drafted", $pr);

            return $pr;
        });
    }

    public function submitRequisition(User $user, PurchaseRequisition $pr): PurchaseRequisition
    {
        if ($user->id !== $pr->requested_by) {
            throw new AuthorizationException('Only the requester can submit this requisition.');
        }

        return $this->transitionPr($pr, ['draft'], 'submitted', fn ($p) => $p->forceFill(['submitted_at' => now()]), 'pr.submitted');
    }

    public function decideRequisition(User $user, PurchaseRequisition $pr, string $decision, ?string $reason): PurchaseRequisition
    {
        if (! $user->is_active || ! $user->hasRole(Role::Admin, Role::OverallManager)) {
            throw new AuthorizationException('Only the Owner/Admin or Overall Manager approves purchase requisitions.');
        }
        if ($user->id === $pr->requested_by) {
            throw new AuthorizationException('You cannot approve your own requisition.');
        }
        $this->assertDecision($decision, $reason);

        return $this->transitionPr($pr, ['submitted'], $decision,
            fn ($p) => $p->forceFill(['decided_by' => $user->id, 'decided_at' => now(), 'decision_reason' => $reason]), 'pr.'.$decision);
    }

    public function cancelRequisition(User $user, PurchaseRequisition $pr): PurchaseRequisition
    {
        if ($user->id !== $pr->requested_by && ! $user->hasRole(Role::Admin, Role::Procurement)) {
            throw new AuthorizationException('Only the requester or procurement can cancel this requisition.');
        }

        return $this->transitionPr($pr, ['draft', 'submitted', 'approved'], 'cancelled', fn ($p) => $p, 'pr.cancelled');
    }

    // ------------------------------------------------------------------ quotations

    /** @param array<int|string, mixed> $prices purchase_requisition_line_id => unit price */
    public function addQuote(User $user, PurchaseRequisition $pr, int $supplierId, array $prices, array $header = []): SupplierQuote
    {
        $this->assertBuyer($user);
        if ($pr->status !== 'approved') {
            throw new WorkflowException('Quotes can be recorded only on an approved requisition.');
        }
        if (! Supplier::whereKey($supplierId)->where('is_active', true)->exists()) {
            throw new WorkflowException('Choose an active supplier.');
        }
        if (SupplierQuote::where('purchase_requisition_id', $pr->id)->where('supplier_id', $supplierId)->exists()) {
            throw new WorkflowException('This supplier already has a quote on this requisition.');
        }
        $lines = $pr->lines()->get()->keyBy('id');

        return DB::transaction(function () use ($user, $pr, $supplierId, $prices, $header, $lines) {
            $total = '0';
            $rows = [];
            foreach ($lines as $lineId => $line) {
                $raw = $prices[$lineId] ?? null;
                if ($raw === null || $raw === '') {
                    throw new WorkflowException("Enter a unit price for {$line->item->sku}.");
                }
                $price = Decimal::of($raw, 4);
                if (bccomp($price, '0', 4) < 0) {
                    throw new WorkflowException('Prices cannot be negative.');
                }
                $rows[$lineId] = $price;
                $total = bcadd($total, bcmul((string) $line->qty, $price, 2), 2);
            }
            $quote = SupplierQuote::create([
                'purchase_requisition_id' => $pr->id, 'supplier_id' => $supplierId, 'quote_ref' => $header['quote_ref'] ?? null,
                'quoted_on' => $header['quoted_on'] ?? now()->toDateString(), 'valid_until' => $header['valid_until'] ?? null,
                'total' => $total, 'recorded_by' => $user->id,
            ]);
            foreach ($rows as $lineId => $price) {
                SupplierQuoteLine::create(['supplier_quote_id' => $quote->id, 'purchase_requisition_line_id' => $lineId, 'unit_price' => $price]);
            }
            ActivityLog::record('quote.recorded', "Quote from {$quote->supplier->name} on {$pr->pr_no}: {$total}", $quote);

            return $quote;
        });
    }

    public function selectQuote(User $user, SupplierQuote $quote, ?string $reason): SupplierQuote
    {
        $this->assertBuyer($user);
        $pr = $quote->requisition;
        if ($pr->status !== 'approved') {
            throw new WorkflowException('The requisition is no longer open for supplier selection.');
        }
        $cheapest = $pr->quotes()->min('total');
        if (bccomp((string) $quote->total, (string) $cheapest, 2) > 0 && trim((string) $reason) === '') {
            throw new WorkflowException('This is not the lowest quote. Give the reason for choosing it.');
        }

        return DB::transaction(function () use ($quote, $pr, $reason) {
            SupplierQuote::where('purchase_requisition_id', $pr->id)->update(['is_selected' => false, 'selection_reason' => null]);
            $quote->forceFill(['is_selected' => true, 'selection_reason' => $reason])->save();
            ActivityLog::record('quote.selected', "Supplier {$quote->supplier->name} selected for {$pr->pr_no}", $quote, ['reason' => $reason]);

            return $quote;
        });
    }

    // ------------------------------------------------------------------ purchase orders

    /** @param array<int, array{item_id:int, qty:mixed, unit_price:mixed}> $lines */
    public function createOrder(User $user, array $header, array $lines): PurchaseOrder
    {
        $this->assertBuyer($user);
        $pr = isset($header['purchase_requisition_id']) ? PurchaseRequisition::find($header['purchase_requisition_id']) : null;
        $quote = null;
        if ($pr) {
            if ($pr->status !== 'approved') {
                throw new WorkflowException("Requisition {$pr->pr_no} must be approved (and not already ordered) before a PO is raised.");
            }
            if ($pr->quotes()->exists()) {
                $quote = $pr->quotes()->where('is_selected', true)->with('lines')->first()
                    ?? throw new WorkflowException('Select one of the quotes before raising the PO.');
                if ((int) $header['supplier_id'] !== $quote->supplier_id) {
                    throw new WorkflowException('The PO supplier must be the selected quote\'s supplier.');
                }
            }
            $header['project_id'] = $pr->project_id;
            $header['store_id'] ??= $pr->store_id;
        }
        if (! Supplier::whereKey($header['supplier_id'])->where('is_active', true)->exists()) {
            throw new WorkflowException('Choose an active supplier.');
        }
        if (! Store::whereKey($header['store_id'])->where('is_active', true)->exists()) {
            throw new WorkflowException('Choose an active delivery store.');
        }

        $norm = [];
        foreach ($lines as $l) {
            $id = (int) $l['item_id'];
            if (isset($norm[$id])) {
                throw new WorkflowException('Each item may appear only once per PO.');
            }
            $qty = Decimal::of($l['qty'], 3);
            $price = Decimal::of($l['unit_price'], 4);
            if (bccomp($qty, '0', 3) <= 0 || bccomp($price, '0', 4) < 0) {
                throw new WorkflowException('Quantities must be positive and prices cannot be negative.');
            }
            $norm[$id] = ['qty' => $qty, 'unit_price' => $price];
        }
        if (! $norm) {
            throw new WorkflowException('Add at least one line.');
        }
        if (InventoryItem::whereIn('id', array_keys($norm))->count() !== count($norm)) {
            throw new WorkflowException('Unknown item.');
        }
        if ($quote) {
            // PO prices must match the selected quotation for every quoted item.
            $quoted = $quote->lines->mapWithKeys(fn ($ql) => [$ql->requisitionLine->item_id => (string) $ql->unit_price]);
            foreach ($norm as $itemId => $l) {
                if ($quoted->has($itemId) && bccomp($l['unit_price'], $quoted[$itemId], 4) !== 0) {
                    throw new WorkflowException('PO prices must match the selected quotation.');
                }
            }
        }

        return DB::transaction(function () use ($user, $header, $norm, $pr, $quote) {
            if ($pr) {
                $pr = PurchaseRequisition::whereKey($pr->id)->lockForUpdate()->first();
                if ($pr->status !== 'approved') {
                    throw new WorkflowException("Requisition {$pr->pr_no} was ordered or changed by someone else.");
                }
            }
            $total = collect($norm)->reduce(fn ($c, $l) => bcadd($c, bcmul($l['qty'], $l['unit_price'], 2), 2), '0');
            $po = PurchaseOrder::create([
                'po_no' => DocumentSequence::next('PO'), 'supplier_id' => $header['supplier_id'], 'purchase_requisition_id' => $pr?->id,
                'supplier_quote_id' => $quote?->id, 'project_id' => $header['project_id'] ?? null, 'store_id' => $header['store_id'],
                'order_date' => $header['order_date'] ?? now()->toDateString(), 'expected_on' => $header['expected_on'] ?? null,
                'total' => $total, 'terms' => $header['terms'] ?? null, 'notes' => $header['notes'] ?? null, 'created_by' => $user->id,
            ]);
            foreach ($norm as $itemId => $l) {
                PurchaseOrderLine::create(['purchase_order_id' => $po->id, 'item_id' => $itemId, 'qty' => $l['qty'],
                    'unit_price' => $l['unit_price'], 'line_total' => bcmul($l['qty'], $l['unit_price'], 2)]);
            }
            $pr?->forceFill(['status' => 'ordered'])->save();
            ActivityLog::record('po.created', "Purchase order {$po->po_no} raised ({$total})", $po);

            return $po;
        });
    }

    public function ownerThreshold(): string
    {
        return Decimal::of(AppSetting::get('po_owner_threshold', self::DEFAULT_OWNER_THRESHOLD) ?: self::DEFAULT_OWNER_THRESHOLD, 2);
    }

    public function canDecideOrder(User $user, PurchaseOrder $po): bool
    {
        if (! $user->is_active || $po->status !== 'pending_approval' || $user->id === $po->created_by) {
            return false;
        }
        if (bccomp((string) $po->total, $this->ownerThreshold(), 2) > 0) {
            return $user->isAdmin();
        }

        return $user->hasRole(Role::Admin, Role::OverallManager);
    }

    public function decideOrder(User $user, PurchaseOrder $po, string $decision, ?string $reason, ?int $expectedVersion = null): PurchaseOrder
    {
        if ($user->id === $po->created_by) {
            throw new AuthorizationException('You cannot approve a purchase order you raised.');
        }
        if (! $this->canDecideOrder($user, $po)) {
            throw new AuthorizationException(bccomp((string) $po->total, $this->ownerThreshold(), 2) > 0
                ? 'This PO is above the owner threshold and needs the Owner/Admin.'
                : 'You cannot decide this purchase order.');
        }
        $this->assertDecision($decision, $reason);

        return DB::transaction(function () use ($user, $po, $decision, $reason, $expectedVersion) {
            $updated = PurchaseOrder::whereKey($po->id)->where('status', 'pending_approval')
                ->when($expectedVersion !== null, fn ($q) => $q->where('lock_version', $expectedVersion))
                ->update(['status' => $decision, 'decided_by' => $user->id, 'decided_at' => now(), 'decision_reason' => $reason,
                    'lock_version' => DB::raw('lock_version + 1'), 'updated_at' => now()]);
            if ($updated !== 1) {
                throw new WorkflowException("{$po->po_no} was changed by someone else. Reload and try again.");
            }
            $po->refresh();
            if ($decision === 'rejected' && $po->purchase_requisition_id) {
                PurchaseRequisition::whereKey($po->purchase_requisition_id)->where('status', 'ordered')->update(['status' => 'approved']);
            }
            ActivityLog::record('po.'.$decision, "Purchase order {$po->po_no} {$decision}", $po, ['reason' => $reason]);

            return $po;
        });
    }

    public function cancelOrder(User $user, PurchaseOrder $po, string $reason): PurchaseOrder
    {
        $this->assertBuyer($user);
        if (trim($reason) === '') {
            throw new WorkflowException('A reason is required.');
        }

        return DB::transaction(function () use ($po, $reason) {
            $po = PurchaseOrder::whereKey($po->id)->lockForUpdate()->firstOrFail();
            if (! in_array($po->status, ['pending_approval', 'approved'], true) || $po->lines()->where('received_qty', '>', 0)->exists()) {
                throw new WorkflowException('Only orders with nothing received can be cancelled. Close it instead.');
            }
            $po->forceFill(['status' => 'cancelled', 'decision_reason' => $reason, 'lock_version' => $po->lock_version + 1])->save();
            if ($po->purchase_requisition_id) {
                PurchaseRequisition::whereKey($po->purchase_requisition_id)->where('status', 'ordered')->update(['status' => 'approved']);
            }
            ActivityLog::record('po.cancelled', "Purchase order {$po->po_no} cancelled: {$reason}", $po);

            return $po;
        });
    }

    /** Short-close a partially delivered PO: the supplier will not deliver the rest. */
    public function closeOrder(User $user, PurchaseOrder $po, string $reason): PurchaseOrder
    {
        $this->assertBuyer($user);
        if (trim($reason) === '') {
            throw new WorkflowException('A reason is required.');
        }

        return DB::transaction(function () use ($po, $reason) {
            $po = PurchaseOrder::whereKey($po->id)->lockForUpdate()->firstOrFail();
            if (! in_array($po->status, ['partially_received', 'received'], true)) {
                throw new WorkflowException('Only orders that have received goods can be closed.');
            }
            $po->forceFill(['status' => 'closed', 'decision_reason' => $reason, 'lock_version' => $po->lock_version + 1])->save();
            ActivityLog::record('po.closed', "Purchase order {$po->po_no} closed: {$reason}", $po);

            return $po;
        });
    }

    // ------------------------------------------------------------------ helpers

    private function transitionPr(PurchaseRequisition $pr, array $from, string $to, callable $mutate, string $action): PurchaseRequisition
    {
        return DB::transaction(function () use ($pr, $from, $to, $mutate, $action) {
            $pr = PurchaseRequisition::whereKey($pr->id)->lockForUpdate()->firstOrFail();
            if (! in_array($pr->status, $from, true)) {
                throw new WorkflowException("Requisition {$pr->pr_no} is {$pr->statusLabel()}; this action is not allowed now.");
            }
            $mutate($pr);
            $pr->forceFill(['status' => $to])->save();
            ActivityLog::record($action, "Purchase requisition {$pr->pr_no}: {$to}", $pr);

            return $pr;
        });
    }

    private function assertBuyer(User $user): void
    {
        if (! $user->is_active || ! $user->hasRole(Role::Admin, Role::Procurement)) {
            throw new AuthorizationException('Only Procurement or the Owner/Admin can do this.');
        }
    }

    private function assertDecision(string $decision, ?string $reason): void
    {
        if (! in_array($decision, ['approved', 'rejected'], true)) {
            throw new WorkflowException('Unknown decision.');
        }
        if ($decision === 'rejected' && trim((string) $reason) === '') {
            throw new WorkflowException('A reason is required when rejecting.');
        }
    }
}
