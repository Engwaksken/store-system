<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CustomRole extends Model
{
    protected $fillable = ['name', 'permission_profile'];

    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }
}
