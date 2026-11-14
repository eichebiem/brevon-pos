<?php

namespace Tests\Feature\System;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Symfony\Component\Process\ExecutableFinder;
use Tests\TestCase;

/** NFR-011, D-185: daily compressed backups with retention. */
class BackupDatabaseCommandTest extends TestCase
{
    use RefreshDatabase;

    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = storage_path('framework/testing/backups-'.uniqid());
        config(['brevon.backup.path' => $this->dir, 'brevon.backup.keep_days' => 14]);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->dir);
        parent::tearDown();
    }

    public function test_it_writes_a_compressed_dump_and_removes_only_old_backups(): void
    {
        if ((new ExecutableFinder)->find((string) config('brevon.backup.mysqldump')) === null) {
            $this->markTestSkipped('mysqldump is not installed here.');
        }
        File::ensureDirectoryExists($this->dir);
        $old = $this->dir.'/brevon_pos_test-20260101-020000.sql.gz';
        $recent = $this->dir.'/brevon_pos_test-20261101-020000.sql.gz';
        $other = $this->dir.'/notes.txt';
        foreach ([$old, $recent, $other] as $f) {
            File::put($f, 'x');
        }
        touch($old, now()->subDays(15)->getTimestamp());
        touch($other, now()->subDays(40)->getTimestamp());

        $this->artisan('brevon:backup')->expectsOutputToContain('1 old backup(s) removed')->assertSuccessful();

        $this->assertFileDoesNotExist($old);
        $this->assertFileExists($recent);
        $this->assertFileExists($other);
        $new = array_values(array_diff(glob($this->dir.'/brevon_pos_test-*.sql.gz') ?: [], [$recent]));
        $this->assertCount(1, $new);
        $sql = (string) gzdecode((string) file_get_contents($new[0]));
        $this->assertStringContainsString('CREATE TABLE `orders`', $sql);
        $this->assertStringNotContainsString((string) config('database.connections.mysql.password') ?: '§never§', $sql);
        $this->assertSame('0700', substr(sprintf('%o', fileperms($this->dir)), -4));
    }

    public function test_a_failed_dump_leaves_no_partial_file(): void
    {
        config(['brevon.backup.mysqldump' => '/nonexistent/mysqldump']);

        $this->artisan('brevon:backup')->expectsOutputToContain('Backup failed')->assertFailed();

        $this->assertSame([], glob($this->dir.'/*.sql.gz') ?: []);
    }

    public function test_the_backup_is_scheduled_daily_in_business_time(): void
    {
        $event = collect(app(Schedule::class)->events())->first(fn ($e) => str_contains((string) $e->command, 'brevon:backup'));

        $this->assertNotNull($event);
        $this->assertSame('0 2 * * *', $event->expression);
        $this->assertSame('Asia/Manila', $event->timezone);
    }
}
