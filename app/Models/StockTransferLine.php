<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

class StockTransferLine extends Model
{
    protected $fillable = ['stock_transfer_id', 'item_id', 'qty', 'received_qty', 'unit_cost', 'shortfall_reason'];

    protected function casts(): array
    {
        return ['qty' => 'decimal:3', 'received_qty' => 'decimal:3', 'unit_cost' => 'decimal:4'];
    }

    protected static function booted(): void
    {
        // The only permitted change is the one-time receipt confirmation.
        static::updating(function (self $l) {
            if ($l->getOriginal('received_qty') !== null || $l->isDirty(['item_id', 'qty', 'unit_cost', 'stock_transfer_id'])) {
                throw new LogicException('Transfer lines are immutable once dispatched.');
            }
        });
        static::deleting(fn () => throw new LogicException('Transfer lines cannot be deleted.'));
    }

    public function transfer(): BelongsTo { return $this->belongsTo(StockTransfer::class, 'stock_transfer_id'); }
    public function item(): BelongsTo { return $this->belongsTo(InventoryItem::class); }

    public function shortfall(): string
    {
        return $this->received_qty === null ? '0.000' : bcsub((string) $this->qty, (string) $this->received_qty, 3);
    }
}
