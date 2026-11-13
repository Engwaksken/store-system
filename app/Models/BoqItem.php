<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Bill of quantities line. Material request lines may point at it, so issues can be reported per BOQ line. */
class BoqItem extends Model
{
    protected $fillable = ['project_id', 'cost_code_id', 'ref', 'description', 'unit', 'qty', 'rate', 'amount', 'item_id'];

    protected function casts(): array
    {
        return ['qty' => 'decimal:3', 'rate' => 'decimal:2', 'amount' => 'decimal:2', 'project_id' => 'integer', 'cost_code_id' => 'integer'];
    }

    public function project(): BelongsTo { return $this->belongsTo(Project::class); }
    public function costCode(): BelongsTo { return $this->belongsTo(CostCode::class); }
    public function item(): BelongsTo { return $this->belongsTo(InventoryItem::class); }
    public function requestLines(): HasMany { return $this->hasMany(MaterialRequestLine::class); }

    public function label(): string
    {
        return "{$this->ref} · ".\Illuminate\Support\Str::limit($this->description, 60);
    }
}
