<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SupplierQuote extends Model
{
    protected $fillable = ['purchase_requisition_id', 'supplier_id', 'quote_ref', 'quoted_on', 'valid_until', 'total', 'recorded_by'];

    protected function casts(): array
    {
        return ['quoted_on' => 'date', 'valid_until' => 'date', 'total' => 'decimal:2', 'is_selected' => 'boolean'];
    }

    public function requisition(): BelongsTo { return $this->belongsTo(PurchaseRequisition::class, 'purchase_requisition_id'); }
    public function supplier(): BelongsTo { return $this->belongsTo(Supplier::class); }
    public function recorder(): BelongsTo { return $this->belongsTo(User::class, 'recorded_by'); }
    public function lines(): HasMany { return $this->hasMany(SupplierQuoteLine::class); }
}
