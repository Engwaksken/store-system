<?php

namespace App\Models;

use App\Enums\Role;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Cash expense paid directly for a project. Never includes store purchases (MVP_SCOPE D9). */
class Expense extends Model
{
    public const PAYMENT_MODES = ['cash', 'bank_transfer', 'mobile_money', 'cheque'];

    protected $fillable = ['project_id', 'category_id', 'incurred_on', 'amount', 'payment_mode', 'reference', 'description', 'receipt_path', 'recorded_by'];

    protected function casts(): array
    {
        return ['incurred_on' => 'date', 'amount' => 'decimal:2'];
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(ExpenseCategory::class, 'category_id');
    }

    public function recorder(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }

    public function scopeVisibleTo(Builder $q, User $user): Builder
    {
        return match ($user->role) {
            Role::Admin, Role::OverallManager, Role::Accountant => $q,
            Role::ProjectManager => $q->whereHas('project', fn ($p) => $p->where('manager_id', $user->id)),
            default => $q->whereRaw('1 = 0'),
        };
    }
}
