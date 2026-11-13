<?php

namespace App\Support;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\UniqueConstraintViolationException;

/**
 * Makes a document-posting call safe to repeat (double-click, browser resubmit, network retry).
 *
 * Forms carry a one-time key. If a document with that key already exists it is returned unchanged;
 * if two requests race, the unique index on idempotency_key lets only one commit and the loser
 * returns the winner's document. Callers can tell a replay by $model->wasRecentlyCreated === false.
 */
final class Idempotency
{
    /**
     * @template T of Model
     * @param  class-string<T>  $modelClass
     * @param  callable(): T  $post
     * @return T
     */
    public static function run(string $modelClass, ?string $key, callable $post): Model
    {
        $key = $key !== null ? trim($key) : null;
        if ($key === '' || $key === null) {
            return $post();
        }
        if ($existing = $modelClass::where('idempotency_key', $key)->first()) {
            return $existing;
        }
        try {
            return $post();
        } catch (UniqueConstraintViolationException $e) {
            if ($existing = $modelClass::where('idempotency_key', $key)->first()) {
                return $existing;
            }
            throw $e;
        }
    }
}
