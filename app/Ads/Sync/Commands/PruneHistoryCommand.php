<?php

namespace App\Ads\Sync\Commands;

use App\Ads\Audit\AdsAudit;
use App\Ads\Sync\HistoryPruner;
use App\Ads\Sync\HistoryWindow;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Throwable;

/**
 * Deletes ads-only history before the history start (D2). DESTRUCTIVE with --force; a dry run otherwise.
 * Outside local/testing --force also needs --backup=<mysqldump file>: a dump (plain or gzip) written in the last
 * 24 h that contains CREATE TABLE for every pruned table that exists. Never scheduled.
 * Every run writes audit rows: previewed | refused | started + pruned.
 */
class PruneHistoryCommand extends Command
{
    protected $signature = 'ads:prune-history
        {--before= : Delete rows before this Y-m-d day (default and maximum: crm.ads.history_start)}
        {--force : Actually delete (without it this is a dry run)}
        {--backup= : Path of the mysqldump taken just before (required with --force outside local/testing)}
        {--chunk=5000 : Rows deleted per transaction}';

    protected $description = 'Delete ads-only rows dated before the history start (dry run unless --force)';

    public const MIN_BACKUP_BYTES = 1024;

    public const MAX_BACKUP_AGE_SECONDS = 86400;

    private bool $stopRequested = false;

    public function handle(HistoryPruner $pruner): int
    {
        $start = HistoryWindow::start()->toDateString();
        $raw = (string) ($this->option('before') ?: $start);
        $force = (bool) $this->option('force');

        $before = $this->parseDay($raw);
        if ($before === null) {
            return $this->refuse("Invalid --before date \"{$raw}\" (expected Y-m-d).", ['before' => $raw]);
        }
        if ($before->toDateString() > $start) {
            return $this->refuse("--before {$raw} is later than history start {$start}: it would delete kept history.", ['before' => $raw]);
        }

        $chunk = (int) $this->option('chunk');
        if ($chunk < 1) {
            return $this->refuse('--chunk must be a positive integer.', ['before' => $raw]);
        }

        $day = $before->toDateString();
        $preview = $pruner->preview($before);
        $present = array_filter($preview, fn (array $r) => $r['status'] === 'present');
        $counts = array_map(fn (array $r) => $r['delete'], $present);

        $backup = null;
        $backupPath = (string) $this->option('backup');
        if ($backupPath !== '') {
            $checked = $this->checkBackup($backupPath, array_keys($present));
            if (is_string($checked)) {
                return $this->refuse($checked, ['before' => $raw, 'backup_path' => $backupPath]);
            }
            $backup = $checked;
        } elseif ($force && ! app()->environment(['local', 'testing'])) {
            return $this->refuse('backup file missing: outside local/testing --force requires --backup=<path of the dump taken just before>.', ['before' => $raw]);
        }

        $this->printPreview($preview, $before);

        if (! $force) {
            AdsAudit::record('ads.history_prune_previewed', meta: ['before' => $day, 'counts' => $counts, 'backup' => $backup]);
            $this->newLine();
            $this->info('Dry run: nothing deleted. Re-run with --force'.(app()->environment(['local', 'testing']) ? '' : ' --backup=<path>').' to delete.');

            return self::SUCCESS;
        }

        // Written before the first delete: if the process dies, the log still says a prune began, from which backup.
        AdsAudit::record('ads.history_prune_started', meta: ['before' => $day, 'counts' => $counts, 'backup' => $backup]);
        $this->trapStopSignals();

        $this->newLine();
        $this->warn("Deleting ads rows before {$day} in chunks of {$chunk}...");
        $done = [];
        try {
            $result = $pruner->prune(
                $before,
                $chunk,
                function (string $table, int $n, int $total) use (&$done) {
                    $done[$table] = $total;
                    $this->line("  {$table}: deleted {$n} (total {$total})");
                },
                fn () => $this->stopRequested,
            );
        } catch (Throwable $e) {
            try {
                AdsAudit::record('ads.history_pruned', meta: ['before' => $day, 'counts' => $done, 'backup' => $backup, 'error' => mb_substr($e->getMessage(), 0, 500)]);
            } catch (Throwable) {
                // the DB may be gone; never hide the original error
            }
            $this->error('Prune stopped with an error after deleting: '.json_encode($done).'. Re-run to continue.');

            throw $e;
        }

        AdsAudit::record('ads.history_pruned', meta: ['before' => $day, 'counts' => $result, 'backup' => $backup] + ($this->stopRequested ? ['interrupted' => true] : []));

        foreach ($result as $table => $n) {
            $this->line("{$table}: {$n} rows deleted");
        }
        if ($this->stopRequested) {
            $this->warn('Stopped by a signal after the current chunk. '.array_sum($result).' rows deleted; re-run to continue.');

            return self::FAILURE;
        }
        $this->info('Done. '.array_sum($result).' rows deleted.');

        return self::SUCCESS;
    }

    /** Ask for a clean stop after the current chunk; needs pcntl (absent on Windows, where Ctrl+C just kills the process). */
    private function trapStopSignals(): void
    {
        if (! extension_loaded('pcntl') || ! defined('SIGINT')) {
            return;
        }
        $this->trap([SIGINT, SIGTERM, SIGHUP], function () {
            $this->stopRequested = true;
        });
    }

    private function parseDay(string $raw): ?CarbonImmutable
    {
        if (! preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $raw, $m) || ! checkdate((int) $m[2], (int) $m[3], (int) $m[1])) {
            return null;
        }

        return CarbonImmutable::parse($raw, HistoryWindow::TIMEZONE)->startOfDay();
    }

    /**
     * A usable backup: a readable file > 1 KB, modified in the last 24 h, starting with a mysqldump/MariaDB dump header
     * (plain or gzip; gzopen reads both) and holding CREATE TABLE for every table about to be pruned.
     *
     * @param  list<string>  $tables
     * @return array{path: string, bytes: int, mtime: string}|string the backup facts, or the refusal reason
     */
    private function checkBackup(string $path, array $tables): array|string
    {
        clearstatcache(true, $path);
        if (! is_file($path) || ! is_readable($path)) {
            return "backup file missing or unreadable: {$path}";
        }
        $real = (string) realpath($path);
        $bytes = (int) filesize($real);
        $mtime = (int) filemtime($real);
        if ($bytes <= self::MIN_BACKUP_BYTES) {
            return "backup file is too small ({$bytes} bytes): {$real}";
        }
        if ($mtime < time() - self::MAX_BACKUP_AGE_SECONDS) {
            return 'backup file is older than 24 h (modified '.date('Y-m-d H:i:s', $mtime).'): take a fresh dump.';
        }

        $h = @gzopen($real, 'rb');
        if ($h === false) {
            return "backup file cannot be opened: {$real}";
        }
        try {
            $first = (string) gzgets($h, 4096);
            if (! str_starts_with($first, '-- MySQL dump') && ! str_starts_with($first, '-- MariaDB dump')) {
                return "backup file is not a mysqldump (no \"-- MySQL dump\" / \"-- MariaDB dump\" header): {$real}";
            }
            $missing = array_fill_keys($tables, true);
            while ($missing !== [] && ($line = gzgets($h, 65536)) !== false) {
                if (! str_starts_with($line, 'CREATE TABLE `')) {
                    continue;
                }
                foreach (array_keys($missing) as $t) {
                    if (str_starts_with($line, "CREATE TABLE `{$t}`")) {
                        unset($missing[$t]);
                    }
                }
            }
        } finally {
            gzclose($h);
        }
        if ($missing !== []) {
            return 'backup file lacks CREATE TABLE for: '.implode(', ', array_keys($missing));
        }

        return ['path' => $real, 'bytes' => $bytes, 'mtime' => date(DATE_ATOM, $mtime)];
    }

    /** @param array<string, mixed> $meta */
    private function refuse(string $message, array $meta): int
    {
        AdsAudit::record('ads.history_prune_refused', meta: $meta + ['reason' => $message, 'force' => (bool) $this->option('force')]);
        $this->error($message);
        $this->line('Nothing deleted.');

        return self::FAILURE;
    }

    /** @param array<string, array{status: string, delete: int, keep: int, min: ?string, max: ?string}> $preview */
    private function printPreview(array $preview, CarbonImmutable $before): void
    {
        $this->info('Ads history prune, before '.$before->toDateString().' (history start '.HistoryWindow::start()->toDateString().')');
        $rows = [];
        foreach ($preview as $table => $r) {
            $rows[] = $r['status'] === 'absent'
                ? [$table, 'absent', '-', '-', '-', '-', '-']
                : [$table, 'present', HistoryPruner::predicate($table, $before), $r['delete'], $r['min'] ?? '-', $r['max'] ?? '-', $r['keep']];
        }
        $this->table(['Table', 'Status', 'Predicate', 'To delete', 'Min', 'Max', 'Kept'], $rows);
        $this->line('Kept (never pruned): '.implode(', ', HistoryPruner::KEPT).', and every other non-ads table.');
    }
}
