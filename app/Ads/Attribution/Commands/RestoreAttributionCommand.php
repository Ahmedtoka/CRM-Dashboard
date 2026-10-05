<?php

namespace App\Ads\Attribution\Commands;

use App\Ads\Attribution\AttributionBackup;
use App\Ads\Audit\AdsAudit;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Puts orders.ad_id / ad_campaign_id / ad_attribution back from an orders_ad_attr_backup_* table (A4, R-19).
 * Dry run by default (lists what differs); --force updates only those three columns, by id, and touches no other
 * column (not even updated_at). Orders deleted since the backup are skipped.
 */
class RestoreAttributionCommand extends Command
{
    protected $signature = 'ads:attribution-restore {table : An orders_ad_attr_backup_YmdHis table} {--force : Write the restore (default: dry run)}';

    protected $description = 'Restore order ad attribution from a backup taken by ads:attribute-orders --force';

    public function handle(): int
    {
        $table = (string) $this->argument('table');
        if (! AttributionBackup::isBackupTable($table)) {
            $this->error("{$table} is not an existing orders_ad_attr_backup_* table.");

            return self::FAILURE;
        }

        $force = (bool) $this->option('force');
        $differ = 0;
        $samples = [];

        DB::table($table)->orderBy('id')->chunk(1000, function ($backup) use (&$differ, &$samples, $force) {
            $current = DB::table('orders')->whereIn('id', $backup->pluck('id'))->get(['id', ...AttributionBackup::COLUMNS])->keyBy('id');
            foreach ($backup as $b) {
                $o = $current[$b->id] ?? null;
                if ($o === null) {
                    continue;
                }
                $want = $this->values($b);
                if ($this->values($o) === $want) {
                    continue;
                }
                $differ++;
                if (count($samples) < 20) {
                    $samples[] = [$b->id, $this->show($o), $this->show($b)];
                }
                if ($force) {
                    DB::table('orders')->where('id', $b->id)->update($want);
                }
            }
        });

        if ($samples !== []) {
            $this->table(['order', 'now', 'backup'], $samples);
        }

        if (! $force) {
            AdsAudit::record('orders.attribution_restore_previewed', meta: ['table' => $table, 'differ' => $differ]);
            $this->info("Dry run: {$differ} order(s) differ from {$table}. Run again with --force to restore them.");

            return self::SUCCESS;
        }

        AdsAudit::record('orders.attribution_restored', meta: ['table' => $table, 'restored' => $differ]);
        $this->info("Restored {$differ} order(s) from {$table}.");

        return self::SUCCESS;
    }

    /** @return array{ad_id:?int, ad_campaign_id:?int, ad_attribution:?string} */
    private function values(object $r): array
    {
        return [
            'ad_id' => $r->ad_id !== null ? (int) $r->ad_id : null,
            'ad_campaign_id' => $r->ad_campaign_id !== null ? (int) $r->ad_campaign_id : null,
            'ad_attribution' => $r->ad_attribution !== null ? (string) $r->ad_attribution : null,
        ];
    }

    private function show(object $r): string
    {
        $v = $this->values($r);

        return ($v['ad_attribution'] ?? '-').' ad='.($v['ad_id'] ?? '-').' campaign='.($v['ad_campaign_id'] ?? '-');
    }
}
