<?php

namespace App\Models;

use App\Models\Concerns\Immutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Append-only inventory ledger. */
class StockMovement extends Model
{
    use Immutable;

    public const UPDATED_AT = null;

    protected $fillable = ['item_id', 'store_id', 'movement_type', 'qty_delta', 'unit_cost', 'balance_after', 'project_id', 'source_type', 'source_id', 'actor_id'];

    protected function casts(): array
    {
        return ['qty_delta' => 'decimal:3', 'unit_cost' => 'decimal:4', 'balance_after' => 'decimal:3', 'created_at' => 'datetime'];
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(InventoryItem::class, 'item_id');
    }

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }
}
