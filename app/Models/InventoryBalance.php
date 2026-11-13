<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Collection;

/** On-hand quantity of one item in one store. Changed only by the stock services inside a locked transaction. */
class InventoryBalance extends Model
{
    protected $fillable = ['store_id', 'item_id', 'on_hand'];

    protected function casts(): array
    {
        return ['on_hand' => 'decimal:3', 'store_id' => 'integer', 'item_id' => 'integer'];
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(InventoryItem::class, 'item_id');
    }

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }

    /**
     * Ensure balance rows exist for (store, items), then lock them in ascending item order
     * (a fixed lock order prevents deadlocks between concurrent transactions).
     *
     * @param  array<int>  $itemIds
     * @return Collection<int, self> keyed by item_id
     */
    public static function lockRows(int $storeId, array $itemIds): Collection
    {
        $itemIds = array_values(array_unique(array_map('intval', $itemIds)));
        sort($itemIds);
        if (! $itemIds) {
            return collect();
        }
        $now = now();
        static::query()->insertOrIgnore(array_map(fn ($id) => [
            'store_id' => $storeId, 'item_id' => $id, 'on_hand' => 0, 'created_at' => $now, 'updated_at' => $now,
        ], $itemIds));

        return static::where('store_id', $storeId)->whereIn('item_id', $itemIds)
            ->orderBy('item_id')->lockForUpdate()->get()->keyBy('item_id');
    }
}
