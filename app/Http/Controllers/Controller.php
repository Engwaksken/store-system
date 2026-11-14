<?php

namespace App\Http\Controllers;

use App\Exceptions\WorkflowException;
use Closure;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\RedirectResponse;

abstract class Controller
{
    /**
     * Apply one bulk action to each selected record on its own. A record that may not take the action
     * (permission or status, signalled by WorkflowException / AuthorizationException) is skipped and listed; the rest are processed.
     *
     * @param  iterable  $records
     * @param  Closure(mixed): string  $label  record number shown in the messages
     * @param  Closure(mixed): void  $apply
     */
    protected function bulkApply(iterable $records, Closure $label, Closure $apply, string $noun, string $verb): RedirectResponse
    {
        $done = [];
        $skipped = [];
        foreach ($records as $record) {
            $name = $label($record);
            try {
                $apply($record);
                $done[] = $name;
            } catch (WorkflowException|AuthorizationException $e) {
                $skipped[] = "{$name}: ".$e->getMessage();
            }
        }
        $back = back()->with('status', count($done)." {$noun}(s) {$verb}".($done ? ' ('.implode(', ', $done).')' : '').'.');

        return $skipped ? $back->withErrors(['bulk' => 'Skipped '.count($skipped).': '.implode('; ', $skipped)]) : $back;
    }
}
