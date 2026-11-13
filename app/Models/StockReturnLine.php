<?php

namespace App\Models;

use App\Models\Concerns\Immutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StockReturnLine extends Model
{
    use Immutable;

    protected $fillable = ['stock_return_id', 'material_issue_line_id', 'item_id', 'qty', 'unit_cost', 'line_value'];

    protected function casts(): array
    {
        return ['qty' => 'decimal:3', 'unit_cost' => 'decimal:4', 'line_value' => 'decimal:2'];
    }

    public function stockReturn(): BelongsTo { return $this->belongsTo(StockReturn::class); }
    public function item(): BelongsTo { return $this->belongsTo(InventoryItem::class); }
    public function issueLine(): BelongsTo { return $this->belongsTo(MaterialIssueLine::class, 'material_issue_line_id'); }
}
