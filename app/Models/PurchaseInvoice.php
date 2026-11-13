<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Supplier invoice, three-way matched against the PO and goods received. */
class PurchaseInvoice extends Model
{
    public const MATCH = ['matched' => 'Matched', 'exception' => 'Match exception', 'exception_approved' => 'Exception approved'];
    public const PAYMENT = ['unpaid' => 'Unpaid', 'partially_paid' => 'Partially paid', 'paid' => 'Paid'];

    protected $fillable = ['pi_no', 'supplier_id', 'supplier_invoice_no', 'purchase_order_id', 'invoice_date', 'due_date',
        'subtotal', 'tax', 'total', 'match_status', 'match_notes', 'recorded_by', 'attachment_path'];

    protected function casts(): array
    {
        return ['invoice_date' => 'date', 'due_date' => 'date', 'subtotal' => 'decimal:2', 'tax' => 'decimal:2', 'total' => 'decimal:2',
            'amount_paid' => 'decimal:2', 'exception_approved_at' => 'datetime', 'recorded_by' => 'integer'];
    }

    public function supplier(): BelongsTo { return $this->belongsTo(Supplier::class); }
    public function order(): BelongsTo { return $this->belongsTo(PurchaseOrder::class, 'purchase_order_id'); }
    public function recorder(): BelongsTo { return $this->belongsTo(User::class, 'recorded_by'); }
    public function exceptionApprover(): BelongsTo { return $this->belongsTo(User::class, 'exception_approved_by'); }
    public function lines(): HasMany { return $this->hasMany(PurchaseInvoiceLine::class); }
    public function payments(): HasMany { return $this->hasMany(SupplierPayment::class); }

    public function isPayable(): bool
    {
        return in_array($this->match_status, ['matched', 'exception_approved'], true) && $this->payment_status !== 'paid';
    }

    public function balance(): string
    {
        return bcsub((string) $this->total, (string) $this->amount_paid, 2);
    }

    public function matchTone(): string
    {
        return match ($this->match_status) { 'matched' => 'approved', 'exception' => 'rejected', default => 'partial' };
    }

    public function paymentTone(): string
    {
        return match ($this->payment_status) { 'paid' => 'approved', 'partially_paid' => 'partial', default => 'pending' };
    }
}
