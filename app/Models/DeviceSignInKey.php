<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * Fingerprint / face sign-in for one phone running the mobile app. The phone holds "{id}|{secret}" in its keystore
 * and releases it only after the device biometrics (or phone PIN) succeed; the server keeps a SHA-256 of the secret.
 */
class DeviceSignInKey extends Model
{
    protected $fillable = ['user_id', 'name', 'secret_hash', 'last_used_at'];

    protected $hidden = ['secret_hash'];

    protected function casts(): array
    {
        return ['last_used_at' => 'datetime'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** Creates a key and returns [model, plain "{id}|{secret}"]; the plain value is shown only once. */
    public static function issue(User $user, string $name): array
    {
        $secret = Str::random(64);
        $key = static::create(['user_id' => $user->id, 'name' => Str::limit($name, 100, ''), 'secret_hash' => hash('sha256', $secret)]);

        return [$key, $key->id.'|'.$secret];
    }

    /** The key matching a plain "{id}|{secret}", or null. Constant-time comparison. */
    public static function findByPlain(?string $plain): ?self
    {
        if (! is_string($plain) || ! str_contains($plain, '|')) {
            return null;
        }
        [$id, $secret] = explode('|', $plain, 2);
        if (! ctype_digit($id) || $secret === '') {
            return null;
        }
        $key = static::find((int) $id);

        return $key && hash_equals($key->secret_hash, hash('sha256', $secret)) ? $key : null;
    }
}
