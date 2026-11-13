<?php

namespace App\Models;

use App\Enums\Role;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Project extends Model
{
    use HasFactory;

    public const STATUSES = ['planned', 'active', 'on_hold', 'completed', 'cancelled'];

    protected $fillable = ['code', 'name', 'client', 'location', 'start_date', 'end_date', 'status', 'budget', 'manager_id', 'description', 'created_by'];

    protected function casts(): array
    {
        return ['start_date' => 'date', 'end_date' => 'date', 'budget' => 'decimal:2', 'manager_id' => 'integer'];
    }

    public function manager(): BelongsTo
    {
        return $this->belongsTo(User::class, 'manager_id');
    }

    public function members(): BelongsToMany
    {
        return $this->belongsToMany(User::class)->withTimestamps();
    }

    public function requests(): HasMany
    {
        return $this->hasMany(MaterialRequest::class);
    }

    public function issues(): HasMany
    {
        return $this->hasMany(MaterialIssue::class);
    }

    public function expenses(): HasMany
    {
        return $this->hasMany(Expense::class);
    }

    /** Projects a user may see (row-level scoping). */
    public function scopeVisibleTo(Builder $q, User $user): Builder
    {
        if ($user->hasRole(Role::Admin, Role::OverallManager, Role::Accountant, Role::Storekeeper, Role::Auditor)) {
            return $q;
        }

        return $q->where(fn ($w) => $w->where('manager_id', $user->id)
            ->orWhereHas('members', fn ($m) => $m->whereKey($user->id)));
    }

    /** Projects the user may raise material requests against. */
    public function scopeRequestableBy(Builder $q, User $user): Builder
    {
        $q->whereIn('status', ['planned', 'active']);
        if ($user->isAdmin()) {
            return $q;
        }
        if (! $user->hasRole(Role::Staff, Role::ProjectManager)) {
            return $q->whereRaw('1 = 0');
        }

        return $q->where(fn ($w) => $w->where('manager_id', $user->id)
            ->orWhereHas('members', fn ($m) => $m->whereKey($user->id)));
    }

    public function budgets(): HasMany
    {
        return $this->hasMany(ProjectBudget::class);
    }

    public function boqItems(): HasMany
    {
        return $this->hasMany(BoqItem::class)->orderBy('ref');
    }

    public function milestones(): HasMany
    {
        return $this->hasMany(ProjectMilestone::class)->orderBy('sort')->orderBy('planned_on');
    }

    public function claims(): HasMany
    {
        return $this->hasMany(ExpenseClaim::class);
    }

    public function purchaseOrders(): HasMany
    {
        return $this->hasMany(PurchaseOrder::class);
    }
}
