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
}
