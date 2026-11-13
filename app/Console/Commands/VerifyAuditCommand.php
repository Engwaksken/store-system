<?php

namespace App\Console\Commands;

use App\Models\ActivityLog;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/** Recomputes the activity-log hash chain. Exit code 1 (and the row id) when any row was edited, deleted or inserted out of band. */
class VerifyAuditCommand extends Command
{
    protected $signature = 'cms:verify-audit';

    protected $description = 'Verify the tamper-evident hash chain of the activity log';

    public function handle(): int
    {
        $prev = null;
        $checked = 0;
        $legacy = 0;
        $started = false;
        foreach (ActivityLog::orderBy('id')->lazyById(500) as $log) {
            if ($log->hash === null) {
                if ($started) {
                    return $this->broken($log->id, 'row has no hash after the chain started (inserted outside the application?)');
                }
                $legacy++; // written before Phase 5; not covered by the chain

                continue;
            }
            $started = true;
            if ($log->prev_hash !== $prev) {
                return $this->broken($log->id, 'link to the previous row does not match (a row before it was deleted or altered)');
            }
            if (! hash_equals($log->hash, $log->expectedHash())) {
                return $this->broken($log->id, 'content does not match its hash (the row was edited)');
            }
            $prev = $log->hash;
            $checked++;
        }
        $head = DB::table('audit_chain_heads')->where('id', 1)->value('last_hash');
        if ($started && $head !== $prev) {
            return $this->broken((int) DB::table('audit_chain_heads')->value('last_id'), 'the newest rows were deleted (chain head does not match the last row)');
        }
        $this->info("Audit chain intact: {$checked} rows verified".($legacy ? ", {$legacy} older rows predate the chain" : '').'.');

        return self::SUCCESS;
    }

    private function broken(int $id, string $why): int
    {
        $this->error("Audit chain BROKEN at activity log #{$id}: {$why}.");

        return self::FAILURE;
    }
}
