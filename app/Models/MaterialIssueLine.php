<?php

namespace App\Models;

use App\Models\Concerns\Immutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MaterialIssueLine extends Model
{
    use Immutable;

    protected $fillable = ['material_issue_id', 'material_request_line_id', 'item_id', 'qty', 'unit_cost_snapshot', 'line_value'];

    protected function casts(): array
    {
        return ['qty' => 'decimal:3', 'unit_cost_snapshot' => 'decimal:4', 'line_value' => 'decimal:2'];
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(InventoryItem::class, 'item_id');
    }

    public function issue(): BelongsTo
    {
        return $this->belongsTo(MaterialIssue::class, 'material_issue_id');
    }

    public function returnLines(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(StockReturnLine::class);
    }

    /** Quantity already returned to store (derived; issue lines themselves are immutable). */
    public function returnedQty(): string
    {
        $rows = $this->relationLoaded('returnLines') ? $this->returnLines : $this->returnLines()->get();

        return $rows->reduce(fn ($c, $r) => bcadd($c, (string) $r->qty, 3), '0.000');
    }
}
