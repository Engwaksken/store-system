<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use LogicException;

/**
 * Physical stock count for one store: draft (counter enters quantities) → submitted → approved (variances
 * posted as adjustments) or rejected. The approver must be a different person from the counter.
 */
class StockCount extends Model
{
    public const STATUSES = ['draft' => 'Draft', 'submitted' => 'Awaiting approval', 'approved' => 'Approved & posted', 'rejected' => 'Rejected'];

    protected $fillable = ['count_no', 'store_id', 'status', 'counted_by', 'submitted_at', 'decided_by', 'decided_at', 'decision_reason', 'notes'];

    protected $attributes = ['status' => 'draft'];

    protected function casts(): array
    {
        return ['submitted_at' => 'datetime', 'decided_at' => 'datetime', 'store_id' => 'integer', 'counted_by' => 'integer'];
    }

    protected static function booted(): void
    {
        static::updating(function (self $c) {
            if (in_array($c->getOriginal('status'), ['approved', 'rejected'], true)) {
                throw new LogicException('Closed stock counts cannot be changed.');
            }
        });
    }

    public function store(): BelongsTo { return $this->belongsTo(Store::class); }
    public function counter(): BelongsTo { return $this->belongsTo(User::class, 'counted_by'); }
    public function decider(): BelongsTo { return $this->belongsTo(User::class, 'decided_by'); }
    public function lines(): HasMany { return $this->hasMany(StockCountLine::class); }
    public function adjustments(): HasMany { return $this->hasMany(StockAdjustment::class); }

    public function statusLabel(): string
    {
        return self::STATUSES[$this->status] ?? $this->status;
    }

    public function tone(): string
    {
        return match ($this->status) { 'submitted' => 'pending', 'approved' => 'approved', 'rejected' => 'rejected', default => 'muted' };
    }
}
