<?php

namespace App\Models;

use App\Models\Concerns\Immutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class StockReceipt extends Model
{
    use Immutable;

    protected $fillable = ['receipt_no', 'type', 'supplier_name', 'reference', 'received_on', 'notes', 'total_value', 'received_by', 'store_id', 'idempotency_key'];

    protected function casts(): array
    {
        return ['received_on' => 'date', 'total_value' => 'decimal:2'];
    }

    public function lines(): HasMany
    {
        return $this->hasMany(StockReceiptLine::class);
    }

    public function receiver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'received_by');
    }

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }
}
