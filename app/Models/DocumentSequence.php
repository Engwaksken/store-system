<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/** Sequential document numbers such as IV-2026-00001, allocated under a row lock. */
class DocumentSequence extends Model
{
    protected $fillable = ['sequence_key', 'last_number'];

    public static function next(string $prefix): string
    {
        $key = $prefix.'-'.now()->format('Y');

        return DB::transaction(function () use ($key) {
            static::query()->insertOrIgnore(['sequence_key' => $key, 'last_number' => 0, 'created_at' => now(), 'updated_at' => now()]);
            $seq = static::where('sequence_key', $key)->lockForUpdate()->firstOrFail();
            $seq->increment('last_number');

            return sprintf('%s-%05d', $key, $seq->last_number);
        });
    }
}
