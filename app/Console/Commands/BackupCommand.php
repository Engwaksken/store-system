<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Symfony\Component\Process\Process;
use ZipArchive;

/**
 * Daily backup: database dump + uploaded expense proofs, zipped into storage/app/backups.
 * Keeps the newest --keep archives. Copy archives off the server regularly (see docs/DEPLOYMENT.md).
 */
class BackupCommand extends Command
{
    protected $signature = 'cms:backup {--keep=14} {--path= : Target directory (default storage/app/backups)}';

    protected $description = 'Back up the database and uploaded files to storage/app/backups';

    public function handle(): int
    {
        $dir = $this->option('path') ?: storage_path('app/backups');
        File::ensureDirectoryExists($dir);
        $stamp = now()->format('Ymd-His');
        $dump = "{$dir}/db-{$stamp}.sql";
        $conn = config('database.default');
        $cfg = config("database.connections.{$conn}");

        if ($cfg['driver'] === 'mysql' || $cfg['driver'] === 'mariadb') {
            $bin = config('cms.mysqldump_path');
            $p = new Process([$bin, '--single-transaction', '--routines', '--no-tablespaces',
                '-h', $cfg['host'], '-P', (string) $cfg['port'], '-u', $cfg['username'], $cfg['database'], '--result-file='.$dump],
                null, ['MYSQL_PWD' => (string) $cfg['password']]);
            $p->setTimeout(600)->run();
            if (! $p->isSuccessful()) {
                $this->error('mysqldump failed: '.$p->getErrorOutput());

                return self::FAILURE;
            }
        } elseif ($cfg['driver'] === 'sqlite') {
            File::copy($cfg['database'], $dump.'.sqlite');
            $dump .= '.sqlite';
        } else {
            $this->error("Unsupported driver {$cfg['driver']}");

            return self::FAILURE;
        }

        $zipPath = "{$dir}/backup-{$stamp}.zip";
        $zip = new ZipArchive();
        $zip->open($zipPath, ZipArchive::CREATE);
        $zip->addFile($dump, basename($dump));
        $uploads = storage_path('app/private/expense-proofs');
        if (is_dir($uploads)) {
            foreach (File::allFiles($uploads) as $f) {
                $zip->addFile($f->getPathname(), 'expense-proofs/'.$f->getRelativePathname());
            }
        }
        $zip->close();
        File::delete($dump);

        $archives = collect(File::glob("{$dir}/backup-*.zip"))->sort()->values();
        $archives->slice(0, max(0, $archives->count() - (int) $this->option('keep')))->each(fn ($f) => File::delete($f));

        $this->info("Backup written: {$zipPath} (".round(filesize($zipPath) / 1024).' KB)');

        return self::SUCCESS;
    }
}
