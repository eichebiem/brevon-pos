<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Symfony\Component\Process\Process;

/**
 * Daily database backup (NFR-011, D-185): a gzip-compressed `mysqldump` of
 * the app's database in `brevon.backup.path`, named
 * `<database>-YYYYMMDD-HHMMSS.sql.gz`. Backups older than
 * `brevon.backup.keep_days` are deleted. The password goes to mysqldump in
 * a temporary private options file, never on the command line. Restore
 * steps: docs/DEPLOYMENT.md.
 */
class BackupDatabaseCommand extends Command
{
    protected $signature = 'brevon:backup';

    protected $description = 'Back up the database to a compressed file and remove old backups';

    public function handle(): int
    {
        /** @var array<string, mixed> $db */
        $db = config('database.connections.'.config('database.default'));
        if (($db['driver'] ?? null) !== 'mysql') {
            $this->error('Backups need the MySQL database.');

            return self::FAILURE;
        }

        $dir = (string) config('brevon.backup.path');
        File::ensureDirectoryExists($dir, 0700);
        // Backups hold every sale: only the app's own user may read them.
        @chmod($dir, 0700);
        $name = (string) $db['database'];
        $file = $dir.'/'.$name.'-'.now((string) config('brevon.business_timezone'))->format('Ymd-His').'.sql.gz';

        $options = tempnam(sys_get_temp_dir(), 'brevon-dump');
        if ($options === false) {
            $this->error('Could not create a temporary file.');

            return self::FAILURE;
        }
        chmod($options, 0600);
        file_put_contents($options, $this->optionsFile($db));

        $gz = gzopen($file, 'wb6');
        if ($gz === false) {
            unlink($options);
            $this->error("Could not write {$file}.");

            return self::FAILURE;
        }

        $process = new Process([
            (string) config('brevon.backup.mysqldump'),
            '--defaults-extra-file='.$options,
            '--single-transaction', '--quick', '--triggers', '--no-tablespaces',
            '--default-character-set=utf8mb4',
            $name,
        ], timeout: 3600);

        $errors = '';
        try {
            $process->run(function (string $type, string $chunk) use ($gz, &$errors) {
                $type === Process::OUT ? gzwrite($gz, $chunk) : $errors .= $chunk;
            });
        } finally {
            gzclose($gz);
            unlink($options);
        }

        if (! $process->isSuccessful()) {
            File::delete($file);
            $message = trim($errors) !== '' ? trim($errors) : 'mysqldump failed';
            Log::error('Database backup failed: '.$message);
            $this->error('Backup failed: '.$message);

            return self::FAILURE;
        }

        $removed = $this->prune($dir, $name);
        $size = number_format((int) filesize($file) / 1024, 0).' KB';
        Log::info("Database backup written: {$file} ({$size}); {$removed} old backup(s) removed.");
        $this->info("Backup written: {$file} ({$size}). {$removed} old backup(s) removed.");

        return self::SUCCESS;
    }

    /** @param  array<string, mixed>  $db */
    private function optionsFile(array $db): string
    {
        $quote = fn (mixed $v) => '"'.addcslashes((string) $v, '\\"').'"';
        $lines = ['[client]', 'user='.$quote($db['username'] ?? ''), 'password='.$quote($db['password'] ?? '')];
        if (! empty($db['unix_socket'])) {
            $lines[] = 'socket='.$quote($db['unix_socket']);
        } else {
            $lines[] = 'host='.$quote($db['host'] ?? '127.0.0.1');
            $lines[] = 'port='.(int) ($db['port'] ?? 3306);
        }

        return implode("\n", $lines)."\n";
    }

    /** Deletes this database's backups older than the retention period. */
    private function prune(string $dir, string $name): int
    {
        $cutoff = now()->subDays(max(1, (int) config('brevon.backup.keep_days')))->getTimestamp();
        $removed = 0;
        foreach (glob($dir.'/'.$name.'-*.sql.gz') ?: [] as $old) {
            if (preg_match('/-\d{8}-\d{6}\.sql\.gz$/', $old) && filemtime($old) < $cutoff) {
                File::delete($old);
                $removed++;
            }
        }

        return $removed;
    }
}
