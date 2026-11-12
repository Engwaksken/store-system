<?php

namespace App\Console\Commands;

use App\Models\MaterialRequest;
use App\Models\User;
use App\Services\IssueService;
use Illuminate\Console\Command;
use Throwable;

/** Child process for cms:concurrency-test. Waits for the shared start instant, then attempts one issue. */
class ConcurrencyWorker extends Command
{
    protected $signature = 'cms:concurrency-worker {user} {request} {line} {qty} {startAt}';

    protected $description = 'Internal: one racing issue attempt (used by cms:concurrency-test).';

    protected $hidden = true;

    public function handle(IssueService $issues): int
    {
        $user = User::findOrFail($this->argument('user'));
        $req = MaterialRequest::findOrFail($this->argument('request'));
        $wait = (float) $this->argument('startAt') - microtime(true);
        if ($wait > 0) {
            usleep((int) ($wait * 1_000_000));
        }
        try {
            $issue = $issues->issue($user, $req, [(int) $this->argument('line') => $this->argument('qty')], 'Race');
            $this->line("OK {$issue->issue_no}");
        } catch (Throwable $e) {
            $this->line('REFUSED '.class_basename($e).': '.$e->getMessage());
        }

        return self::SUCCESS;
    }
}
