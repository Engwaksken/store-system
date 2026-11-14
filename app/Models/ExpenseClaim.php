<?php

namespace App\Models;

use App\Enums\Role;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

/** Out-of-pocket / site cost claimed by a project member. Becomes exactly one Expense when paid. */
class ExpenseClaim extends Model
{
    public const STATUSES = ['submitted' => 'Awaiting approval', 'approved' => 'Approved - awaiting payment', 'rejected' => 'Rejected', 'paid' => 'Paid'];

    protected $fillable = ['claim_no', 'project_id', 'category_id', 'cost_code_id', 'claimant_id', 'incurred_on', 'amount', 'description', 'receipt_path'];

    protected $attributes = ['status' => 'submitted'];

    protected function casts(): array
    {
        return ['incurred_on' => 'date', 'decided_at' => 'datetime', 'amount' => 'decimal:2', 'claimant_id' => 'integer', 'project_id' => 'integer'];
    }

    public function project(): BelongsTo { return $this->belongsTo(Project::class); }
    public function category(): BelongsTo { return $this->belongsTo(ExpenseCategory::class, 'category_id'); }
    public function costCode(): BelongsTo { return $this->belongsTo(CostCode::class); }
    public function claimant(): BelongsTo { return $this->belongsTo(User::class, 'claimant_id'); }
    public function approver(): BelongsTo { return $this->belongsTo(User::class, 'approver_id'); }
    public function expense(): HasOne { return $this->hasOne(Expense::class); }

    public function statusLabel(): string { return self::STATUSES[$this->status] ?? $this->status; }

    public function tone(): string
    {
        return match ($this->status) { 'submitted' => 'pending', 'approved' => 'partial', 'paid' => 'approved', default => 'rejected' };
    }

    public function scopeVisibleTo(Builder $q, User $user): Builder
    {
        return match ($user->role) {
            Role::Admin, Role::OverallManager, Role::Accountant, Role::Auditor => $q,
            Role::ProjectManager => $q->where(fn ($w) => $w->where('claimant_id', $user->id)
                ->orWhereIn('project_id', Project::where('manager_id', $user->id)->select('id'))),
            default => $q->where('claimant_id', $user->id),
        };
    }
}
