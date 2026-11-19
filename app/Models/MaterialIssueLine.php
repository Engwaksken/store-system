<?php

namespace App\Models;

use App\Models\Concerns\Immutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MaterialIssueLine extends Model
{
    use Immutable;

    protected $fillable = ['material_issue_id', 'material_request_line_id', 'item_id', 'qty', 'unit_cost_snapshot', 'line_value'];

    protected function casts(): array
    {
        return ['qty' => 'decimal:3', 'unit_cost_snapshot' => 'decimal:4', 'line_value' => 'decimal:2'];
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(InventoryItem::class, 'item_id');
    }

    public function issue(): BelongsTo
    {
        return $this->belongsTo(MaterialIssue::class, 'material_issue_id');
    }

    public function returnLines(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(StockReturnLine::class);
    }

    public function requestLine(): BelongsTo
    {
        return $this->belongsTo(MaterialRequestLine::class, 'material_request_line_id');
    }

    /** Final approved quantity on the request line. */
    public function approvedQty(): string
    {
        return bcadd((string) ($this->requestLine?->final_approved_qty ?? 0), '0', 3);
    }

    /** Issued on this request line up to and including this voucher. */
    public function issuedToDate(): string
    {
        $sum = static::where('material_request_line_id', $this->material_request_line_id)
            ->where('material_issue_id', '<=', $this->material_issue_id)->sum('qty');

        return bcadd((string) $sum, '0', 3);
    }

    /** Balance still to issue on the request line right after this voucher (stays outstanding on the request). */
    public function balanceAfter(): string
    {
        $left = bcsub($this->approvedQty(), $this->issuedToDate(), 3);

        return bccomp($left, '0', 3) < 0 ? '0.000' : $left;
    }

    /** Quantity already returned to store (derived; issue lines themselves are immutable). */
    public function returnedQty(): string
    {
        $rows = $this->relationLoaded('returnLines') ? $this->returnLines : $this->returnLines()->get();

        return $rows->reduce(fn ($c, $r) => bcadd($c, (string) $r->qty, 3), '0.000');
    }
}
