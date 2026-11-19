<?php

namespace App\Http\Controllers;

use App\Exceptions\WorkflowException;
use App\Models\ActivityLog;
use Closure;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\QueryException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

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

    /** The ticked row ids posted by a bulk bar. */
    protected function bulkIds(Request $request, string $rule = 'integer'): array
    {
        return $request->validate(['ids' => 'required|array|min:1|max:200', 'ids.*' => $rule], ['ids.required' => 'Select at least one row first.'])['ids'];
    }

    /**
     * Delete a master record only when nothing uses it. $inUse returns why it is still needed (or null); anything else
     * that refers to it is refused by the database (restrict foreign keys) and reported as "in use". Never deletes history.
     *
     * @param  Closure(): ?string  $inUse
     */
    protected function deleteUnused(Model $record, string $label, ?Closure $inUse = null, ?Closure $before = null): void
    {
        if ($inUse && ($why = $inUse())) {
            throw new WorkflowException($why);
        }
        try {
            DB::transaction(function () use ($record, $before) {
                $before && $before();
                $record->delete();
            });
        } catch (QueryException) {
            throw new WorkflowException('in use on documents or transactions; deactivate it instead');
        }
        ActivityLog::record('record.deleted', class_basename($record)." {$label} deleted");
    }
}
