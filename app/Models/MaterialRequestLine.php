<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MaterialRequestLine extends Model
{
    /** Approved / issued quantities are written only by RequestWorkflow and IssueService. */
    protected $fillable = ['material_request_id', 'item_id', 'boq_item_id', 'requested_qty', 'notes'];

    protected function casts(): array
    {
        return [
            'requested_qty' => 'decimal:3', 'pm_approved_qty' => 'decimal:3',
            'final_approved_qty' => 'decimal:3', 'issued_qty' => 'decimal:3',
        ];
    }

    public function request(): BelongsTo
    {
        return $this->belongsTo(MaterialRequest::class, 'material_request_id');
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(InventoryItem::class, 'item_id');
    }

    /** Final approved quantity not yet issued. */
    public function outstanding(): string
    {
        if ($this->final_approved_qty === null) {
            return '0.000';
        }

        return bcsub((string) $this->final_approved_qty, (string) $this->issued_qty, 3);
    }

    public function boqItem(): BelongsTo
    {
        return $this->belongsTo(BoqItem::class);
    }
}
