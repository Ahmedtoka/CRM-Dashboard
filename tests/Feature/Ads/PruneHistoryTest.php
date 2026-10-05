<?php

use App\Ads\Audit\AdsAudit;
use App\Ads\Sync\HistoryPruner;
use App\Models\ActivityLog;
use App\Models\Ad;
use App\Models\AdAccount;
use App\Models\AdDailyMetric;
use App\Models\AdPublication;
use App\Models\AdsApiUsage;
use App\Models\AdsAuditLog;
use App\Models\AdsSyncRun;
use App\Models\Conversation;
use App\Models\Customer;
use App\Models\Order;
use Carbon\CarbonImmutable;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

const PRUNE_HISTORY_PRUNED_TABLES = ['ad_daily_metrics', 'ad_account_daily', 'ads_sync_runs', 'ads_api_usage'];

/** First line written by recent MariaDB mysqldump, before the "-- MariaDB dump" comment. */
const PRUNE_HISTORY_SANDBOX = '/*M!999999\- enable the sandbox mode */';

beforeEach(function () {
    config(['crm.ads.history_start' => '2026-09-01']);
});

/** @return array{0: int, 1: string} */
function pruneHistoryRun(array $args = []): array
{
    $code = Artisan::call('ads:prune-history', $args);

    return [$code, Artisan::output()];
}

/**
 * Seeds the brief's fixture: metrics on 08-15, 08-31, 09-01, 09-20 (August ones on an ad with only August metrics,
 * which an order points to); runs 08-01..08-30 ok, 08-28..09-03 straddling, 09-01..09-03, a running one ending 08-10;
 * plus rows in protected tables dated in August.
 *
 * @return array<string, mixed>
 */
function pruneHistorySeed(): array
{
    $account = AdAccount::factory()->create();
    $augustAd = Ad::factory()->create(['ad_account_id' => $account->id]);
    $septAd = Ad::factory()->create(['ad_account_id' => $account->id]);

    foreach (['2026-08-15', '2026-08-31'] as $d) {
        AdDailyMetric::factory()->create(['ad_id' => $augustAd->id, 'ad_account_id' => $account->id, 'date' => $d]);
    }
    foreach (['2026-09-01', '2026-09-20'] as $d) {
        AdDailyMetric::factory()->create(['ad_id' => $septAd->id, 'ad_account_id' => $account->id, 'date' => $d]);
    }

    $run = fn (string $from, string $to, string $status = 'ok') => AdsSyncRun::factory()->create([
        'ad_account_id' => $account->id, 'from_date' => $from, 'to_date' => $to, 'status' => $status,
        'started_at' => '2026-08-31 10:00:00', 'finished_at' => '2026-08-31 10:05:00',
    ]);
    $oldRun = $run('2026-08-01', '2026-08-30');
    $straddle = $run('2026-08-28', '2026-09-03');
    $septRun = $run('2026-09-01', '2026-09-03');
    $running = $run('2026-08-01', '2026-08-10', 'running');

    $customer = Customer::factory()->create();
    $order = Order::factory()->create(['customer_id' => $customer->id, 'ad_id' => $augustAd->id, 'created_at' => '2026-08-15 12:00:00']);
    Conversation::factory()->create(['created_at' => '2026-08-15 12:00:00']);
    ActivityLog::factory()->create(['created_at' => '2026-08-15 12:00:00']);
    DB::table('ad_actions')->insert(['ad_account_id' => $account->id, 'platform' => 'meta', 'level' => 'ad', 'external_id' => '1', 'to_status' => 'PAUSED', 'result' => 'ok', 'created_at' => '2026-08-10 09:00:00', 'updated_at' => '2026-08-10 09:00:00']); // frozen model
    AdPublication::create([
        'ad_account_id' => $account->id, 'platform' => 'meta', 'campaign_external_id' => 'c1', 'adset_external_id' => 's1',
        'headline' => 'H', 'primary_text' => 'T', 'cta' => 'SHOP_NOW', 'ad_name' => 'A', 'link' => 'https://x.test', 'url_tags' => '',
        'status' => 'done', 'created_at' => '2026-08-10 09:00:00',
    ]);
    AdsAudit::record('seed.before_prune', meta: ['at' => '2026-08-01']);

    return compact('account', 'augustAd', 'septAd', 'order', 'oldRun', 'straddle', 'septRun', 'running');
}

/** Row count of every table except the pruned ones (and the audit log, which grows by one per run). */
function pruneProtectedCounts(): array
{
    $out = [];
    foreach (Schema::getTableListing() as $t) {
        $t = str_contains($t, '.') ? substr($t, strrpos($t, '.') + 1) : $t;
        if (in_array($t, PRUNE_HISTORY_PRUNED_TABLES, true) || $t === 'ads_audit_log' || $t === 'migrations') {
            continue;
        }
        $out[$t] = DB::table($t)->count();
    }
    ksort($out);

    return $out;
}

it('dry run counts the pre-start rows, deletes nothing and records one preview audit row', function () {
    $f = pruneHistorySeed();
    $before = pruneProtectedCounts();

    [$code, $out] = pruneHistoryRun();

    expect($code)->toBe(0)
        ->and($out)->toContain('Dry run: nothing deleted')
        ->and($out)->toContain('ad_daily_metrics')->and($out)->toContain('2026-08-15')->and($out)->toContain('2026-08-31')
        ->and($out)->toContain('ads_audit_log'); // listed as kept
    expect(AdDailyMetric::count())->toBe(4)->and(AdsSyncRun::count())->toBe(4)
        ->and(pruneProtectedCounts())->toBe($before);

    $preview = app(HistoryPruner::class)->preview(CarbonImmutable::parse('2026-09-01'));
    expect($preview['ad_daily_metrics'])->toMatchArray(['status' => 'present', 'delete' => 2, 'keep' => 2, 'min' => '2026-08-15', 'max' => '2026-08-31'])
        ->and($preview['ads_sync_runs'])->toMatchArray(['status' => 'present', 'delete' => 1, 'keep' => 3]);

    $audit = AdsAuditLog::where('action', 'ads.history_prune_previewed')->sole();
    expect($audit->actor_type)->toBe('cli')
        ->and($audit->meta['before'])->toBe('2026-09-01')
        ->and($audit->meta['counts']['ad_daily_metrics'])->toBe(2)
        ->and($audit->meta['counts']['ads_sync_runs'])->toBe(1)
        ->and($audit->meta['backup'])->toBeNull();
});

it('--force deletes exactly the pre-start rows of the listed tables and nothing else', function () {
    $f = pruneHistorySeed();
    AdsApiUsage::create(['header' => 'x-ad-account-usage', 'recorded_at' => '2026-08-20 10:00:00']);
    AdsApiUsage::create(['header' => 'x-ad-account-usage', 'recorded_at' => '2026-09-02 10:00:00']);
    $before = pruneProtectedCounts();
    $auditBefore = AdsAuditLog::count();
    // the snapshot really covers the protected tables (guards against an empty table listing)
    expect($before)->toHaveKeys(['ads', 'ad_accounts', 'ad_actions', 'ad_publications', 'orders', 'customers', 'conversations', 'activity_logs'])
        ->and($before['ads'])->toBe(2)->and($before['orders'])->toBe(1)->and($before['ad_actions'])->toBe(1);

    [$code, $out] = pruneHistoryRun(['--force' => true]);

    expect($code)->toBe(0);
    // boundary: 08-31 deleted, 09-01 kept
    expect(AdDailyMetric::orderBy('date')->pluck('date')->map(fn ($d) => substr((string) $d, 0, 10))->all())->toBe(['2026-09-01', '2026-09-20']);
    expect(AdsSyncRun::pluck('id')->sort()->values()->all())
        ->toBe(collect([$f['straddle']->id, $f['septRun']->id, $f['running']->id])->sort()->values()->all());
    expect(AdsApiUsage::count())->toBe(1);

    expect(pruneProtectedCounts())->toBe($before)
        ->and($f['order']->fresh()->ad_id)->toBe($f['augustAd']->id)
        ->and(Ad::find($f['augustAd']->id))->not->toBeNull()
        ->and(AdsAuditLog::count())->toBe($auditBefore + 2) // started + pruned
        ->and(AdsAuditLog::where('action', 'seed.before_prune')->exists())->toBeTrue();

    $audit = AdsAuditLog::where('action', 'ads.history_pruned')->sole();
    expect($audit->meta['counts'])->toMatchArray(['ad_daily_metrics' => 2, 'ads_sync_runs' => 1, 'ads_api_usage' => 1])
        ->and($audit->meta['backup'])->toBeNull();
});

it('a second --force run is a no-op', function () {
    pruneHistorySeed();
    pruneHistoryRun(['--force' => true]);
    $metrics = AdDailyMetric::count();
    $runs = AdsSyncRun::count();

    [$code] = pruneHistoryRun(['--force' => true]);

    expect($code)->toBe(0)->and(AdDailyMetric::count())->toBe($metrics)->and(AdsSyncRun::count())->toBe($runs);
    $last = AdsAuditLog::where('action', 'ads.history_pruned')->latest('id')->first();
    expect(array_sum($last->meta['counts']))->toBe(0);
});

it('refuses a --before later than the history start', function () {
    pruneHistorySeed();

    [$code, $out] = pruneHistoryRun(['--before' => '2026-09-10', '--force' => true]);

    expect($code)->toBe(1)->and($out)->toContain('later than history start')
        ->and(AdDailyMetric::count())->toBe(4)->and(AdsSyncRun::count())->toBe(4);
});

it('refuses an invalid --before date', function () {
    pruneHistorySeed();

    [$code] = pruneHistoryRun(['--before' => '2026-02-30', '--force' => true]);

    expect($code)->toBe(1)->and(AdDailyMetric::count())->toBe(4);
});

it('accepts an earlier --before and prunes only before it', function () {
    pruneHistorySeed();

    [$code] = pruneHistoryRun(['--before' => '2026-08-20', '--force' => true]);

    expect($code)->toBe(0)->and(AdDailyMetric::count())->toBe(3)
        ->and(AdsSyncRun::count())->toBe(4); // the 08-01..08-30 run ends after 08-20
});

/**
 * Writes a fake mysqldump to a temp file (deleted after the test). $tables null = every pruned table that exists.
 *
 * @param  list<string>|null  $tables
 */
function pruneHistoryDump(?array $tables = null, string $header = '-- MySQL dump 10.13  Distrib 8.0.36, for Linux (x86_64)', bool $gzip = false, int $ageSeconds = 0): string
{
    $tables ??= array_values(array_filter(PRUNE_HISTORY_PRUNED_TABLES, fn ($t) => Schema::hasTable($t)));
    $sql = $header."\n--\n-- Host: localhost    Database: crm\n";
    foreach (array_merge($tables, ['ads', 'ad_accounts']) as $t) {
        $sql .= "DROP TABLE IF EXISTS `{$t}`;\nCREATE TABLE `{$t}` (\n  `id` bigint unsigned NOT NULL AUTO_INCREMENT,\n  PRIMARY KEY (`id`)\n);\n";
        $sql .= "INSERT INTO `{$t}` VALUES ".implode(',', array_map(fn ($i) => "({$i})", range(1, 60))).";\n";
    }
    $sql .= '-- padding '.bin2hex(random_bytes(1024))."\n"; // keeps the gzip variant above 1 KB
    $sql .= "-- Dump completed on 2026-10-05 10:00:00\n";
    $file = tempnam(sys_get_temp_dir(), 'adsbk');
    file_put_contents($file, $gzip ? gzencode($sql) : $sql);
    if ($ageSeconds > 0) {
        touch($file, time() - $ageSeconds);
    }
    $GLOBALS['pruneHistoryFiles'][] = $file;

    return $file;
}

afterEach(function () {
    foreach ($GLOBALS['pruneHistoryFiles'] ?? [] as $f) {
        @unlink($f);
    }
    $GLOBALS['pruneHistoryFiles'] = [];
});

it('in production refuses --force without a valid backup and deletes nothing', function () {
    pruneHistorySeed();
    app()->detectEnvironment(fn () => 'production');

    [$code, $out] = pruneHistoryRun(['--force' => true]);
    expect($code)->toBe(1)->and($out)->toContain('backup file missing')->and(AdDailyMetric::count())->toBe(4);

    $tiny = tempnam(sys_get_temp_dir(), 'adsbk');
    $GLOBALS['pruneHistoryFiles'][] = $tiny;
    file_put_contents($tiny, 'x');
    [$code] = pruneHistoryRun(['--force' => true, '--backup' => $tiny]);
    expect($code)->toBe(1)->and(AdDailyMetric::count())->toBe(4);

    [$code] = pruneHistoryRun(['--force' => true, '--backup' => $tiny.'-does-not-exist']);
    expect($code)->toBe(1)->and(AdDailyMetric::count())->toBe(4)->and(AdsSyncRun::count())->toBe(4);
    expect(AdsAuditLog::where('action', 'ads.history_pruned')->exists())->toBeFalse()
        ->and(AdsAuditLog::where('action', 'ads.history_prune_started')->exists())->toBeFalse()
        ->and(AdsAuditLog::where('action', 'ads.history_prune_refused')->count())->toBe(3);
});

it('requires a backup with --force on staging too', function () {
    pruneHistorySeed();
    app()->detectEnvironment(fn () => 'staging');

    [$code, $out] = pruneHistoryRun(['--force' => true]);

    expect($code)->toBe(1)->and($out)->toContain('backup file missing')->and(AdDailyMetric::count())->toBe(4);
});

it('refuses a backup that is not a recent ads dump', function (string $kind) {
    pruneHistorySeed();
    app()->detectEnvironment(fn () => 'production');

    $file = match ($kind) {
        'log file' => (function () {
            $f = tempnam(sys_get_temp_dir(), 'adsbk');
            $GLOBALS['pruneHistoryFiles'][] = $f;
            file_put_contents($f, str_repeat("[2026-10-05 10:00:00] production.INFO: CREATE TABLE `ad_daily_metrics` mentioned\n", 40));

            return $f;
        })(),
        'stale dump' => pruneHistoryDump(ageSeconds: 25 * 3600),
        'dump missing a table' => pruneHistoryDump(['ad_daily_metrics', 'ads_api_usage']),
        'gzip without header' => pruneHistoryDump(header: '-- not a dump', gzip: true),
        'future-dated dump' => (function () {
            $f = pruneHistoryDump();
            touch($f, time() + 3600);

            return $f;
        })(),
        'header after line 5' => pruneHistoryDump(header: PRUNE_HISTORY_SANDBOX.str_repeat("\n", 5).'-- MariaDB dump 10.19'),
    };

    [$code, $out] = pruneHistoryRun(['--force' => true, '--backup' => $file]);

    expect($code)->toBe(1)->and($out)->toContain('backup file')
        ->and(AdDailyMetric::count())->toBe(4)->and(AdsSyncRun::count())->toBe(4)
        ->and(AdsAuditLog::where('action', 'ads.history_prune_refused')->count())->toBe(1);
    if ($kind === 'dump missing a table') {
        expect($out)->toContain('ads_sync_runs');
    }
})->with(['log file', 'stale dump', 'dump missing a table', 'gzip without header', 'future-dated dump', 'header after line 5']);

it('in production deletes with a valid dump and records realpath, size and mtime', function (bool $gzip, string $header) {
    pruneHistorySeed();
    app()->detectEnvironment(fn () => 'production');
    $file = pruneHistoryDump(header: $header, gzip: $gzip);

    [$code] = pruneHistoryRun(['--force' => true, '--backup' => $file]);

    expect($code)->toBe(0)->and(AdDailyMetric::count())->toBe(2);
    $audit = AdsAuditLog::where('action', 'ads.history_pruned')->sole();
    clearstatcache();
    expect($audit->meta['backup']['path'])->toBe(realpath($file))
        ->and($audit->meta['backup']['bytes'])->toBe(filesize($file))
        ->and($audit->meta['backup']['mtime'])->toBe(date(DATE_ATOM, filemtime($file)));
})->with([
    'plain mysql' => [false, '-- MySQL dump 10.13  Distrib 8.0.36, for Linux (x86_64)'],
    'gzip mariadb' => [true, '-- MariaDB dump 10.19  Distrib 10.6.16-MariaDB, for Linux (x86_64)'],
    'mariadb sandbox line first' => [false, PRUNE_HISTORY_SANDBOX."\n".'-- MariaDB dump 10.19-11.4.5-MariaDB, for Linux (x86_64)'],
    'gzip mariadb sandbox line first' => [true, PRUNE_HISTORY_SANDBOX."\n".'-- MariaDB dump 10.19-11.4.5-MariaDB, for Linux (x86_64)'],
]);

it('writes a started audit row before the first delete', function () {
    pruneHistorySeed();
    $file = pruneHistoryDump();

    [$code] = pruneHistoryRun(['--force' => true, '--backup' => $file]);

    expect($code)->toBe(0);
    $started = AdsAuditLog::where('action', 'ads.history_prune_started')->sole();
    $pruned = AdsAuditLog::where('action', 'ads.history_pruned')->sole();
    expect($started->id)->toBeLessThan($pruned->id)
        ->and($started->meta['before'])->toBe('2026-09-01')
        ->and($started->meta['counts'])->toMatchArray(['ad_daily_metrics' => 2, 'ads_sync_runs' => 1])
        ->and($started->meta['backup']['path'])->toBe(realpath($file));
});

it('stops cleanly after the current chunk when asked to stop', function () {
    pruneHistorySeed();
    $chunks = 0;

    $counts = app(HistoryPruner::class)->prune(
        CarbonImmutable::parse('2026-09-01'), 1,
        function () use (&$chunks) {
            $chunks++;
        },
        function () use (&$chunks) {
            return $chunks >= 1;
        },
    );

    expect($counts)->toBe(['ad_daily_metrics' => 1])->and(AdDailyMetric::count())->toBe(3);
});

it('deletes in chunks of --chunk ids', function () {
    pruneHistorySeed();
    $progress = [];

    $counts = app(HistoryPruner::class)->prune(CarbonImmutable::parse('2026-09-01'), 1, function (string $table, int $n) use (&$progress) {
        $progress[] = [$table, $n];
    });

    expect($counts['ad_daily_metrics'])->toBe(2)->and(AdDailyMetric::count())->toBe(2)
        ->and(collect($progress)->where(0, 'ad_daily_metrics')->count())->toBe(2);

    [$code] = pruneHistoryRun(['--force' => true, '--chunk' => 1]);
    expect($code)->toBe(0);
});

it('reports ad_account_daily as absent when the table does not exist', function () {
    Schema::dropIfExists('ad_account_daily');
    pruneHistorySeed();

    [$code, $out] = pruneHistoryRun(['--force' => true]);

    expect($code)->toBe(0)->and($out)->toContain('absent')
        ->and(app(HistoryPruner::class)->preview(CarbonImmutable::parse('2026-09-01'))['ad_account_daily']['status'])->toBe('absent');
});

it('prunes ad_account_daily by date when the table exists', function () {
    if (! Schema::hasTable('ad_account_daily')) {
        Schema::create('ad_account_daily', function (Blueprint $t) {
            $t->id();
            $t->date('date');
        });
        DB::table('ad_account_daily')->insert([['date' => '2026-08-31'], ['date' => '2026-09-01']]);
    } else {
        $acc = AdAccount::factory()->create();
        foreach (['2026-08-31', '2026-09-01'] as $d) {
            DB::table('ad_account_daily')->insert(['ad_account_id' => $acc->id, 'date' => $d]);
        }
    }

    [$code] = pruneHistoryRun(['--force' => true]);

    expect($code)->toBe(0)->and(DB::table('ad_account_daily')->pluck('date')->map(fn ($d) => substr((string) $d, 0, 10))->all())->toBe(['2026-09-01']);
});
