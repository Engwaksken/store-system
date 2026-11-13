<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SupplierQuoteLine extends Model
{
    protected $fillable = ['supplier_quote_id', 'purchase_requisition_line_id', 'unit_price'];

    protected function casts(): array
    {
        return ['unit_price' => 'decimal:4'];
    }

    public function quote(): BelongsTo { return $this->belongsTo(SupplierQuote::class, 'supplier_quote_id'); }
    public function requisitionLine(): BelongsTo { return $this->belongsTo(PurchaseRequisitionLine::class, 'purchase_requisition_line_id'); }
}
