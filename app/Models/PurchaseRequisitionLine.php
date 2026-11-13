<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PurchaseRequisitionLine extends Model
{
    protected $fillable = ['purchase_requisition_id', 'item_id', 'qty', 'est_unit_cost', 'notes'];

    protected function casts(): array
    {
        return ['qty' => 'decimal:3', 'est_unit_cost' => 'decimal:4'];
    }

    public function requisition(): BelongsTo { return $this->belongsTo(PurchaseRequisition::class, 'purchase_requisition_id'); }
    public function item(): BelongsTo { return $this->belongsTo(InventoryItem::class); }
}
