<?php

namespace App\Maintenance;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Symfony\Component\Process\Process;
use Throwable;

/**
 * A full, gzip-compressed copy of the default database, taken before crm:fresh-start wipes anything (F1).
 *
 * MySQL/MariaDB: mysqldump (binary `crm.fresh_start.mysqldump_binary`, env CRM_MYSQLDUMP_PATH) streamed into
 * `{backup_dir}/fresh-start-{Ymd-His}-{rand}.sql.gz`; the password goes through MYSQL_PWD, never the command line.
 * Before dumping, the folder must have at least half the database's size (information_schema) free.
 * SQLite (local/testing): a SQL text dump written here, gzipped, so the same path is testable in memory.
 *
 * A backup is only returned once verified: every compressed write landed, the file exists, is larger than 0 bytes,
 * gunzips to the very end, starts like a dump and ends with the `-- Dump completed` line (a truncated dump has no
 * such line). Anything else throws BackupFailed and leaves no file behind. The file is chmod 0600.
 */
class DatabaseBackup
{
    public const COMPLETED_MARKER = '-- Dump completed';

    /** @return array{path: string, bytes: int, driver: string} */
    public function take(): array
    {
        $driver = $this->driver();
        $dir = (string) config('crm.fresh_start.backup_dir', storage_path('app/backups'));

        try {
            File::ensureDirectoryExists($dir, 0750);
        } catch (Throwable $e) {
            throw new BackupFailed("cannot create the backup folder {$dir}: ".$e->getMessage(), previous: $e);
        }
        if (! is_dir($dir) || ! is_writable($dir)) {
            throw new BackupFailed("the backup folder is not writable: {$dir}");
        }
        if ($driver === 'mysqldump') {
            $this->ensureRoom($dir, $this->mysqlDatabaseBytes());
        }

        $name = 'fresh-start-'.now()->format('Ymd-His').'-'.bin2hex(random_bytes(3)).($driver === 'sqlite' ? '.sqlite.gz' : '.sql.gz');
        $path = $dir.DIRECTORY_SEPARATOR.$name;

        try {
            $driver === 'sqlite' ? $this->writeSqliteDump($path) : $this->mysqldump($path);
            @chmod($path, 0600);
            $bytes = $this->verify($path);
        } catch (Throwable $e) {
            @unlink($path);

            throw $e instanceof BackupFailed ? $e : new BackupFailed($e->getMessage(), previous: $e);
        }

        return ['path' => $path, 'bytes' => $bytes, 'driver' => $driver];
    }

    /** Free space in $dir must be at least half the database size (a gzip dump is far smaller than the data). */
    public function ensureRoom(string $dir, int $databaseBytes): void
    {
        $free = @disk_free_space($dir);
        if ($free !== false && $free < 0.5 * $databaseBytes) {
            throw new BackupFailed(sprintf('not enough disk space in %s: %s MB free, need at least %s MB (half the database).',
                $dir, number_format($free / 1048576, 1), number_format($databaseBytes / 2 / 1048576, 1)));
        }
    }

    /**
     * A usable backup file: exists, > 0 bytes, gunzip-readable to the end with a non-empty body that starts like a
     * mysqldump/MariaDB (or the SQLite) dump and ends with `-- Dump completed`. Returns its size in bytes.
     */
    public function verify(string $path): int
    {
        if (! is_file($path) || ! is_readable($path)) {
            throw new BackupFailed("backup file missing or unreadable: {$path}");
        }
        clearstatcache(true, $path);
        $bytes = (int) filesize($path);
        if ($bytes <= 0) {
            throw new BackupFailed("backup file is empty: {$path}");
        }

        $fh = fopen($path, 'rb');
        $magic = $fh !== false ? (string) fread($fh, 2) : '';
        if ($fh !== false) {
            fclose($fh);
        }
        if ($magic !== "\x1f\x8b") {
            throw new BackupFailed("backup file is not gzip: {$path}");
        }

        $gz = @gzopen($path, 'rb');
        if ($gz === false) {
            throw new BackupFailed("backup file cannot be opened with gunzip: {$path}");
        }
        $head = '';
        $tail = '';
        $total = 0;
        try {
            while (! gzeof($gz)) {
                $chunk = @gzread($gz, 1 << 20);
                if ($chunk === false) {
                    throw new BackupFailed("backup file is corrupt (gunzip failed): {$path}");
                }
                if ($chunk === '') {
                    break;
                }
                if (strlen($head) < 512) {
                    $head .= substr($chunk, 0, 512 - strlen($head));
                }
                $tail = substr($tail.$chunk, -512);
                $total += strlen($chunk);
            }
        } finally {
            gzclose($gz);
        }

        if ($total === 0) {
            throw new BackupFailed("backup file is empty once unzipped: {$path}");
        }
        if (preg_match('/^-- (MySQL|MariaDB|SQLite) dump/m', $head) !== 1) {
            throw new BackupFailed("backup file does not look like a database dump: {$path}");
        }
        if (! str_contains($tail, self::COMPLETED_MARKER)) {
            throw new BackupFailed('backup file is incomplete (no «'.self::COMPLETED_MARKER."» line at the end): {$path}");
        }

        return $bytes;
    }

    private function driver(): string
    {
        $configured = (string) config('crm.fresh_start.backup_driver', 'auto');
        if (in_array($configured, ['mysqldump', 'sqlite'], true)) {
            return $configured;
        }

        $db = DB::connection()->getDriverName();

        return match ($db) {
            'mysql', 'mariadb' => 'mysqldump',
            'sqlite' => 'sqlite',
            default => throw new BackupFailed("no backup method for the {$db} database driver"),
        };
    }

    private function mysqlDatabaseBytes(): int
    {
        try {
            return (int) DB::selectOne('SELECT COALESCE(SUM(data_length + index_length), 0) AS b FROM information_schema.tables WHERE table_schema = DATABASE()')->b;
        } catch (Throwable) {
            return 0; // not MySQL (driver forced in config): no size to check against
        }
    }

    /** @param  resource  $out */
    protected function write($out, string $data): void
    {
        if ($data === '') {
            return;
        }
        if (gzwrite($out, $data) !== strlen($data)) {
            throw new BackupFailed('writing the backup failed (disk full?)');
        }
    }

    /** @param  resource  $out */
    protected function close($out): void
    {
        if (gzclose($out) !== true) {
            throw new BackupFailed('closing the backup file failed (disk full?)');
        }
    }

    /**
     * A plain SQL dump (schema from sqlite_master + one INSERT per row), gzipped, ending with `-- Dump completed`.
     * Works inside a transaction and on an in-memory database (VACUUM INTO / ATTACH cannot), as local and tests use.
     */
    protected function writeSqliteDump(string $path): void
    {
        $pdo = DB::connection()->getPdo();
        $out = @gzopen($path, 'wb6');
        if ($out === false) {
            throw new BackupFailed("cannot write the backup file {$path}");
        }
        $closed = false;
        try {
            $this->write($out, '-- SQLite dump of '.DB::connection()->getDatabaseName().' taken '.now()->toIso8601String()."\nPRAGMA foreign_keys=OFF;\nBEGIN TRANSACTION;\n");
            $objects = $pdo->query("SELECT type, name, sql FROM sqlite_master WHERE sql IS NOT NULL AND name NOT LIKE 'sqlite_%' ORDER BY CASE type WHEN 'table' THEN 0 ELSE 1 END, name")->fetchAll(\PDO::FETCH_ASSOC);
            foreach ($objects as $o) {
                $this->write($out, $o['sql'].";\n");
                if ($o['type'] !== 'table') {
                    continue;
                }
                $table = str_replace('"', '""', $o['name']);
                $rows = $pdo->query("SELECT * FROM \"{$table}\"");
                while (($row = $rows->fetch(\PDO::FETCH_NUM)) !== false) {
                    $values = array_map(fn ($v) => $v === null ? 'NULL' : (is_int($v) || is_float($v) ? (string) $v : $pdo->quote((string) $v)), $row);
                    $this->write($out, "INSERT INTO \"{$table}\" VALUES(".implode(',', $values).");\n");
                }
            }
            $this->write($out, "COMMIT;\n".self::COMPLETED_MARKER.' on '.now()->toDateTimeString()."\n");
            $closed = true;
            $this->close($out);
        } finally {
            if (! $closed) {
                @gzclose($out);
            }
        }
    }

    private function mysqldump(string $path): void
    {
        $c = DB::connection()->getConfig();
        $args = [(string) config('crm.fresh_start.mysqldump_binary', 'mysqldump'),
            '--single-transaction', '--quick', '--triggers', '--hex-blob', '--no-tablespaces', '--default-character-set=utf8mb4'];
        if (config('crm.fresh_start.mysqldump_routines', true)) {
            array_push($args, '--routines', '--events');
        }
        if (! empty($c['unix_socket'])) {
            $args[] = '--socket='.$c['unix_socket'];
        } else {
            $args[] = '--host='.($c['host'] ?? '127.0.0.1');
            $args[] = '--port='.($c['port'] ?? 3306);
        }
        $args[] = '--user='.($c['username'] ?? 'root');
        $args[] = (string) ($c['database'] ?? '');

        $out = @gzopen($path, 'wb6');
        if ($out === false) {
            throw new BackupFailed("cannot write the backup file {$path}");
        }
        $stderr = '';
        $closed = false;
        try {
            $process = new Process($args, base_path(), ['MYSQL_PWD' => (string) ($c['password'] ?? '')], null, null);
            $process->start();
            foreach ($process as $type => $data) {
                if ($type === Process::OUT) {
                    $this->write($out, $data);
                } elseif (strlen($stderr) < 4000) {
                    $stderr .= $data;
                }
            }
            $process->wait();
            $closed = true;
            $this->close($out);
        } catch (BackupFailed $e) {
            throw $e;
        } catch (Throwable $e) {
            throw new BackupFailed('mysqldump could not run: '.$e->getMessage(), previous: $e);
        } finally {
            if (! $closed) {
                @gzclose($out);
            }
        }

        if (! $process->isSuccessful()) {
            throw new BackupFailed(self::explainDumpError((int) $process->getExitCode(), trim($stderr)));
        }
    }

    /** mysqldump's error, plus what to do when it is a privilege problem with routines/events. */
    public static function explainDumpError(int $exit, string $stderr): string
    {
        $message = "mysqldump failed (exit {$exit}): {$stderr}";
        if (preg_match('/access denied|privilege|routine|event|SHOW VIEW|TRIGGER|LOCK TABLES/i', $stderr) === 1) {
            $message .= "\nThe database user lacks a privilege the dump needs (SELECT, SHOW VIEW, TRIGGER, EVENT, and access to routines)."
                .' Ask the host to grant them, or retry without routines/events: php artisan crm:fresh-start --no-routines';
        }

        return $message;
    }
}
