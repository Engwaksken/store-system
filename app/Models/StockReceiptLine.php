<?php

namespace App\Models;

use App\Models\Concerns\Immutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StockReceiptLine extends Model
{
    use Immutable;

    protected $fillable = ['stock_receipt_id', 'item_id', 'purchase_order_line_id', 'qty', 'unit_cost', 'line_total'];

    protected function casts(): array
    {
        return ['qty' => 'decimal:3', 'unit_cost' => 'decimal:4', 'line_total' => 'decimal:2'];
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(InventoryItem::class, 'item_id');
    }
}
