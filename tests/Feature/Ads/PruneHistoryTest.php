<?php

use App\Ads\Audit\AdsAudit;
use App\Ads\Sync\HistoryPruner;
use App\Models\ActivityLog;
use App\Models\Ad;
use App\Models\AdAccount;
use App\Models\AdAction;
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

const PRUNED_TABLES = ['ad_daily_metrics', 'ad_account_daily', 'ads_sync_runs', 'ads_api_usage'];

beforeEach(function () {
    config(['crm.ads.history_start' => '2026-09-01']);
});

/** @return array{0: int, 1: string} */
function prune(array $args = []): array
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
function seedPruneFixture(): array
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
    AdAction::create(['ad_account_id' => $account->id, 'platform' => 'meta', 'level' => 'ad', 'external_id' => '1', 'to_status' => 'PAUSED', 'result' => 'ok', 'created_at' => '2026-08-10 09:00:00']);
    AdPublication::create([
        'ad_account_id' => $account->id, 'platform' => 'meta', 'campaign_external_id' => 'c1', 'adset_external_id' => 's1',
        'headline' => 'H', 'primary_text' => 'T', 'cta' => 'SHOP_NOW', 'ad_name' => 'A', 'link' => 'https://x.test', 'url_tags' => '',
        'status' => 'done', 'created_at' => '2026-08-10 09:00:00',
    ]);
    AdsAudit::record('seed.before_prune', meta: ['at' => '2026-08-01']);

    return compact('account', 'augustAd', 'septAd', 'order', 'oldRun', 'straddle', 'septRun', 'running');
}

/** Row count of every table except the pruned ones (and the audit log, which grows by one per run). */
function protectedCounts(): array
{
    $out = [];
    foreach (Schema::getTableListing() as $t) {
        $t = str_contains($t, '.') ? substr($t, strrpos($t, '.') + 1) : $t;
        if (in_array($t, PRUNED_TABLES, true) || $t === 'ads_audit_log' || $t === 'migrations') {
            continue;
        }
        $out[$t] = DB::table($t)->count();
    }
    ksort($out);

    return $out;
}

it('dry run counts the pre-start rows, deletes nothing and records one preview audit row', function () {
    $f = seedPruneFixture();
    $before = protectedCounts();

    [$code, $out] = prune();

    expect($code)->toBe(0)
        ->and($out)->toContain('Dry run: nothing deleted')
        ->and($out)->toContain('ad_daily_metrics')->and($out)->toContain('2026-08-15')->and($out)->toContain('2026-08-31')
        ->and($out)->toContain('ads_audit_log'); // listed as kept
    expect(AdDailyMetric::count())->toBe(4)->and(AdsSyncRun::count())->toBe(4)
        ->and(protectedCounts())->toBe($before);

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
    $f = seedPruneFixture();
    AdsApiUsage::create(['header' => 'x-ad-account-usage', 'recorded_at' => '2026-08-20 10:00:00']);
    AdsApiUsage::create(['header' => 'x-ad-account-usage', 'recorded_at' => '2026-09-02 10:00:00']);
    $before = protectedCounts();
    $auditBefore = AdsAuditLog::count();
    // the snapshot really covers the protected tables (guards against an empty table listing)
    expect($before)->toHaveKeys(['ads', 'ad_accounts', 'ad_actions', 'ad_publications', 'orders', 'customers', 'conversations', 'activity_logs'])
        ->and($before['ads'])->toBe(2)->and($before['orders'])->toBe(1)->and($before['ad_actions'])->toBe(1);

    [$code, $out] = prune(['--force' => true]);

    expect($code)->toBe(0);
    // boundary: 08-31 deleted, 09-01 kept
    expect(AdDailyMetric::orderBy('date')->pluck('date')->map(fn ($d) => substr((string) $d, 0, 10))->all())->toBe(['2026-09-01', '2026-09-20']);
    expect(AdsSyncRun::pluck('id')->sort()->values()->all())
        ->toBe(collect([$f['straddle']->id, $f['septRun']->id, $f['running']->id])->sort()->values()->all());
    expect(AdsApiUsage::count())->toBe(1);

    expect(protectedCounts())->toBe($before)
        ->and($f['order']->fresh()->ad_id)->toBe($f['augustAd']->id)
        ->and(Ad::find($f['augustAd']->id))->not->toBeNull()
        ->and(AdsAuditLog::count())->toBe($auditBefore + 1)
        ->and(AdsAuditLog::where('action', 'seed.before_prune')->exists())->toBeTrue();

    $audit = AdsAuditLog::where('action', 'ads.history_pruned')->sole();
    expect($audit->meta['counts'])->toMatchArray(['ad_daily_metrics' => 2, 'ads_sync_runs' => 1, 'ads_api_usage' => 1])
        ->and($audit->meta['backup'])->toBeNull();
});

it('a second --force run is a no-op', function () {
    seedPruneFixture();
    prune(['--force' => true]);
    $metrics = AdDailyMetric::count();
    $runs = AdsSyncRun::count();

    [$code] = prune(['--force' => true]);

    expect($code)->toBe(0)->and(AdDailyMetric::count())->toBe($metrics)->and(AdsSyncRun::count())->toBe($runs);
    $last = AdsAuditLog::where('action', 'ads.history_pruned')->latest('id')->first();
    expect(array_sum($last->meta['counts']))->toBe(0);
});

it('refuses a --before later than the history start', function () {
    seedPruneFixture();

    [$code, $out] = prune(['--before' => '2026-09-10', '--force' => true]);

    expect($code)->toBe(1)->and($out)->toContain('later than history start')
        ->and(AdDailyMetric::count())->toBe(4)->and(AdsSyncRun::count())->toBe(4);
});

it('refuses an invalid --before date', function () {
    seedPruneFixture();

    [$code] = prune(['--before' => '2026-02-30', '--force' => true]);

    expect($code)->toBe(1)->and(AdDailyMetric::count())->toBe(4);
});

it('accepts an earlier --before and prunes only before it', function () {
    seedPruneFixture();

    [$code] = prune(['--before' => '2026-08-20', '--force' => true]);

    expect($code)->toBe(0)->and(AdDailyMetric::count())->toBe(3)
        ->and(AdsSyncRun::count())->toBe(4); // the 08-01..08-30 run ends after 08-20
});

it('in production refuses --force without a valid backup and deletes nothing', function () {
    seedPruneFixture();
    app()->detectEnvironment(fn () => 'production');

    [$code, $out] = prune(['--force' => true]);
    expect($code)->toBe(1)->and($out)->toContain('backup file missing')->and(AdDailyMetric::count())->toBe(4);

    $tiny = tempnam(sys_get_temp_dir(), 'adsbk');
    file_put_contents($tiny, 'x');
    [$code] = prune(['--force' => true, '--backup' => $tiny]);
    expect($code)->toBe(1)->and(AdDailyMetric::count())->toBe(4);

    [$code] = prune(['--force' => true, '--backup' => $tiny.'-does-not-exist']);
    expect($code)->toBe(1)->and(AdDailyMetric::count())->toBe(4)->and(AdsSyncRun::count())->toBe(4);
    expect(AdsAuditLog::where('action', 'ads.history_pruned')->exists())->toBeFalse()
        ->and(AdsAuditLog::where('action', 'ads.history_prune_refused')->count())->toBe(3);
    @unlink($tiny);
});

it('in production deletes with a valid backup and records its path and size', function () {
    seedPruneFixture();
    app()->detectEnvironment(fn () => 'production');
    $file = tempnam(sys_get_temp_dir(), 'adsbk');
    file_put_contents($file, str_repeat('-- dump line', 200)); // 2400 bytes

    [$code] = prune(['--force' => true, '--backup' => $file]);

    expect($code)->toBe(0)->and(AdDailyMetric::count())->toBe(2);
    $audit = AdsAuditLog::where('action', 'ads.history_pruned')->sole();
    expect($audit->meta['backup'])->toBe(['path' => $file, 'bytes' => 2400]);
    @unlink($file);
});

it('deletes in chunks of --chunk ids', function () {
    seedPruneFixture();
    $progress = [];

    $counts = app(HistoryPruner::class)->prune(CarbonImmutable::parse('2026-09-01'), 1, function (string $table, int $n) use (&$progress) {
        $progress[] = [$table, $n];
    });

    expect($counts['ad_daily_metrics'])->toBe(2)->and(AdDailyMetric::count())->toBe(2)
        ->and(collect($progress)->where(0, 'ad_daily_metrics')->count())->toBe(2);

    [$code] = prune(['--force' => true, '--chunk' => 1]);
    expect($code)->toBe(0);
});

it('reports ad_account_daily as absent when the table does not exist', function () {
    Schema::dropIfExists('ad_account_daily');
    seedPruneFixture();

    [$code, $out] = prune(['--force' => true]);

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

    [$code] = prune(['--force' => true]);

    expect($code)->toBe(0)->and(DB::table('ad_account_daily')->pluck('date')->map(fn ($d) => substr((string) $d, 0, 10))->all())->toBe(['2026-09-01']);
});
