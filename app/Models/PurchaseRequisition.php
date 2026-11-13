<?php

namespace App\Models;

use App\Enums\Role;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Internal request to buy. Status changes only through PurchasingService. */
class PurchaseRequisition extends Model
{
    public const STATUSES = [
        'draft' => 'Draft', 'submitted' => 'Awaiting approval', 'approved' => 'Approved – get quotes / raise PO',
        'rejected' => 'Rejected', 'ordered' => 'Ordered', 'cancelled' => 'Cancelled',
    ];

    protected $fillable = ['pr_no', 'project_id', 'store_id', 'requested_by', 'purpose', 'needed_on', 'estimated_total'];

    protected $attributes = ['status' => 'draft'];

    protected function casts(): array
    {
        return ['needed_on' => 'date', 'submitted_at' => 'datetime', 'decided_at' => 'datetime', 'estimated_total' => 'decimal:2',
            'requested_by' => 'integer', 'project_id' => 'integer', 'store_id' => 'integer'];
    }

    public function project(): BelongsTo { return $this->belongsTo(Project::class); }
    public function store(): BelongsTo { return $this->belongsTo(Store::class); }
    public function requester(): BelongsTo { return $this->belongsTo(User::class, 'requested_by'); }
    public function decider(): BelongsTo { return $this->belongsTo(User::class, 'decided_by'); }
    public function lines(): HasMany { return $this->hasMany(PurchaseRequisitionLine::class); }
    public function quotes(): HasMany { return $this->hasMany(SupplierQuote::class); }
    public function purchaseOrders(): HasMany { return $this->hasMany(PurchaseOrder::class); }

    public function statusLabel(): string { return self::STATUSES[$this->status] ?? $this->status; }

    public function tone(): string
    {
        return match ($this->status) {
            'submitted' => 'pending', 'approved' => 'partial', 'ordered' => 'approved', 'rejected' => 'rejected', default => 'muted',
        };
    }

    /** Procurement, finance, management see all; others see their own (PMs also their projects'). */
    public function scopeVisibleTo(Builder $q, User $user): Builder
    {
        if ($user->hasRole(Role::Admin, Role::OverallManager, Role::Procurement, Role::Accountant, Role::Auditor)) {
            return $q;
        }
        if ($user->role === Role::ProjectManager) {
            return $q->where(fn ($w) => $w->where('requested_by', $user->id)
                ->orWhereIn('project_id', Project::where('manager_id', $user->id)->select('id')));
        }

        return $q->where('requested_by', $user->id);
    }
}
