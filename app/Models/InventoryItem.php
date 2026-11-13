<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class InventoryItem extends Model
{
    protected $fillable = ['sku', 'name', 'category_id', 'unit_id', 'min_level', 'is_active'];

    protected function casts(): array
    {
        return ['min_level' => 'decimal:3', 'avg_cost' => 'decimal:4', 'is_active' => 'boolean'];
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(InventoryCategory::class, 'category_id');
    }

    public function unit(): BelongsTo
    {
        return $this->belongsTo(InventoryUnit::class, 'unit_id');
    }

    /** One row per store that has ever held the item (rows are created on first movement). */
    public function balances(): HasMany
    {
        return $this->hasMany(InventoryBalance::class, 'item_id');
    }

    public function movements(): HasMany
    {
        return $this->hasMany(StockMovement::class, 'item_id');
    }

    /** On-hand quantity in one store, or company-wide when $storeId is null. */
    public function onHand(?int $storeId = null): string
    {
        $rows = $this->relationLoaded('balances') ? $this->balances : $this->balances()->get();
        if ($storeId !== null) {
            $rows = $rows->where('store_id', $storeId);
        }

        return $rows->reduce(fn ($c, $b) => bcadd($c, (string) $b->on_hand, 3), '0.000');
    }

    /** Minimum level applies to company-wide stock. */
    public function isLowStock(): bool
    {
        return bccomp((string) $this->min_level, '0', 3) > 0
            && bccomp($this->onHand(), (string) $this->min_level, 3) <= 0;
    }
}
