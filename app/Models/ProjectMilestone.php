<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProjectMilestone extends Model
{
    protected $fillable = ['project_id', 'name', 'planned_on', 'completed_on', 'notes', 'sort'];

    protected function casts(): array
    {
        return ['planned_on' => 'date', 'completed_on' => 'date'];
    }

    public function project(): BelongsTo { return $this->belongsTo(Project::class); }

    public function state(): string
    {
        if ($this->completed_on) {
            return 'done';
        }

        return $this->planned_on && $this->planned_on->isPast() ? 'late' : 'open';
    }
}
