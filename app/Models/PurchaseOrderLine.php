<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PurchaseOrderLine extends Model
{
    protected $fillable = ['purchase_order_id', 'item_id', 'qty', 'unit_price', 'line_total'];

    protected function casts(): array
    {
        return ['qty' => 'decimal:3', 'unit_price' => 'decimal:4', 'line_total' => 'decimal:2', 'received_qty' => 'decimal:3', 'invoiced_qty' => 'decimal:3'];
    }

    public function order(): BelongsTo { return $this->belongsTo(PurchaseOrder::class, 'purchase_order_id'); }
    public function item(): BelongsTo { return $this->belongsTo(InventoryItem::class); }

    public function outstanding(): string
    {
        return bcsub((string) $this->qty, (string) $this->received_qty, 3);
    }
}
