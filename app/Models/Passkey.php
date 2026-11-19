<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A WebAuthn credential (passkey) used to sign in to the web app with fingerprint, face or device PIN. */
class Passkey extends Model
{
    protected $fillable = ['user_id', 'name', 'credential_id', 'public_key', 'sign_count', 'last_used_at'];

    protected $hidden = ['public_key'];

    protected function casts(): array
    {
        return ['sign_count' => 'integer', 'last_used_at' => 'datetime'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
