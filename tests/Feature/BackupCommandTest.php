<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\File;
use Tests\TestCase;
use ZipArchive;

/** Verifies archive creation and retention (SQLite path; the mysqldump path is verified on staging). */
class BackupCommandTest extends TestCase
{
    public function test_backup_creates_zip_with_database_and_prunes_old_archives(): void
    {
        $tmp = storage_path('framework/testing/backup-src.sqlite');
        File::ensureDirectoryExists(dirname($tmp));
        File::put($tmp, 'sqlite-bytes');
        config(['database.default' => 'backup_test', 'database.connections.backup_test' => ['driver' => 'sqlite', 'database' => $tmp]]);

        $dir = storage_path('framework/testing/backups');
        File::ensureDirectoryExists($dir);
        File::deleteDirectory($dir);
        File::ensureDirectoryExists($dir);
        foreach (range(1, 3) as $i) {
            File::put("{$dir}/backup-20000101-00000{$i}.zip", 'old');
        }

        $this->artisan('cms:backup', ['--keep' => 2, '--path' => $dir])->assertSuccessful();

        $after = collect(File::glob("{$dir}/backup-*.zip"))->sort()->values();
        $this->assertCount(2, $after);
        $newest = $after->last();
        $zip = new ZipArchive();
        $this->assertTrue($zip->open($newest) === true);
        $this->assertStringEndsWith('.sql.sqlite', $zip->getNameIndex(0));
        $zip->close();

        File::delete($after->all());
        File::delete($tmp);
    }
}
