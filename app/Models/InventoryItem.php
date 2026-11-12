<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class InventoryItem extends Model
{
    protected $fillable = ['sku', 'name', 'category_id', 'unit_id', 'min_level', 'is_active'];

    protected function casts(): array
    {
        return ['min_level' => 'decimal:3', 'avg_cost' => 'decimal:4', 'is_active' => 'boolean'];
    }

    protected static function booted(): void
    {
        // Every item has exactly one balance row in the single store.
        static::created(fn (self $item) => InventoryBalance::create(['item_id' => $item->id, 'on_hand' => 0]));
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(InventoryCategory::class, 'category_id');
    }

    public function unit(): BelongsTo
    {
        return $this->belongsTo(InventoryUnit::class, 'unit_id');
    }

    public function balance(): HasOne
    {
        return $this->hasOne(InventoryBalance::class, 'item_id');
    }

    public function movements(): HasMany
    {
        return $this->hasMany(StockMovement::class, 'item_id');
    }

    public function onHand(): string
    {
        return (string) ($this->balance?->on_hand ?? '0.000');
    }

    public function isLowStock(): bool
    {
        return bccomp((string) $this->min_level, '0', 3) > 0
            && bccomp($this->onHand(), (string) $this->min_level, 3) <= 0;
    }
}
