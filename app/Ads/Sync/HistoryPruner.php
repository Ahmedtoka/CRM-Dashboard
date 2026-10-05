<?php

namespace App\Ads\Sync;

use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Deletes ads-only rows that describe days before the history start (owner decision D2).
 *
 * Only the tables in TABLES are ever touched, each by its own predicate; everything else (entities, orders,
 * conversations, the audit log, ...) is left alone. Deletes go by id chunks, one short transaction per chunk,
 * so MariaDB never holds a long lock. Re-running finds nothing and deletes nothing.
 */
final class HistoryPruner
{
    /**
     * table => [column, kind]. kind 'date' compares the account-timezone day; 'datetime' compares a UTC timestamp
     * against the start of the history day in Cairo. ads_sync_runs also requires status <> 'running', and a run is
     * pruned only when its whole window (to_date) is before the start: a run that straddles the start is kept.
     */
    public const TABLES = [
        'ad_daily_metrics' => ['column' => 'date', 'kind' => 'date'],
        'ad_account_daily' => ['column' => 'date', 'kind' => 'date'],
        'ads_sync_runs' => ['column' => 'to_date', 'kind' => 'date'],
        'ads_api_usage' => ['column' => 'recorded_at', 'kind' => 'datetime'],
    ];

    /** Printed as "kept": never pruned, whatever their dates. Any table not in TABLES is kept too. */
    public const KEPT = [
        'ads', 'ad_campaigns', 'ad_sets', 'ad_accounts', 'ad_platform_connections', 'ad_actions', 'ad_publications',
        'ad_materials', 'ad_material_files', 'ad_material_collections', 'ad_material_captions', 'ad_account_assignments',
        'media_buyers', 'buyer_targets', 'ads_settings', 'ads_audit_log',
        'orders', 'refunds', 'customers', 'conversations', 'activity_logs',
    ];

    /** Human-readable predicate for the command output. */
    public static function predicate(string $table, CarbonImmutable $before): string
    {
        $col = self::TABLES[$table]['column'];
        $bound = self::bound($table, $before);
        $sql = "{$col} < '{$bound}'";

        return $table === 'ads_sync_runs' ? $sql." AND status <> 'running'" : $sql;
    }

    /**
     * @return array<string, array{status: 'present'|'absent', delete: int, keep: int, min: ?string, max: ?string}>
     */
    public function preview(CarbonImmutable $before): array
    {
        $out = [];
        foreach (array_keys(self::TABLES) as $table) {
            if (! Schema::hasTable($table)) {
                $out[$table] = ['status' => 'absent', 'delete' => 0, 'keep' => 0, 'min' => null, 'max' => null];

                continue;
            }
            $col = self::TABLES[$table]['column'];
            $delete = $this->query($table, $before)->count();
            $total = DB::table($table)->count();
            $min = $delete > 0 ? $this->query($table, $before)->min($col) : null;
            $max = $delete > 0 ? $this->query($table, $before)->max($col) : null;

            $out[$table] = [
                'status' => 'present',
                'delete' => $delete,
                'keep' => $total - $delete,
                'min' => $min === null ? null : self::day($table, (string) $min),
                'max' => $max === null ? null : self::day($table, (string) $max),
            ];
        }

        return $out;
    }

    /**
     * @param  callable(string $table, int $deletedInChunk, int $deletedSoFar): void|null  $progress
     * @param  callable(): bool|null  $shouldStop  checked before every chunk; true stops cleanly (the finished chunks stay deleted)
     * @return array<string, int> rows deleted per present table
     */
    public function prune(CarbonImmutable $before, int $chunk, ?callable $progress = null, ?callable $shouldStop = null): array
    {
        $chunk = max(1, $chunk);
        $counts = [];

        foreach (array_keys(self::TABLES) as $table) {
            if (! Schema::hasTable($table)) {
                continue;
            }
            $counts[$table] = 0;
            $lastId = 0;

            while (true) {
                if ($shouldStop !== null && $shouldStop()) {
                    return $counts;
                }
                $ids = $this->query($table, $before)->where('id', '>', $lastId)->orderBy('id')->limit($chunk)->pluck('id')->all();
                if ($ids === []) {
                    break;
                }
                $lastId = (int) max($ids);

                // The predicate is applied again inside the delete: a row that changed since the select is left alone.
                $deleted = DB::transaction(fn () => $this->query($table, $before)->whereIn('id', $ids)->delete());

                $counts[$table] += $deleted;
                if ($progress !== null) {
                    $progress($table, $deleted, $counts[$table]);
                }
            }
        }

        return $counts;
    }

    private function query(string $table, CarbonImmutable $before): Builder
    {
        $q = DB::table($table)->where(self::TABLES[$table]['column'], '<', self::bound($table, $before));

        return $table === 'ads_sync_runs' ? $q->where('status', '<>', 'running') : $q;
    }

    private static function bound(string $table, CarbonImmutable $before): string
    {
        $day = CarbonImmutable::parse($before->toDateString(), HistoryWindow::TIMEZONE)->startOfDay();

        return self::TABLES[$table]['kind'] === 'date'
            ? $day->toDateString()
            : $day->setTimezone((string) config('app.timezone', 'UTC'))->format('Y-m-d H:i:s');
    }

    private static function day(string $table, string $value): string
    {
        return self::TABLES[$table]['kind'] === 'date' ? substr($value, 0, 10) : substr($value, 0, 19);
    }
}
