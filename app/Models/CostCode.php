<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Company-wide cost codes used for budgets, BOQ lines, POs and expenses. */
class CostCode extends Model
{
    public const CATEGORIES = ['materials' => 'Materials', 'labour' => 'Labour', 'equipment' => 'Equipment', 'subcontract' => 'Subcontract', 'overheads' => 'Overheads'];

    protected $fillable = ['code', 'name', 'category', 'is_active'];

    protected $attributes = ['is_active' => true, 'category' => 'materials'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    public function label(): string
    {
        return "{$this->code} · {$this->name}";
    }
}
