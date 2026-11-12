<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Current on-hand quantity. Changed only by StockService / IssueService inside a locked transaction. */
class InventoryBalance extends Model
{
    protected $fillable = ['item_id', 'on_hand'];

    protected function casts(): array
    {
        return ['on_hand' => 'decimal:3'];
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(InventoryItem::class, 'item_id');
    }
}
