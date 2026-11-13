<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Supplier extends Model
{
    protected $fillable = ['code', 'name', 'contact_person', 'phone', 'email', 'address', 'tin', 'payment_terms', 'is_active'];

    protected $attributes = ['is_active' => true];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    public function purchaseOrders(): HasMany { return $this->hasMany(PurchaseOrder::class); }
    public function invoices(): HasMany { return $this->hasMany(PurchaseInvoice::class); }
    public function quotes(): HasMany { return $this->hasMany(SupplierQuote::class); }

    public function label(): string
    {
        return "{$this->code} · {$this->name}";
    }
}
