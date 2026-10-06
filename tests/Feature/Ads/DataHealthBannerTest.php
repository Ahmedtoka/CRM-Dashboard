<?php

use App\Ads\Buyers\AssignmentService;
use App\Ads\Health\DataHealth;
use App\Ads\Reports\AdsFilter;
use App\Enums\OrderSource;
use App\Enums\UserRole;
use App\Models\Ad;
use App\Models\AdAccount;
use App\Models\AdAccountDaily;
use App\Models\AdDailyMetric;
use App\Models\AdPlatformConnection;
use App\Models\AdsAuditLog;
use App\Models\AdsHealthState;
use App\Models\AdsSyncRun;
use App\Models\Conversation;
use App\Models\MediaBuyer;
use App\Models\Order;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia;

beforeEach(function () {
    Cache::flush();
    $this->withoutVite();
});

function dbAccount(string $name, int $okAgoMinutes = 10): AdAccount
{
    $c = AdPlatformConnection::factory()->meta()->create(['status' => 'connected']);
    $a = AdAccount::factory()->meta()->create(['connection_id' => $c->id, 'name' => $name, 'complete_from' => '2026-01-01']);
    AdsSyncRun::factory()->create(['ad_account_id' => $a->id, 'status' => 'ok', 'started_at' => now()->subMinutes($okAgoMinutes + 1), 'finished_at' => now()->subMinutes($okAgoMinutes)]);

    return $a;
}

function dbAdmin(): User
{
    return User::factory()->create(['role' => UserRole::Admin, 'is_active' => true]);
}

/** @return list<string> every Ads report page that must carry the banner props */
function dbPages(MediaBuyer $buyer): array
{
    return ['/ads', '/ads/numbers', "/ads/buyers/{$buyer->id}", '/ads/explorer', '/ads/explorer?view=tree', '/ads/explorer?view=cards', '/ads/decisions', '/ads/decisions?tab=log'];
}

it('puts the stale reason on every Ads report page and names the account', function () {
    $admin = dbAdmin();
    $buyer = MediaBuyer::factory()->create();
    $a = dbAccount('Stale Shop', 4 * 60);
    dbAccount('Fresh Shop', 5);

    foreach (dbPages($buyer) as $url) {
        Cache::flush();
        $this->actingAs($admin)->get($url)->assertOk()->assertInertia(fn (AssertableInertia $p) => $p
            ->where('data_health.reasons.0.reason', 'stale')
            ->where('data_health.reasons.0.accounts', ['Stale Shop'])
            ->where('data_health.reasons.0.more', 0)
            ->has('numbers_under_review')->has('clamped_to_history'));
    }
});

it('orders the reasons worst first and never names an account outside a buyer scope', function () {
    $user = User::factory()->create(['role' => UserRole::MediaBuyer]);
    $buyer = MediaBuyer::factory()->create(['user_id' => $user->id]);
    $own = dbAccount('Own Shop', 4 * 60);
    $other = dbAccount('Other Shop', 7 * 60);
    $other->connection->update(['status' => 'needs_reconnect']);
    app(AssignmentService::class)->assign($own, $buyer, CarbonImmutable::now('Africa/Cairo')->subDays(5));

    $this->actingAs($user)->get('/ads')->assertOk()->assertInertia(function (AssertableInertia $p) {
        $p->where('data_health.reasons.0.reason', 'stale')->where('data_health.reasons.0.accounts', ['Own Shop'])->missing('data_health.reasons.1');
    });

    Cache::flush();
    $this->actingAs(dbAdmin())->get('/ads')->assertInertia(fn (AssertableInertia $p) => $p
        ->where('data_health.reasons.0.reason', 'reconnect')->where('data_health.reasons.0.accounts', ['Other Shop'])
        ->where('data_health.reasons.1.reason', 'stale')->where('data_health.reasons.1.accounts', fn ($names) => collect($names)->sort()->values()->all() === ['Other Shop', 'Own Shop']));
});

it('names at most three accounts and counts the rest', function () {
    foreach (range(1, 5) as $i) {
        dbAccount("Shop {$i}", 4 * 60);
    }

    $this->actingAs(dbAdmin())->get('/ads')->assertInertia(fn (AssertableInertia $p) => $p
        ->where('data_health.reasons.0.reason', 'stale')->has('data_health.reasons.0.accounts', 3)->where('data_health.reasons.0.more', 2));
});

it('reports a read-only connection and a spend gap over the range', function () {
    $a = dbAccount('Gap Shop');
    $a->connection->forceFill(['read_only' => true])->save();
    $day = CarbonImmutable::now('Africa/Cairo')->subDays(2)->toDateString();
    AdAccountDaily::create(['ad_account_id' => $a->id, 'date' => $day, 'spend' => 1000, 'fetched_at' => now()]);
    $ad = Ad::factory()->for($a, 'account')->create();
    AdDailyMetric::factory()->create(['ad_id' => $ad->id, 'ad_account_id' => $a->id, 'date' => $day, 'spend' => 900]);

    $this->actingAs(dbAdmin())->get('/ads')->assertInertia(fn (AssertableInertia $p) => $p
        ->where('data_health.reasons.0.reason', 'read_only')->where('data_health.reasons.1.reason', 'gap')->where('data_health.reasons.1.accounts', ['Gap Shop']));
});

it('reports incomplete history when the range starts before the account complete_from', function () {
    $a = dbAccount('Partial Shop');
    DB::table('ad_accounts')->where('id', $a->id)->update(['complete_from' => '2026-09-20']);

    $this->actingAs(dbAdmin())->get('/ads?from=2026-09-05&to=2026-09-28')->assertInertia(fn (AssertableInertia $p) => $p
        ->where('data_health.reasons.0.reason', 'incomplete')->where('data_health.reasons.0.accounts', ['Partial Shop']));

    Cache::flush();
    $this->actingAs(dbAdmin())->get('/ads?from=2026-09-21&to=2026-09-28')->assertInertia(fn (AssertableInertia $p) => $p->where('data_health.reasons', []));
});

it('keeps the numbers under review until ads:gate --pass, audits it and reopens with --reopen', function () {
    $admin = dbAdmin();

    $this->actingAs($admin)->get('/ads')->assertInertia(fn (AssertableInertia $p) => $p->where('numbers_under_review', true));

    expect(Artisan::call('ads:gate', ['--pass' => true, '--note' => 'September matches Ads Manager']))->toBe(0);
    $row = AdsAuditLog::where('action', 'gate.passed')->first();
    expect($row)->not->toBeNull()->and($row->meta['note'])->toBe('September matches Ads Manager');
    $this->actingAs($admin)->get('/ads')->assertInertia(fn (AssertableInertia $p) => $p->where('numbers_under_review', false));

    expect(Artisan::call('ads:gate', ['--reopen' => true]))->toBe(0);
    expect(AdsAuditLog::where('action', 'gate.reopened')->count())->toBe(1);
    $this->actingAs($admin)->get('/ads')->assertInertia(fn (AssertableInertia $p) => $p->where('numbers_under_review', true));

    expect(Artisan::call('ads:gate'))->toBe(1); // neither flag: refuses
});

it('flags a range clamped to the history start', function () {
    config(['crm.ads.history_start' => '2026-09-01']);
    $this->actingAs(dbAdmin())->get('/ads?from=2026-01-01&to=2026-09-10')->assertInertia(fn (AssertableInertia $p) => $p->where('clamped_to_history', true));
    $this->actingAs(dbAdmin())->get('/ads?from=2026-09-02&to=2026-09-10')->assertInertia(fn (AssertableInertia $p) => $p->where('clamped_to_history', false));
});

it('gives /up/crm an ads block with no names and nothing token-like', function () {
    config(['crm.health.token' => 'h-token']);
    $c = AdPlatformConnection::factory()->meta()->create(['credentials' => ['access_token' => 'EAABsecrettokenvalue']]);
    $a = AdAccount::factory()->meta()->create(['connection_id' => $c->id, 'name' => 'Secret Name Shop']);
    AdsSyncRun::factory()->create(['ad_account_id' => $a->id, 'status' => 'ok', 'finished_at' => now()->subMinutes(125)]);
    AdsSyncRun::factory()->create(['ad_account_id' => $a->id, 'status' => 'running', 'started_at' => now()->subHours(3), 'finished_at' => null]);
    DB::table('failed_jobs')->insert(['uuid' => (string) Str::uuid(), 'connection' => 'redis', 'queue' => 'commercelong', 'payload' => '{"displayName":"App\\\\Ads\\\\Sync\\\\SyncAdAccount"}', 'exception' => 'boom', 'failed_at' => now()->subHour()]);
    Cache::forever('crm:scheduler_heartbeat', now()->subMinutes(3)->toISOString());

    $res = $this->getJson('/up/crm', ['X-Health-Token' => 'h-token'])->assertOk();

    $res->assertJsonPath('ads.max_staleness_minutes', 125)->assertJsonPath('ads.stuck_runs', 1)->assertJsonPath('ads.failed_sync_jobs_24h', 1)
        ->assertJsonPath('ads.scheduler_age_minutes', 3)->assertJsonStructure(['ads' => ['last_usage_pct']]);
    expect($res->getContent())->not->toContain('EAAB')->not->toContain('Secret Name Shop')->not->toContain('act_');
});

it('shows the chat link rate with its definition on the accounts page', function () {
    foreach ([1, 2, 3] as $i) {
        $o = Order::factory()->create(['source' => OrderSource::Chat]);
        DB::table('orders')->where('id', $o->id)->update(['conversation_id' => Conversation::factory()->create()->id]);
    }
    Order::factory()->create(['source' => OrderSource::Chat]);

    $this->actingAs(dbAdmin())->get('/ads/accounts')->assertOk()->assertInertia(fn (AssertableInertia $p) => $p
        ->where('link_rate.rate', 0.75)->where('link_rate.orders', 4)->where('link_rate.days', 14));
});

it('computes banner reasons without writing health state', function () {
    dbAccount('Quiet Shop', 4 * 60);

    $out = app(DataHealth::class)->forFilter(AdsFilter::fromRequest(request(), dbAdmin()));

    expect($out['reasons'][0]['reason'])->toBe('stale')->and(AdsHealthState::count())->toBe(0);
});

it('flags an account never judged (complete_from null) as not verified yet, but not one that never synced', function () {
    $synced = dbAccount('Fresh Synced');
    DB::table('ad_accounts')->where('id', $synced->id)->update(['complete_from' => null]);
    $c = AdPlatformConnection::factory()->meta()->create(['status' => 'connected']);
    AdAccount::factory()->meta()->create(['connection_id' => $c->id, 'name' => 'Brand New']);

    $this->actingAs(dbAdmin())->get('/ads?from=2026-09-05&to=2026-09-28')->assertInertia(fn (AssertableInertia $p) => $p
        ->where('data_health.reasons.0.reason', 'incomplete')->where('data_health.reasons.0.accounts', [])
        ->where('data_health.reasons.0.unverified', ['Fresh Synced']));
});
