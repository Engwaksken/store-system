<?php

namespace App\Http\Controllers\Api;

use App\Models\MaterialIssue;
use App\Models\PurchaseOrder;
use App\Models\PurchaseRequisition;
use App\Models\StockReceipt;
use App\Models\StockReturn;
use App\Models\StockTransfer;
use App\Models\Store;
use App\Models\User;
use App\Services\PurchasingService;
use Illuminate\Support\Facades\Gate;

/**
 * JSON shapes for store operations and purchasing on the mobile API (docs/API.md): issue vouchers, receipts,
 * transfers, returns, purchase requisitions and orders. Stock prices (unit cost, values) are included only for
 * viewers allowed `view-stock-value` (Owner/Admin, Overall Manager); purchasing amounts follow the web pages.
 */
final class PresentOps
{
    public static function store(?Store $s): ?array
    {
        return $s ? ['id' => $s->id, 'code' => $s->code, 'name' => $s->name] : null;
    }

    private static function item($i): array
    {
        return ['id' => $i->id, 'sku' => $i->sku, 'name' => $i->name, 'unit' => $i->unit?->abbreviation];
    }

    private static function values(User $viewer): bool
    {
        return Gate::forUser($viewer)->allows('view-stock-value');
    }

    // ------------------------------------------------------------------ issue vouchers

    public static function issueSummary(MaterialIssue $i, User $viewer): array
    {
        $row = [
            'id' => $i->id, 'issue_no' => $i->issue_no,
            'request' => $i->request ? ['id' => $i->request->id, 'request_no' => $i->request->request_no] : null,
            'project' => Present::project($i->project), 'store' => self::store($i->store),
            'issued_at' => Present::time($i->issued_at), 'issuer' => Present::person($i->issuer),
            'recipient_name' => $i->recipient_name, 'acknowledged' => $i->acknowledgment !== null,
            'lines_count' => $i->lines_count ?? ($i->relationLoaded('lines') ? $i->lines->count() : $i->lines()->count()),
        ];
        if (self::values($viewer)) {
            $row['total_value'] = Present::money($i->total_value);
        }

        return $row;
    }

    public static function issueDetail(MaterialIssue $i, User $viewer): array
    {
        $i->loadMissing('project', 'store', 'request', 'issuer', 'lines.item.unit', 'lines.returnLines', 'acknowledgment.user', 'returns');
        $values = self::values($viewer);
        $as = Gate::forUser($viewer);

        return self::issueSummary($i, $viewer) + [
            'notes' => $i->notes,
            'lines' => $i->lines->map(fn ($l) => array_filter([
                'id' => $l->id, 'item' => self::item($l->item), 'qty' => Present::qty($l->qty), 'returned_qty' => Present::qty($l->returnedQty()),
                'unit_cost' => $values ? Present::money($l->unit_cost_snapshot) : null, 'line_value' => $values ? Present::money($l->line_value) : null,
            ], fn ($v) => $v !== null))->values()->all(),
            'acknowledgment' => $i->acknowledgment ? [
                'recipient_name' => $i->acknowledgment->recipient_name, 'remarks' => $i->acknowledgment->remarks,
                'by' => Present::person($i->acknowledgment->user), 'at' => Present::time($i->acknowledgment->acknowledged_at),
            ] : null,
            'returns' => $i->returns->map(fn ($r) => ['id' => $r->id, 'return_no' => $r->return_no, 'returned_at' => Present::time($r->returned_at)])->values()->all(),
            'can' => [
                'acknowledge' => $i->acknowledgment === null && $as->allows('acknowledge-issue', $i),
                'return' => $as->allows('record-return', $i),
            ],
        ];
    }

    // ------------------------------------------------------------------ receipts

    public static function receiptSummary(StockReceipt $r, User $viewer): array
    {
        $row = [
            'id' => $r->id, 'receipt_no' => $r->receipt_no, 'type' => $r->type, 'store' => self::store($r->store),
            'received_on' => $r->received_on?->toDateString(), 'supplier_name' => $r->supplier_name, 'reference' => $r->reference,
            'purchase_order' => $r->purchaseOrder ? ['id' => $r->purchaseOrder->id, 'po_no' => $r->purchaseOrder->po_no] : null,
            'receiver' => Present::person($r->receiver),
            'lines_count' => $r->lines_count ?? $r->lines()->count(),
        ];
        if (self::values($viewer)) {
            $row['total_value'] = Present::money($r->total_value);
        }

        return $row;
    }

    public static function receiptDetail(StockReceipt $r, User $viewer): array
    {
        $r->loadMissing('store', 'receiver', 'purchaseOrder', 'lines.item.unit');
        $values = self::values($viewer);

        return self::receiptSummary($r, $viewer) + [
            'notes' => $r->notes,
            'lines' => $r->lines->map(fn ($l) => array_filter([
                'id' => $l->id, 'item' => self::item($l->item), 'qty' => Present::qty($l->qty),
                'unit_cost' => $values ? Present::money($l->unit_cost) : null, 'line_total' => $values ? Present::money($l->line_total) : null,
            ], fn ($v) => $v !== null))->values()->all(),
        ];
    }

    // ------------------------------------------------------------------ transfers

    public static function transferSummary(StockTransfer $t): array
    {
        return [
            'id' => $t->id, 'transfer_no' => $t->transfer_no, 'status' => $t->status, 'status_label' => $t->statusLabel(), 'status_tone' => $t->tone(),
            'from_store' => self::store($t->fromStore), 'to_store' => self::store($t->toStore),
            'dispatched_at' => Present::time($t->dispatched_at), 'dispatcher' => Present::person($t->dispatcher),
            'received_at' => Present::time($t->received_at), 'receiver' => Present::person($t->receiver),
            'lines_count' => $t->lines_count ?? $t->lines()->count(),
        ];
    }

    public static function transferDetail(StockTransfer $t, User $viewer): array
    {
        $t->loadMissing('fromStore', 'toStore', 'dispatcher', 'receiver', 'lines.item.unit');
        $as = Gate::forUser($viewer);
        $values = self::values($viewer);

        return self::transferSummary($t) + [
            'notes' => $t->notes, 'receive_notes' => $t->receive_notes,
            'lines' => $t->lines->map(fn ($l) => array_filter([
                'id' => $l->id, 'item' => self::item($l->item), 'qty' => Present::qty($l->qty),
                'received_qty' => Present::qty($l->received_qty), 'shortfall_reason' => $l->shortfall_reason,
                'unit_cost' => $values ? Present::money($l->unit_cost) : null,
            ], fn ($v) => $v !== null))->values()->all(),
            'can' => ['receive' => $as->allows('receive', $t), 'cancel' => $as->allows('cancel', $t)],
        ];
    }

    // ------------------------------------------------------------------ returns

    public static function returnSummary(StockReturn $r, User $viewer): array
    {
        $row = [
            'id' => $r->id, 'return_no' => $r->return_no, 'returned_at' => Present::time($r->returned_at),
            'issue' => $r->issue ? ['id' => $r->issue->id, 'issue_no' => $r->issue->issue_no] : null,
            'project' => Present::project($r->project), 'store' => self::store($r->store),
            'returned_by_name' => $r->returned_by_name, 'reason' => $r->reason, 'receiver' => Present::person($r->receiver),
        ];
        if (self::values($viewer)) {
            $row['total_value'] = Present::money($r->total_value);
        }

        return $row;
    }

    public static function returnDetail(StockReturn $r, User $viewer): array
    {
        $r->loadMissing('issue', 'project', 'store', 'receiver', 'lines.item.unit');
        $values = self::values($viewer);

        return self::returnSummary($r, $viewer) + [
            'lines' => $r->lines->map(fn ($l) => array_filter([
                'id' => $l->id, 'item' => self::item($l->item), 'qty' => Present::qty($l->qty),
                'unit_cost' => $values ? Present::money($l->unit_cost) : null, 'line_value' => $values ? Present::money($l->line_value) : null,
            ], fn ($v) => $v !== null))->values()->all(),
        ];
    }

    // ------------------------------------------------------------------ purchasing

    public static function prSummary(PurchaseRequisition $pr): array
    {
        return [
            'id' => $pr->id, 'pr_no' => $pr->pr_no, 'status' => $pr->status, 'status_label' => $pr->statusLabel(), 'status_tone' => $pr->tone(),
            'project' => Present::project($pr->project), 'store' => self::store($pr->store), 'purpose' => $pr->purpose,
            'requester' => Present::person($pr->requester), 'needed_on' => $pr->needed_on?->toDateString(),
            'estimated_total' => Present::money($pr->estimated_total),
            'material_request' => $pr->materialRequest ? ['id' => $pr->materialRequest->id, 'request_no' => $pr->materialRequest->request_no] : null,
            'is_restock' => $pr->material_request_id !== null,
            'lines_count' => $pr->lines_count ?? $pr->lines()->count(),
            'submitted_at' => Present::time($pr->submitted_at), 'created_at' => Present::time($pr->created_at),
        ];
    }

    public static function prDetail(PurchaseRequisition $pr, User $viewer): array
    {
        $pr->loadMissing('project', 'store', 'requester', 'decider', 'materialRequest', 'lines.item.unit', 'purchaseOrders');
        $as = Gate::forUser($viewer);

        return self::prSummary($pr) + [
            'decider' => Present::person($pr->decider), 'decided_at' => Present::time($pr->decided_at), 'decision_reason' => $pr->decision_reason,
            'lines' => $pr->lines->map(fn ($l) => [
                'id' => $l->id, 'item' => self::item($l->item), 'qty' => Present::qty($l->qty),
                'est_unit_cost' => Present::money($l->est_unit_cost), 'notes' => $l->notes,
            ])->values()->all(),
            'purchase_orders' => $pr->purchaseOrders->map(fn ($po) => ['id' => $po->id, 'po_no' => $po->po_no, 'status_label' => $po->statusLabel()])->values()->all(),
            'can' => [
                'decide' => $as->allows('decide-pr', $pr),
                'submit' => $pr->status === 'draft' && $viewer->id === $pr->requested_by,
            ],
        ];
    }

    public static function poSummary(PurchaseOrder $po): array
    {
        return [
            'id' => $po->id, 'po_no' => $po->po_no, 'status' => $po->status, 'status_label' => $po->statusLabel(), 'status_tone' => $po->tone(),
            'supplier' => $po->supplier ? ['id' => $po->supplier->id, 'name' => $po->supplier->name] : null,
            'project' => Present::project($po->project), 'store' => self::store($po->store),
            'order_date' => $po->order_date?->toDateString(), 'expected_on' => $po->expected_on?->toDateString(),
            'total' => Present::money($po->total), 'creator' => Present::person($po->creator),
            'lines_count' => $po->lines_count ?? $po->lines()->count(),
        ];
    }

    public static function poDetail(PurchaseOrder $po, User $viewer): array
    {
        $po->loadMissing('supplier', 'project', 'store', 'creator', 'decider', 'requisition', 'lines.item.unit');

        return self::poSummary($po) + [
            'lock_version' => $po->lock_version, 'terms' => $po->terms, 'notes' => $po->notes,
            'requisition' => $po->requisition ? ['id' => $po->requisition->id, 'pr_no' => $po->requisition->pr_no] : null,
            'decider' => Present::person($po->decider), 'decided_at' => Present::time($po->decided_at), 'decision_reason' => $po->decision_reason,
            'owner_threshold' => Present::money(app(PurchasingService::class)->ownerThreshold()),
            'lines' => $po->lines->map(fn ($l) => [
                'id' => $l->id, 'item' => self::item($l->item), 'qty' => Present::qty($l->qty), 'received_qty' => Present::qty($l->received_qty),
                'unit_price' => Present::money($l->unit_price), 'line_total' => Present::money($l->line_total),
            ])->values()->all(),
            'can' => ['decide' => Gate::forUser($viewer)->allows('decide-po', $po)],
        ];
    }
}
