<?php

namespace App\Models;

use App\Models\Concerns\Immutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PurchaseInvoiceLine extends Model
{
    use Immutable;

    protected $fillable = ['purchase_invoice_id', 'purchase_order_line_id', 'qty', 'unit_price', 'line_total'];

    protected function casts(): array
    {
        return ['qty' => 'decimal:3', 'unit_price' => 'decimal:4', 'line_total' => 'decimal:2'];
    }

    public function invoice(): BelongsTo { return $this->belongsTo(PurchaseInvoice::class, 'purchase_invoice_id'); }
    public function orderLine(): BelongsTo { return $this->belongsTo(PurchaseOrderLine::class, 'purchase_order_line_id'); }
}
