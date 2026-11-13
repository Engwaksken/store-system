<?php

namespace App\Models;

use App\Models\Concerns\Immutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Unused material returned from a project to a store against an issue voucher. Credits project cost. */
class StockReturn extends Model
{
    use Immutable;

    protected $fillable = ['return_no', 'material_issue_id', 'project_id', 'store_id', 'received_by', 'returned_by_name',
        'reason', 'total_value', 'returned_at', 'idempotency_key'];

    protected function casts(): array
    {
        return ['returned_at' => 'datetime', 'total_value' => 'decimal:2', 'project_id' => 'integer'];
    }

    public function issue(): BelongsTo { return $this->belongsTo(MaterialIssue::class, 'material_issue_id'); }
    public function project(): BelongsTo { return $this->belongsTo(Project::class); }
    public function store(): BelongsTo { return $this->belongsTo(Store::class); }
    public function receiver(): BelongsTo { return $this->belongsTo(User::class, 'received_by'); }
    public function lines(): HasMany { return $this->hasMany(StockReturnLine::class); }
}
