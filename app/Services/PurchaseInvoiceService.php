<?php

namespace App\Services;

use App\Enums\Role;
use App\Exceptions\WorkflowException;
use App\Models\ActivityLog;
use App\Models\DocumentSequence;
use App\Models\PurchaseInvoice;
use App\Models\PurchaseInvoiceLine;
use App\Models\PurchaseOrder;
use App\Models\SupplierPayment;
use App\Models\User;
use App\Support\Decimal;
use App\Support\Idempotency;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;

/**
 * Supplier invoices and payments.
 *
 * Three-way match per invoice line: PO (price) ↔ GRN (quantity received) ↔ invoice (quantity, price).
 *   - invoice unit price must equal the PO unit price;
 *   - cumulative invoiced quantity must not exceed cumulative received quantity.
 * Any mismatch records the invoice as a *match exception*: it cannot be paid until an Owner/Admin or Overall Manager
 * (not the person who recorded it) approves the exception with a reason.
 * Payments are records of money paid outside the system; the total paid never exceeds the invoice total.
 */
class PurchaseInvoiceService
{
    /**
     * @param array{supplier_invoice_no:string, invoice_date:string, due_date?:?string, tax?:mixed, attachment_path?:?string} $header
     * @param array<int|string, array{qty:mixed, unit_price:mixed}> $lines purchase_order_line_id => values
     */
    public function record(User $user, PurchaseOrder $po, array $header, array $lines): PurchaseInvoice
    {
        $this->assertFinance($user);
        $tax = Decimal::of($header['tax'] ?? 0, 2);
        if (bccomp($tax, '0', 2) < 0) {
            throw new WorkflowException('Tax cannot be negative.');
        }
        if (PurchaseInvoice::where('supplier_id', $po->supplier_id)->where('supplier_invoice_no', trim($header['supplier_invoice_no']))->exists()) {
            throw new WorkflowException("Invoice {$header['supplier_invoice_no']} from this supplier is already recorded.");
        }

        return DB::transaction(function () use ($user, $po, $header, $lines, $tax) {
            $po = PurchaseOrder::whereKey($po->id)->lockForUpdate()->firstOrFail();
            if (! in_array($po->status, ['approved', 'partially_received', 'received', 'closed'], true)) {
                throw new WorkflowException("{$po->po_no} is {$po->statusLabel()}; invoices can only be recorded against approved orders.");
            }
            $poLines = $po->lines()->with('item')->orderBy('id')->lockForUpdate()->get()->keyBy('id');
            $notes = [];
            $rows = [];
            $subtotal = '0';
            foreach ($lines as $lineId => $l) {
                $qtyRaw = $l['qty'] ?? null;
                if ($qtyRaw === null || $qtyRaw === '' || bccomp(Decimal::of($qtyRaw, 3), '0', 3) === 0) {
                    continue;
                }
                $line = $poLines->get((int) $lineId) ?? throw new WorkflowException('Line does not belong to this purchase order.');
                $qty = Decimal::of($qtyRaw, 3);
                $price = Decimal::of($l['unit_price'] ?? $line->unit_price, 4);
                if (bccomp($qty, '0', 3) < 0 || bccomp($price, '0', 4) < 0) {
                    throw new WorkflowException('Invoice quantities and prices cannot be negative.');
                }
                if (bccomp($price, (string) $line->unit_price, 4) !== 0) {
                    $notes[] = "{$line->item->sku}: invoiced at ".rtrim(rtrim($price, '0'), '.').' but PO price is '.rtrim(rtrim((string) $line->unit_price, '0'), '.').'.';
                }
                $newInvoiced = bcadd((string) $line->invoiced_qty, $qty, 3);
                if (bccomp($newInvoiced, (string) $line->received_qty, 3) > 0) {
                    $notes[] = "{$line->item->sku}: invoiced quantity to date {$newInvoiced} exceeds quantity received {$line->received_qty}.";
                }
                $rows[$line->id] = [$line, $qty, $price, $newInvoiced];
                $subtotal = bcadd($subtotal, bcmul($qty, $price, 2), 2);
            }
            if (! $rows) {
                throw new WorkflowException('Enter at least one invoiced line.');
            }

            $invoice = PurchaseInvoice::create([
                'pi_no' => DocumentSequence::next('PI'), 'supplier_id' => $po->supplier_id, 'supplier_invoice_no' => trim($header['supplier_invoice_no']),
                'purchase_order_id' => $po->id, 'invoice_date' => $header['invoice_date'], 'due_date' => $header['due_date'] ?? null,
                'subtotal' => $subtotal, 'tax' => $tax, 'total' => bcadd($subtotal, $tax, 2),
                'match_status' => $notes ? 'exception' : 'matched', 'match_notes' => $notes ? implode("\n", $notes) : null,
                'recorded_by' => $user->id, 'attachment_path' => $header['attachment_path'] ?? null,
            ]);
            foreach ($rows as [$line, $qty, $price, $newInvoiced]) {
                PurchaseInvoiceLine::create(['purchase_invoice_id' => $invoice->id, 'purchase_order_line_id' => $line->id,
                    'qty' => $qty, 'unit_price' => $price, 'line_total' => bcmul($qty, $price, 2)]);
                $line->forceFill(['invoiced_qty' => $newInvoiced])->save();
            }
            ActivityLog::record('invoice.recorded', "Supplier invoice {$invoice->supplier_invoice_no} recorded as {$invoice->pi_no} ({$invoice->match_status})", $invoice);

            return $invoice;
        });
    }

    public function approveException(User $user, PurchaseInvoice $invoice, string $reason): PurchaseInvoice
    {
        if (! $user->is_active || ! $user->hasRole(Role::Admin, Role::OverallManager)) {
            throw new AuthorizationException('Only the Owner/Admin or Overall Manager can approve a match exception.');
        }
        if ($user->id === $invoice->recorded_by) {
            throw new AuthorizationException('The person who recorded the invoice cannot approve its exception.');
        }
        if (trim($reason) === '') {
            throw new WorkflowException('A reason is required.');
        }

        return DB::transaction(function () use ($user, $invoice, $reason) {
            $invoice = PurchaseInvoice::whereKey($invoice->id)->lockForUpdate()->firstOrFail();
            if ($invoice->match_status !== 'exception') {
                throw new WorkflowException('This invoice has no open match exception.');
            }
            $invoice->forceFill(['match_status' => 'exception_approved', 'exception_approved_by' => $user->id,
                'exception_approved_at' => now(), 'exception_reason' => $reason])->save();
            ActivityLog::record('invoice.exception_approved', "Match exception on {$invoice->pi_no} approved: {$reason}", $invoice);

            return $invoice;
        });
    }

    public function pay(User $user, PurchaseInvoice $invoice, mixed $amount, string $paidOn, string $method, ?string $reference = null, ?string $idempotencyKey = null): SupplierPayment
    {
        $this->assertFinance($user);
        $amount = Decimal::of($amount, 2);
        if (bccomp($amount, '0', 2) <= 0) {
            throw new WorkflowException('Payment amount must be positive.');
        }
        if (! array_key_exists($method, SupplierPayment::METHODS)) {
            throw new WorkflowException('Unknown payment method.');
        }

        return Idempotency::run(SupplierPayment::class, $idempotencyKey, fn () => DB::transaction(function () use ($user, $invoice, $amount, $paidOn, $method, $reference, $idempotencyKey) {
            $invoice = PurchaseInvoice::whereKey($invoice->id)->lockForUpdate()->firstOrFail();
            if (! in_array($invoice->match_status, ['matched', 'exception_approved'], true)) {
                throw new WorkflowException("{$invoice->pi_no} has an unapproved match exception and cannot be paid.");
            }
            if (bccomp($amount, $invoice->balance(), 2) > 0) {
                throw new WorkflowException("Payment exceeds the outstanding balance of {$invoice->balance()}.");
            }
            $payment = SupplierPayment::create([
                'payment_no' => DocumentSequence::next('PAY'), 'purchase_invoice_id' => $invoice->id, 'amount' => $amount,
                'paid_on' => $paidOn, 'method' => $method, 'reference' => $reference, 'recorded_by' => $user->id, 'idempotency_key' => $idempotencyKey,
            ]);
            $paid = bcadd((string) $invoice->amount_paid, $amount, 2);
            $invoice->forceFill([
                'amount_paid' => $paid,
                'payment_status' => bccomp($paid, (string) $invoice->total, 2) >= 0 ? 'paid' : 'partially_paid',
            ])->save();
            ActivityLog::record('invoice.payment', "Payment {$payment->payment_no} of {$amount} on {$invoice->pi_no}", $payment);

            return $payment;
        }));
    }

    private function assertFinance(User $user): void
    {
        if (! $user->is_active || ! $user->hasRole(Role::Admin, Role::Accountant)) {
            throw new AuthorizationException('Only the Accountant or Owner/Admin can record supplier invoices and payments.');
        }
    }
}
