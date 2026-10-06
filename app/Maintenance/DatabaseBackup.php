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
 * SQLite (local/testing): a SQL text dump written here, gzipped, so the same path is testable in memory.
 *
 * A backup is only returned once verified: the file exists, is larger than 0 bytes, gunzips to the end, and its
 * body starts like a dump. Anything else throws BackupFailed and leaves no file behind.
 */
final class DatabaseBackup
{
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

        $name = 'fresh-start-'.now()->format('Ymd-His').'-'.bin2hex(random_bytes(3)).($driver === 'sqlite' ? '.sqlite.gz' : '.sql.gz');
        $path = $dir.DIRECTORY_SEPARATOR.$name;

        try {
            $driver === 'sqlite' ? $this->sqlite($path) : $this->mysqldump($path);
            $bytes = $this->verify($path);
        } catch (Throwable $e) {
            @unlink($path);

            throw $e instanceof BackupFailed ? $e : new BackupFailed($e->getMessage(), previous: $e);
        }

        return ['path' => $path, 'bytes' => $bytes, 'driver' => $driver];
    }

    /**
     * A usable backup file: exists, > 0 bytes, gunzip-readable to the end with a non-empty body that starts like a
     * mysqldump/MariaDB (or the SQLite) dump. Returns its size in bytes.
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
        $total = 0;
        try {
            while (! gzeof($gz)) {
                $chunk = gzread($gz, 1 << 20);
                if ($chunk === false) {
                    throw new BackupFailed("backup file is corrupt (gunzip failed): {$path}");
                }
                if (strlen($head) < 512) {
                    $head .= substr($chunk, 0, 512 - strlen($head));
                }
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

    /**
     * A plain SQL dump (schema from sqlite_master + one INSERT per row), gzipped. Works inside a transaction and on an
     * in-memory database (VACUUM INTO / ATTACH cannot), which is what local and the test suite use.
     */
    private function sqlite(string $path): void
    {
        $pdo = DB::connection()->getPdo();
        $out = @gzopen($path, 'wb6');
        if ($out === false) {
            throw new BackupFailed("cannot write the backup file {$path}");
        }
        try {
            gzwrite($out, '-- SQLite dump of '.DB::connection()->getDatabaseName().' taken '.now()->toIso8601String()."\nPRAGMA foreign_keys=OFF;\nBEGIN TRANSACTION;\n");
            $objects = $pdo->query("SELECT type, name, sql FROM sqlite_master WHERE sql IS NOT NULL AND name NOT LIKE \x27sqlite_%\x27 ORDER BY CASE type WHEN \x27table\x27 THEN 0 ELSE 1 END, name")->fetchAll(\PDO::FETCH_ASSOC);
            foreach ($objects as $o) {
                gzwrite($out, $o['sql'].";\n");
                if ($o['type'] !== 'table') {
                    continue;
                }
                $table = str_replace('"', '""', $o['name']);
                $rows = $pdo->query("SELECT * FROM \"{$table}\"");
                while (($row = $rows->fetch(\PDO::FETCH_NUM)) !== false) {
                    $values = array_map(fn ($v) => $v === null ? 'NULL' : (is_int($v) || is_float($v) ? (string) $v : $pdo->quote((string) $v)), $row);
                    gzwrite($out, "INSERT INTO \"{$table}\" VALUES(".implode(',', $values).");\n");
                }
            }
            gzwrite($out, "COMMIT;\n");
        } finally {
            gzclose($out);
        }
    }

    private function mysqldump(string $path): void
    {
        $c = DB::connection()->getConfig();
        $args = [(string) config('crm.fresh_start.mysqldump_binary', 'mysqldump'),
            '--single-transaction', '--quick', '--routines', '--triggers', '--no-tablespaces', '--default-character-set=utf8mb4'];
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
        try {
            $process = new Process($args, base_path(), ['MYSQL_PWD' => (string) ($c['password'] ?? '')], null, null);
            $process->start();
            foreach ($process as $type => $data) {
                if ($type === Process::OUT) {
                    gzwrite($out, $data);
                } elseif (strlen($stderr) < 4000) {
                    $stderr .= $data;
                }
            }
            $process->wait();
        } catch (Throwable $e) {
            throw new BackupFailed('mysqldump could not run: '.$e->getMessage(), previous: $e);
        } finally {
            gzclose($out);
        }

        if (! $process->isSuccessful()) {
            throw new BackupFailed('mysqldump failed (exit '.$process->getExitCode().'): '.trim($stderr));
        }
    }
}
