<?php

namespace App\Models;

use App\Enums\Role;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A physical stock location: the central store or a project site store.
 * Storekeepers may only move stock in stores they are assigned to (store_user); the admin may use any store.
 */
class Store extends Model
{
    public const DEFAULT_ID = 1; // "MAIN", created by the Phase 2 migration

    public const TYPES = ['central' => 'Central store', 'site' => 'Project site store'];

    protected $fillable = ['code', 'name', 'type', 'project_id', 'location', 'is_active'];

    protected $attributes = ['type' => 'central', 'is_active' => true];

    protected function casts(): array
    {
        return ['is_active' => 'boolean', 'project_id' => 'integer'];
    }

    public function balances(): HasMany
    {
        return $this->hasMany(InventoryBalance::class);
    }

    public function stockMovements(): HasMany
    {
        return $this->hasMany(StockMovement::class);
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /** Storekeepers assigned to this store. */
    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class)->withTimestamps();
    }

    /** Active stores in which the user may receive, issue, transfer, count or adjust stock. */
    public function scopeOperableBy(Builder $q, User $user): Builder
    {
        $q->where('is_active', true);
        if (! $user->is_active) {
            return $q->whereRaw('1 = 0');
        }
        if ($user->isAdmin()) {
            return $q;
        }
        if ($user->role !== Role::Storekeeper) {
            return $q->whereRaw('1 = 0');
        }

        return $q->whereHas('users', fn ($u) => $u->whereKey($user->id));
    }

    public function isOperableBy(User $user): bool
    {
        return static::operableBy($user)->whereKey($this->id)->exists();
    }

    /** Server-side guard used by every stock service. */
    public static function assertOperable(User $user, int $storeId): self
    {
        $store = static::operableBy($user)->find($storeId);
        if (! $store) {
            throw new AuthorizationException('You are not assigned to that store, or it is inactive.');
        }

        return $store;
    }

    public function label(): string
    {
        return "{$this->code} · {$this->name}";
    }
}
