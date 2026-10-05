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
 * In production --force also needs --backup=<mysqldump file> (exists, readable, > 1 KB). Never scheduled.
 * Every run (preview, prune, refusal) writes one ads_audit_log row.
 */
class PruneHistoryCommand extends Command
{
    protected $signature = 'ads:prune-history
        {--before= : Delete rows before this Y-m-d day (default and maximum: crm.ads.history_start)}
        {--force : Actually delete (without it this is a dry run)}
        {--backup= : Path of the backup file taken just before (required with --force in production)}
        {--chunk=5000 : Rows deleted per transaction}';

    protected $description = 'Delete ads-only rows dated before the history start (dry run unless --force)';

    private const MIN_BACKUP_BYTES = 1024;

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

        $backup = null;
        $backupPath = $this->option('backup');
        if ($backupPath !== null && $backupPath !== '') {
            $backup = $this->checkBackup((string) $backupPath);
            if ($backup === null) {
                return $this->refuse('backup file missing, unreadable or smaller than 1 KB: '.$backupPath, ['before' => $raw, 'backup_path' => $backupPath]);
            }
        } elseif ($force && app()->environment('production')) {
            return $this->refuse('backup file missing: in production --force requires --backup=<path of the dump taken just before>.', ['before' => $raw]);
        }

        $day = $before->toDateString();
        $preview = $pruner->preview($before);
        $this->printPreview($preview, $before);

        if (! $force) {
            AdsAudit::record('ads.history_prune_previewed', meta: [
                'before' => $day,
                'counts' => array_map(fn (array $r) => $r['delete'], array_filter($preview, fn (array $r) => $r['status'] === 'present')),
                'backup' => $backup,
            ]);
            $this->newLine();
            $this->info('Dry run: nothing deleted. Re-run with --force'.(app()->environment('production') ? ' --backup=<path>' : '').' to delete.');

            return self::SUCCESS;
        }

        $this->newLine();
        $this->warn("Deleting ads rows before {$day} in chunks of {$chunk}...");
        $done = [];
        try {
            $counts = $pruner->prune($before, $chunk, function (string $table, int $n, int $total) use (&$done) {
                $done[$table] = $total;
                $this->line("  {$table}: deleted {$n} (total {$total})");
            });
        } catch (Throwable $e) {
            AdsAudit::record('ads.history_pruned', meta: ['before' => $day, 'counts' => $done, 'backup' => $backup, 'error' => mb_substr($e->getMessage(), 0, 500)]);
            $this->error('Prune stopped with an error after deleting: '.json_encode($done).'. Re-run to continue.');

            throw $e;
        }

        AdsAudit::record('ads.history_pruned', meta: ['before' => $day, 'counts' => $counts, 'backup' => $backup]);

        foreach ($counts as $table => $n) {
            $this->line("{$table}: {$n} rows deleted");
        }
        $this->info('Done. '.array_sum($counts).' rows deleted.');

        return self::SUCCESS;
    }

    private function parseDay(string $raw): ?CarbonImmutable
    {
        if (! preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $raw, $m) || ! checkdate((int) $m[2], (int) $m[3], (int) $m[1])) {
            return null;
        }

        return CarbonImmutable::parse($raw, HistoryWindow::TIMEZONE)->startOfDay();
    }

    /** @return array{path: string, bytes: int}|null */
    private function checkBackup(string $path): ?array
    {
        clearstatcache(true, $path);
        if (! is_file($path) || ! is_readable($path)) {
            return null;
        }
        $bytes = (int) filesize($path);

        return $bytes > self::MIN_BACKUP_BYTES ? ['path' => $path, 'bytes' => $bytes] : null;
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
