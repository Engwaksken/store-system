<?php

namespace App\Models;

use App\Models\Concerns\Immutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Record of a payment made outside the system (no bank integration). Immutable. */
class SupplierPayment extends Model
{
    use Immutable;

    public const METHODS = ['bank_transfer' => 'Bank transfer', 'cheque' => 'Cheque', 'mobile_money' => 'Mobile money', 'cash' => 'Cash'];

    protected $fillable = ['payment_no', 'purchase_invoice_id', 'amount', 'paid_on', 'method', 'reference', 'recorded_by', 'idempotency_key'];

    protected function casts(): array
    {
        return ['paid_on' => 'date', 'amount' => 'decimal:2'];
    }

    public function invoice(): BelongsTo { return $this->belongsTo(PurchaseInvoice::class, 'purchase_invoice_id'); }
    public function recorder(): BelongsTo { return $this->belongsTo(User::class, 'recorded_by'); }
}
