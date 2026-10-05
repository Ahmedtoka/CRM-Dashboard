<?php

namespace App\Ads\Attribution;

use Carbon\CarbonImmutable;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Snapshot of orders(id, ad_id, ad_campaign_id, ad_attribution) for a placed_at window, taken before a forced
 * re-attribution (A4, R-19) into orders_ad_attr_backup_{YmdHis}. Restored by `ads:attribution-restore`.
 * Reads orders only; writes only the new backup table.
 */
final class AttributionBackup
{
    public const PREFIX = 'orders_ad_attr_backup_';

    public const PATTERN = '/^orders_ad_attr_backup_\d{14}(_\d+)?$/';

    public const COLUMNS = ['ad_id', 'ad_campaign_id', 'ad_attribution'];

    /** @return array{table:string, rows:int} */
    public function create(CarbonImmutable $from, CarbonImmutable $to): array
    {
        $table = self::PREFIX.CarbonImmutable::now()->format('YmdHis');
        for ($i = 2; Schema::hasTable($table); $i++) {
            $table = self::PREFIX.CarbonImmutable::now()->format('YmdHis').'_'.$i;
        }

        $select = DB::table('orders')->whereBetween('placed_at', [$from, $to])->select(['id', ...self::COLUMNS]);

        if (in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true)) {
            DB::statement("CREATE TABLE `{$table}` AS ".$select->toRawSql());
        } else {
            Schema::create($table, function (Blueprint $t) {
                $t->unsignedBigInteger('id')->primary();
                $t->unsignedBigInteger('ad_id')->nullable();
                $t->unsignedBigInteger('ad_campaign_id')->nullable();
                $t->string('ad_attribution', 20)->nullable();
            });
            DB::table($table)->insertUsing(['id', ...self::COLUMNS], $select);
        }

        return ['table' => $table, 'rows' => DB::table($table)->count()];
    }

    public static function isBackupTable(string $table): bool
    {
        return preg_match(self::PATTERN, $table) === 1 && Schema::hasTable($table);
    }
}
