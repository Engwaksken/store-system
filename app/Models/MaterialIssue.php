<?php

namespace App\Models;

use App\Models\Concerns\Immutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/** Issue voucher. Never edited; corrections go through stock adjustments. */
class MaterialIssue extends Model
{
    use Immutable;

    protected $fillable = ['issue_no', 'material_request_id', 'project_id', 'issued_by', 'recipient_name', 'issued_at', 'total_value', 'notes', 'store_id', 'idempotency_key'];

    protected function casts(): array
    {
        return ['issued_at' => 'datetime', 'total_value' => 'decimal:2', 'project_id' => 'integer', 'material_request_id' => 'integer'];
    }

    /** Store, management, finance and audit see every voucher; others only vouchers of requests they may see. */
    public function scopeVisibleTo($q, User $user)
    {
        if ($user->hasRole(\App\Enums\Role::Admin, \App\Enums\Role::OverallManager, \App\Enums\Role::Storekeeper, \App\Enums\Role::Accountant, \App\Enums\Role::Auditor)) {
            return $q;
        }

        return $q->whereHas('request', fn ($r) => $r->visibleTo($user));
    }

    /** Headline issue figures for dashboards: vouchers and item lines issued this month, vouchers not yet acknowledged. */
    public static function statsFor(User $user): array
    {
        $month = now()->startOfMonth();
        $visible = static::query()->visibleTo($user);

        return [
            'vouchers_month' => (clone $visible)->where('issued_at', '>=', $month)->count(),
            'items_month' => MaterialIssueLine::whereIn('material_issue_id', (clone $visible)->where('issued_at', '>=', $month)->select('id'))->count(),
            'unacknowledged' => (clone $visible)->whereDoesntHave('acknowledgment')->count(),
        ];
    }

    public function request(): BelongsTo
    {
        return $this->belongsTo(MaterialRequest::class, 'material_request_id');
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function issuer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'issued_by');
    }

    public function lines(): HasMany
    {
        return $this->hasMany(MaterialIssueLine::class);
    }

    public function acknowledgment(): HasOne
    {
        return $this->hasOne(IssueAcknowledgment::class);
    }

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }

    public function returns(): HasMany
    {
        return $this->hasMany(StockReturn::class);
    }
}
