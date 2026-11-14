<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class InventoryUnit extends Model
{
    protected $fillable = ['name', 'abbreviation'];

    public function items(): HasMany
    {
        return $this->hasMany(InventoryItem::class, 'unit_id');
    }
}
