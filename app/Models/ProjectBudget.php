<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProjectBudget extends Model
{
    protected $fillable = ['project_id', 'cost_code_id', 'amount'];

    protected function casts(): array
    {
        return ['amount' => 'decimal:2'];
    }

    public function project(): BelongsTo { return $this->belongsTo(Project::class); }
    public function costCode(): BelongsTo { return $this->belongsTo(CostCode::class); }
}
