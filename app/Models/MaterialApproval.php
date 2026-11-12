<?php

namespace App\Models;

use App\Models\Concerns\Immutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Immutable decision record: one per (request, version, step). */
class MaterialApproval extends Model
{
    use Immutable;

    public const STEP_PM = 'pm';
    public const STEP_OVERALL = 'overall';

    protected $fillable = ['material_request_id', 'version', 'step', 'approver_id', 'decision', 'reason', 'was_substitute', 'lines_snapshot', 'decided_at'];

    protected function casts(): array
    {
        return ['lines_snapshot' => 'array', 'decided_at' => 'datetime', 'was_substitute' => 'boolean', 'version' => 'integer', 'approver_id' => 'integer'];
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approver_id');
    }
}
