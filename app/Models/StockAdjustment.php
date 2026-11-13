<?php

namespace App\Models;

use App\Models\Concerns\Immutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StockAdjustment extends Model
{
    use Immutable;

    protected $fillable = ['adjustment_no', 'item_id', 'store_id', 'stock_count_id', 'qty_delta', 'reason', 'actor_id'];

    protected function casts(): array
    {
        return ['qty_delta' => 'decimal:3'];
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(InventoryItem::class, 'item_id');
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }
}
