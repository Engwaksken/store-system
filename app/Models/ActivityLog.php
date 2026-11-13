<?php

namespace App\Models;

use App\Models\Concerns\Immutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\DB;

/**
 * Append-only audit trail with tamper evidence.
 *
 * Every row carries prev_hash (the previous row's hash) and hash = HMAC-SHA256(prev_hash | canonical content, APP_KEY).
 * Writers serialize on the single audit_chain_heads row, so the chain is linear even under concurrent requests.
 * `php artisan cms:verify-audit` recomputes the chain and reports the first broken link (edited or deleted rows).
 */
class ActivityLog extends Model
{
    use Immutable;

    public const UPDATED_AT = null;

    protected $fillable = ['user_id', 'action', 'subject_type', 'subject_id', 'description', 'properties', 'ip_address', 'created_at', 'prev_hash', 'hash'];

    protected function casts(): array
    {
        return ['properties' => 'array', 'created_at' => 'datetime'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public static function record(string $action, string $description, ?Model $subject = null, array $properties = []): self
    {
        $row = [
            'user_id' => auth()->id(),
            'action' => $action,
            'subject_type' => $subject ? class_basename($subject) : null,
            'subject_id' => $subject?->getKey(),
            'description' => mb_substr($description, 0, 1000),
            'properties' => $properties ?: null,
            'ip_address' => request()?->ip(),
            'created_at' => now()->startOfSecond(),
        ];

        return DB::transaction(function () use ($row) {
            $head = DB::table('audit_chain_heads')->where('id', 1)->lockForUpdate()->first();
            $prev = $head?->last_hash;
            $log = static::create($row + ['prev_hash' => $prev, 'hash' => static::computeHash($prev, $row)]);
            DB::table('audit_chain_heads')->updateOrInsert(['id' => 1], ['last_hash' => $log->hash, 'last_id' => $log->id]);

            return $log;
        });
    }

    /** @param array<string, mixed> $row */
    public static function computeHash(?string $prevHash, array $row): string
    {
        $created = $row['created_at'] instanceof \DateTimeInterface ? $row['created_at']->format('Y-m-d H:i:s') : (string) $row['created_at'];
        $payload = implode("\x1F", [
            $prevHash ?? '', (string) ($row['user_id'] ?? ''), $row['action'], (string) ($row['subject_type'] ?? ''),
            (string) ($row['subject_id'] ?? ''), $row['description'], static::canonicalJson($row['properties'] ?? null),
            (string) ($row['ip_address'] ?? ''), $created,
        ]);

        return hash_hmac('sha256', $payload, (string) config('app.key'));
    }

    /** Key-sorted JSON so MySQL's JSON key reordering cannot produce false alarms. */
    public static function canonicalJson(mixed $value): string
    {
        if ($value === null || $value === []) {
            return '';
        }
        $sort = function ($v) use (&$sort) {
            if (! is_array($v)) {
                return is_scalar($v) || $v === null ? (string) $v : $v;
            }
            if (! array_is_list($v)) {
                ksort($v);
            }

            return array_map($sort, $v);
        };

        return json_encode($sort($value), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    public function expectedHash(): string
    {
        return static::computeHash($this->prev_hash, [
            'user_id' => $this->user_id, 'action' => $this->action, 'subject_type' => $this->subject_type, 'subject_id' => $this->subject_id,
            'description' => $this->description, 'properties' => $this->properties, 'ip_address' => $this->ip_address,
            'created_at' => $this->created_at,
        ]);
    }
}
