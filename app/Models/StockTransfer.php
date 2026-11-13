<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use LogicException;

/**
 * Two-step transfer: dispatched (stock leaves the source, status in_transit) → received at the destination
 * (or cancelled, which returns the stock to the source). Once closed the record cannot change.
 */
class StockTransfer extends Model
{
    public const STATUSES = ['in_transit' => 'In transit', 'received' => 'Received', 'cancelled' => 'Cancelled'];

    protected $fillable = ['transfer_no', 'from_store_id', 'to_store_id', 'status', 'dispatched_by', 'dispatched_at',
        'received_by', 'received_at', 'notes', 'receive_notes', 'idempotency_key'];

    protected $attributes = ['status' => 'in_transit'];

    protected function casts(): array
    {
        return ['dispatched_at' => 'datetime', 'received_at' => 'datetime', 'from_store_id' => 'integer', 'to_store_id' => 'integer', 'dispatched_by' => 'integer'];
    }

    protected static function booted(): void
    {
        static::updating(function (self $t) {
            if ($t->getOriginal('status') !== 'in_transit') {
                throw new LogicException('Closed transfers cannot be changed.');
            }
        });
        static::deleting(fn () => throw new LogicException('Transfers cannot be deleted.'));
    }

    public function fromStore(): BelongsTo { return $this->belongsTo(Store::class, 'from_store_id'); }
    public function toStore(): BelongsTo { return $this->belongsTo(Store::class, 'to_store_id'); }
    public function dispatcher(): BelongsTo { return $this->belongsTo(User::class, 'dispatched_by'); }
    public function receiver(): BelongsTo { return $this->belongsTo(User::class, 'received_by'); }
    public function lines(): HasMany { return $this->hasMany(StockTransferLine::class); }

    public function movements(): HasMany
    {
        return $this->hasMany(StockMovement::class, 'source_id')->where('source_type', 'stock_transfer');
    }

    public function statusLabel(): string
    {
        return self::STATUSES[$this->status] ?? $this->status;
    }

    public function tone(): string
    {
        return match ($this->status) { 'in_transit' => 'pending', 'received' => 'approved', default => 'muted' };
    }

    public function scopeVisibleTo($q, User $user)
    {
        if ($user->hasRole(\App\Enums\Role::Admin, \App\Enums\Role::OverallManager, \App\Enums\Role::Accountant, \App\Enums\Role::Auditor)) {
            return $q;
        }
        $ids = Store::operableBy($user)->pluck('id');

        return $q->where(fn ($w) => $w->whereIn('from_store_id', $ids)->orWhereIn('to_store_id', $ids));
    }
}
