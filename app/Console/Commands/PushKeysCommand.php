<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Minishlink\WebPush\VAPID;

/** Creates the VAPID key pair for browser push and writes it to .env (once). */
class PushKeysCommand extends Command
{
    protected $signature = 'cms:push-keys {--show : Only print new keys, do not touch .env} {--force : Replace keys that are already set}';

    protected $description = 'Generate the VAPID keys used for browser push notifications';

    public function handle(): int
    {
        $keys = VAPID::createVapidKeys();
        if ($this->option('show')) {
            $this->line('VAPID_PUBLIC_KEY='.$keys['publicKey']);
            $this->line('VAPID_PRIVATE_KEY='.$keys['privateKey']);

            return self::SUCCESS;
        }

        $path = base_path('.env');
        if (! is_file($path) || ! is_writable($path)) {
            $this->error('.env is missing or not writable. Run with --show and add the two lines yourself.');

            return self::FAILURE;
        }
        $env = file_get_contents($path);
        if (preg_match('/^VAPID_PUBLIC_KEY=\S+/m', $env) && ! $this->option('force')) {
            $this->warn('VAPID keys are already set. Changing them makes every device turn push on again; use --force if you really mean it.');

            return self::SUCCESS;
        }
        $subject = rtrim((string) config('app.url'), '/');
        foreach (['VAPID_PUBLIC_KEY' => $keys['publicKey'], 'VAPID_PRIVATE_KEY' => $keys['privateKey'], 'VAPID_SUBJECT' => $subject] as $k => $v) {
            $env = preg_match("/^{$k}=.*$/m", $env)
                ? preg_replace("/^{$k}=.*$/m", "{$k}={$v}", $env)
                : rtrim($env, "\n")."\n{$k}={$v}\n";
        }
        file_put_contents($path, $env);
        $this->info('VAPID keys written to .env. Now run: php artisan config:cache');
        $this->line('Keep a copy of .env with your backups: if the keys are lost, every user has to turn push on again.');

        return self::SUCCESS;
    }
}
