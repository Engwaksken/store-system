<?php

namespace App\Models;

use App\Models\Concerns\Immutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class IssueAcknowledgment extends Model
{
    use Immutable;

    protected $fillable = ['material_issue_id', 'acknowledged_by', 'recipient_name', 'remarks', 'acknowledged_at'];

    protected function casts(): array
    {
        return ['acknowledged_at' => 'datetime'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'acknowledged_by');
    }
}
