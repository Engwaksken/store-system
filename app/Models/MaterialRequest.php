<?php

namespace App\Models;

use App\Enums\RequestStatus;
use App\Enums\Role;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class MaterialRequest extends Model
{
    /** status, version, project_manager_id and submitted_at are set only by RequestWorkflow. */
    protected $fillable = ['request_no', 'project_id', 'requester_id', 'purpose', 'needed_on'];

    protected $attributes = ['status' => 'draft', 'version' => 1];

    protected function casts(): array
    {
        return [
            'status' => RequestStatus::class, 'needed_on' => 'date', 'submitted_at' => 'datetime', 'version' => 'integer',
            'project_id' => 'integer', 'requester_id' => 'integer', 'project_manager_id' => 'integer',
        ];
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requester_id');
    }

    public function projectManager(): BelongsTo
    {
        return $this->belongsTo(User::class, 'project_manager_id');
    }

    public function lines(): HasMany
    {
        return $this->hasMany(MaterialRequestLine::class);
    }

    public function approvals(): HasMany
    {
        return $this->hasMany(MaterialApproval::class)->orderBy('version')->orderBy('id');
    }

    public function issues(): HasMany
    {
        return $this->hasMany(MaterialIssue::class);
    }

    public function currentApproval(string $step): ?MaterialApproval
    {
        return $this->approvals->first(fn ($a) => $a->version === $this->version && $a->step === $step);
    }

    public function scopeVisibleTo(Builder $q, User $user): Builder
    {
        return match ($user->role) {
            Role::Admin, Role::OverallManager, Role::Accountant, Role::Auditor => $q,
            Role::Storekeeper => $q->whereIn('status', [
                RequestStatus::OverallApproved->value, RequestStatus::PartiallyIssued->value, RequestStatus::FullyIssued->value,
            ]),
            Role::ProjectManager => $q->where(fn ($w) => $w->where('requester_id', $user->id)
                ->orWhere('project_manager_id', $user->id)
                ->orWhereHas('project', fn ($p) => $p->where('manager_id', $user->id))),
            default => $q->where('requester_id', $user->id),
        };
    }
}
