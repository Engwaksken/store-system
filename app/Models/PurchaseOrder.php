<?php

namespace App\Models;

use App\Enums\Role;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Status, decision and quantity counters are written only by PurchasingService / GoodsReceiptService / PurchaseInvoiceService. */
class PurchaseOrder extends Model
{
    public const STATUSES = [
        'pending_approval' => 'Awaiting approval', 'approved' => 'Approved – awaiting delivery', 'rejected' => 'Rejected',
        'partially_received' => 'Partially received', 'received' => 'Fully received', 'closed' => 'Closed', 'cancelled' => 'Cancelled',
    ];

    /** Statuses in which goods can still be received against the PO. */
    public const RECEIVABLE = ['approved', 'partially_received'];

    protected $fillable = ['po_no', 'supplier_id', 'purchase_requisition_id', 'supplier_quote_id', 'project_id', 'cost_code_id', 'store_id',
        'order_date', 'expected_on', 'total', 'terms', 'notes', 'created_by'];

    protected $attributes = ['status' => 'pending_approval', 'lock_version' => 0];

    protected function casts(): array
    {
        return ['order_date' => 'date', 'expected_on' => 'date', 'decided_at' => 'datetime', 'total' => 'decimal:2',
            'created_by' => 'integer', 'project_id' => 'integer', 'store_id' => 'integer', 'lock_version' => 'integer'];
    }

    public function supplier(): BelongsTo { return $this->belongsTo(Supplier::class); }
    public function requisition(): BelongsTo { return $this->belongsTo(PurchaseRequisition::class, 'purchase_requisition_id'); }
    public function quote(): BelongsTo { return $this->belongsTo(SupplierQuote::class, 'supplier_quote_id'); }
    public function project(): BelongsTo { return $this->belongsTo(Project::class); }
    public function store(): BelongsTo { return $this->belongsTo(Store::class); }
    public function creator(): BelongsTo { return $this->belongsTo(User::class, 'created_by'); }
    public function decider(): BelongsTo { return $this->belongsTo(User::class, 'decided_by'); }
    public function lines(): HasMany { return $this->hasMany(PurchaseOrderLine::class); }
    public function receipts(): HasMany { return $this->hasMany(StockReceipt::class); }
    public function invoices(): HasMany { return $this->hasMany(PurchaseInvoice::class); }

    public function statusLabel(): string { return self::STATUSES[$this->status] ?? $this->status; }

    public function tone(): string
    {
        return match ($this->status) {
            'pending_approval' => 'pending', 'approved', 'partially_received' => 'partial', 'received', 'closed' => 'approved',
            'rejected' => 'rejected', default => 'muted',
        };
    }

    public function isReceivable(): bool { return in_array($this->status, self::RECEIVABLE, true); }

    /** Value ordered but not yet received (open commitment). */
    public function openValue(): string
    {
        if (! in_array($this->status, self::RECEIVABLE, true)) {
            return '0.00';
        }
        $lines = $this->relationLoaded('lines') ? $this->lines : $this->lines()->get();

        return $lines->reduce(fn ($c, $l) => bcadd($c, bcmul(bcsub((string) $l->qty, (string) $l->received_qty, 3), (string) $l->unit_price, 2), 2), '0.00');
    }

    public function scopeVisibleTo(Builder $q, User $user): Builder
    {
        if ($user->hasRole(Role::Admin, Role::OverallManager, Role::Procurement, Role::Accountant)) {
            return $q;
        }
        if ($user->role === Role::Storekeeper) {
            return $q->whereIn('store_id', Store::operableBy($user)->select('id'));
        }
        if ($user->role === Role::ProjectManager) {
            return $q->whereIn('project_id', Project::where('manager_id', $user->id)->select('id'));
        }

        return $q->whereRaw('1 = 0');
    }

    public function costCode(): BelongsTo
    {
        return $this->belongsTo(CostCode::class);
    }
}
