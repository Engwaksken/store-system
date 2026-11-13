<?php

namespace App\Console\Commands;

use App\Enums\Role;
use App\Models\CostCode;
use App\Models\Store;
use App\Models\User;
use Illuminate\Console\Command;

/** Go-live / health checklist: configuration, data readiness, books reconciliation and audit-chain integrity. Read-only. */
class GoLiveCheckCommand extends Command
{
    protected $signature = 'cms:go-live-check';

    protected $description = 'Check production configuration, readiness data, reconciliation and audit integrity';

    public function handle(): int
    {
        $rows = [];
        $fail = false;
        $add = function (string $status, string $check, string $detail = '') use (&$rows, &$fail) {
            $rows[] = [$status, $check, $detail];
            $fail = $fail || $status === 'FAIL';
        };
        $prod = app()->isProduction();

        $add($prod ? 'PASS' : 'WARN', 'APP_ENV is production', config('app.env'));
        $add(config('app.debug') ? 'FAIL' : 'PASS', 'APP_DEBUG is off');
        $add(config('app.key') ? 'PASS' : 'FAIL', 'APP_KEY is set (keep a sealed copy with backups)');
        $add(str_starts_with((string) config('app.url'), 'https://') ? 'PASS' : ($prod ? 'FAIL' : 'WARN'), 'APP_URL uses HTTPS', (string) config('app.url'));
        $add(config('session.secure') ? 'PASS' : ($prod ? 'FAIL' : 'WARN'), 'SESSION_SECURE_COOKIE=true');
        $db = config('database.default');
        $add(in_array($db, ['mysql', 'mariadb'], true) ? 'PASS' : ($prod ? 'FAIL' : 'WARN'), 'Database is MySQL/MariaDB', $db);
        $mailer = config('mail.default');
        $add(in_array($mailer, ['log', 'array'], true) ? 'WARN' : 'PASS', 'Mail is configured for alerts and password resets', $mailer);
        $add(is_link(public_path('storage')) || is_dir(public_path('storage')) ? 'PASS' : 'WARN', 'storage:link done (logo on vouchers)');

        $add(User::where('role', Role::Admin)->where('is_active', true)->exists() ? 'PASS' : 'FAIL', 'At least one active Owner/Admin');
        $add(User::where('role', Role::OverallManager)->where('is_active', true)->exists() ? 'PASS' : 'FAIL', 'An active Overall Manager exists (final approvals)');
        $demo = User::where('email', 'like', '%@demo.test')->count();
        $add($demo === 0 ? 'PASS' : ($prod ? 'FAIL' : 'WARN'), 'No demo accounts', $demo ? "{$demo} demo users" : '');
        $unstaffed = Store::where('is_active', true)->whereDoesntHave('users')->pluck('code');
        $add($unstaffed->isEmpty() ? 'PASS' : 'WARN', 'Every active store has a storekeeper', $unstaffed->implode(', '));
        $add(CostCode::where('is_active', true)->exists() ? 'PASS' : 'WARN', 'Cost codes exist');

        $reconcile = $this->callSilently('cms:reconcile');
        $add($reconcile === 0 ? 'PASS' : 'FAIL', 'Books reconcile (cms:reconcile)');
        $audit = $this->callSilently('cms:verify-audit');
        $add($audit === 0 ? 'PASS' : 'FAIL', 'Audit chain intact (cms:verify-audit)');

        $this->table(['Result', 'Check', 'Detail'], $rows);
        $fail ? $this->error('Not ready: fix the FAIL lines.') : $this->info('Ready (review any WARN lines).');

        return $fail ? self::FAILURE : self::SUCCESS;
    }
}
