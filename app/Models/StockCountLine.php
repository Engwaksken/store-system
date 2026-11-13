<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StockCountLine extends Model
{
    protected $fillable = ['stock_count_id', 'item_id', 'system_qty', 'counted_qty', 'reason'];

    protected function casts(): array
    {
        return ['system_qty' => 'decimal:3', 'counted_qty' => 'decimal:3'];
    }

    public function stockCount(): BelongsTo { return $this->belongsTo(StockCount::class); }
    public function item(): BelongsTo { return $this->belongsTo(InventoryItem::class); }

    /** counted − system (null until counted). */
    public function variance(): ?string
    {
        return $this->counted_qty === null ? null : bcsub((string) $this->counted_qty, (string) $this->system_qty, 3);
    }
}
