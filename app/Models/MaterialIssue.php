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

    protected $fillable = ['issue_no', 'material_request_id', 'project_id', 'issued_by', 'recipient_name', 'issued_at', 'total_value', 'notes'];

    protected function casts(): array
    {
        return ['issued_at' => 'datetime', 'total_value' => 'decimal:2', 'project_id' => 'integer', 'material_request_id' => 'integer'];
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
}
