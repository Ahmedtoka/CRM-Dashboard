<?php

use App\Ads\Platforms\AdsApiException;
use App\Ads\Platforms\Fake\FakeAdsDriver;
use App\Ads\Sync\HistoryWindow;
use App\Ads\Sync\QueueInspector;
use App\Ads\Sync\SyncAdAccount;
use App\Enums\UserRole;
use App\Models\Ad;
use App\Models\AdAccount;
use App\Models\AdDailyMetric;
use App\Models\AdPlatformConnection;
use App\Models\AdsSyncRun;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia as Assert;

/* FS4 / F6: the ads setup accounts page: range + accounts filter, summary tiles, one sync with a status endpoint. */

beforeEach(function () {
    Http::preventStrayRequests();
    config(['crm.ads.drivers.meta' => 'fake', 'crm.ads.drivers.tiktok' => 'fake', 'crm.ads.drivers.google' => 'fake']);
    $this->withoutVite();
    $this->travelTo(CarbonImmutable::parse('2026-10-06 12:00', 'Africa/Cairo'));
});

function fs4User(UserRole $role = UserRole::Admin): User
{
    return User::factory()->create(['role' => $role]);
}

function fs4Spend(AdAccount $account, string $date, float $spend): void
{
    $ad = Ad::factory()->create(['ad_account_id' => $account->id]);
    AdDailyMetric::factory()->create(['ad_id' => $ad->id, 'ad_account_id' => $account->id, 'date' => $date, 'spend' => $spend]);
}

it('defaults to this month and sums spend in the range only, with the summary tiles', function () {
    $c = AdPlatformConnection::factory()->create();
    $a = AdAccount::factory()->create(['connection_id' => $c->id, 'currency' => 'EGP', 'last_synced_at' => '2026-10-06 08:00:00']);
    $b = AdAccount::factory()->create(['connection_id' => $c->id, 'currency' => 'USD', 'is_active' => false]);
    fs4Spend($a, '2026-10-02', 100);
    fs4Spend($a, '2026-09-28', 999); // before the month: left out
    fs4Spend($b, '2026-10-03', 20);
    AdsSyncRun::factory()->create(['ad_account_id' => $b->id, 'status' => 'error', 'error' => 'Rate limit hit', 'started_at' => now()->subHour(), 'finished_at' => now()->subHour()]);

    $this->actingAs(fs4User())->get('/ads/accounts')->assertOk()->assertInertia(fn (Assert $p) => $p
        ->component('Ads/Accounts')
        ->where('filters', ['from' => '2026-10-01', 'to' => '2026-10-06', 'accounts' => []])
        ->where('connections.0.accounts.0.spend', 100)
        ->where('connections.0.accounts.1.spend', 20)
        ->where('connections.0.accounts.1.last_run.status', 'error')
        ->where('connections.0.accounts.1.last_run.error', 'Rate limit hit')
        ->where('summary.accounts', 2)
        ->where('summary.active', 1)
        ->where('summary.errors', 1)
        ->where('summary.spend', [['currency' => 'EGP', 'amount' => 100], ['currency' => 'USD', 'amount' => 20]])
        ->where('summary.last_sync', fn ($v) => str_starts_with((string) $v, '2026-10-06T')));
});

it('narrows rows and summary to the picked accounts and a custom range', function () {
    $c = AdPlatformConnection::factory()->create();
    $a = AdAccount::factory()->create(['connection_id' => $c->id]);
    $b = AdAccount::factory()->create(['connection_id' => $c->id]);
    fs4Spend($a, '2026-09-20', 50);
    fs4Spend($b, '2026-09-20', 70);

    $this->actingAs(fs4User())->get("/ads/accounts?from=2026-09-15&to=2026-09-30&accounts={$b->id}")
        ->assertInertia(fn (Assert $p) => $p
            ->where('filters', ['from' => '2026-09-15', 'to' => '2026-09-30', 'accounts' => [$b->id]])
            ->has('connections.0.accounts', 1)
            ->where('connections.0.accounts.0.id', $b->id)
            ->where('connections.0.accounts.0.spend', 70)
            ->where('summary.accounts', 1)
            ->has('account_options', 2));
});

it('falls back to the default range when the dates are invalid', function () {
    $this->actingAs(fs4User())->get('/ads/accounts?from=2026-10-05&to=2026-10-01')
        ->assertInertia(fn (Assert $p) => $p->where('filters.from', '2026-10-01')->where('filters.to', '2026-10-06'));
});

it('queues a recent sync for the picked accounts only', function () {
    Queue::fake();
    $c = AdPlatformConnection::factory()->create();
    AdAccount::factory()->create(['connection_id' => $c->id]);
    $b = AdAccount::factory()->create(['connection_id' => $c->id]);

    $this->actingAs(fs4User(UserRole::Supervisor))->postJson('/ads/accounts/sync', ['accounts' => [$b->id, 999999]])
        ->assertOk()
        ->assertJsonPath('accounts', [$b->id])
        ->assertJsonPath('errors', [])
        ->assertJsonStructure(['since', 'accounts', 'errors']);

    Queue::assertPushed(SyncAdAccount::class, 1);
    Queue::assertPushed(SyncAdAccount::class, fn (SyncAdAccount $j) => $j->accountId === $b->id && $j->kind === 'recent' && $j->trigger === 'manual');
});

it('syncs everything: re-discovers accounts, backfills new ones, recent for known ones', function () {
    Queue::fake();
    $c = AdPlatformConnection::factory()->create();
    $known = AdAccount::factory()->create(['connection_id' => $c->id, 'external_id' => 'act_demo_cloting']);
    AdAccount::factory()->create(['connection_id' => $c->id, 'is_active' => false]);

    $res = $this->actingAs(fs4User())->postJson('/ads/accounts/sync', [])->assertOk();

    expect($res->json('accounts'))->toHaveCount(3)->toContain($known->id);
    Queue::assertPushed(SyncAdAccount::class, 3);
    Queue::assertPushed(SyncAdAccount::class, fn (SyncAdAccount $j) => $j->accountId === $known->id && $j->kind === 'recent');
    Queue::assertPushed(SyncAdAccount::class, fn (SyncAdAccount $j) => $j->kind === 'backfill');
});

it('reports a connection that fails discovery and still syncs the known accounts', function () {
    Queue::fake();
    app()->bind(FakeAdsDriver::class, fn () => new class extends FakeAdsDriver
    {
        public function accounts(AdPlatformConnection $c): array
        {
            throw new AdsApiException('Rate limit hit');
        }
    });
    $c = AdPlatformConnection::factory()->create(['name' => 'Main BM']);
    $a = AdAccount::factory()->create(['connection_id' => $c->id]);

    $this->actingAs(fs4User())->postJson('/ads/accounts/sync', [])->assertOk()
        ->assertJsonPath('accounts', [$a->id])
        ->assertJsonPath('errors.0.connection', 'Main BM')
        ->assertJsonPath('errors.0.message', 'Rate limit hit');
    Queue::assertPushed(SyncAdAccount::class, 1);
});

it('reports each account as queued, running, done or error since the click', function () {
    $since = now()->subMinute();
    [$queued, $running, $done, $failed, $skipped] = AdAccount::factory()->count(5)->create()->all();
    AdsSyncRun::factory()->create(['ad_account_id' => $queued->id, 'status' => 'ok', 'started_at' => now()->subHours(2), 'finished_at' => now()->subHours(2)]);
    AdsSyncRun::factory()->create(['ad_account_id' => $running->id, 'status' => 'running', 'started_at' => now()->subMinutes(5), 'finished_at' => null]);
    AdsSyncRun::factory()->create(['ad_account_id' => $done->id, 'status' => 'ok', 'started_at' => now(), 'finished_at' => now()]);
    AdsSyncRun::factory()->create(['ad_account_id' => $failed->id, 'status' => 'error', 'error' => 'Boom', 'started_at' => now(), 'finished_at' => now()]);
    AdsSyncRun::factory()->create(['ad_account_id' => $skipped->id, 'status' => 'skipped', 'started_at' => now(), 'finished_at' => now()]);
    $ids = implode(',', [$queued->id, $running->id, $done->id, $failed->id, $skipped->id]);
    $url = '/ads/accounts/sync-status?accounts='.$ids.'&since='.urlencode($since->toIso8601String());

    $res = $this->actingAs(fs4User())->getJson($url)->assertOk();

    $states = collect($res->json('accounts'))->pluck('state', 'id');
    expect($states[$queued->id])->toBe('queued')
        ->and($states[$running->id])->toBe('running')
        ->and($states[$done->id])->toBe('done')
        ->and($states[$failed->id])->toBe('error')
        ->and($states[$skipped->id])->toBe('done')
        ->and(collect($res->json('accounts'))->firstWhere('id', $failed->id)['error'])->toBe('Boom')
        ->and($res->json('done'))->toBe(3)
        ->and($res->json('total'))->toBe(5)
        ->and($res->json('finished'))->toBeFalse();

    AdsSyncRun::query()->where('status', 'running')->update(['status' => 'ok', 'finished_at' => now()]);
    AdsSyncRun::factory()->create(['ad_account_id' => $queued->id, 'status' => 'ok', 'started_at' => now(), 'finished_at' => now()]);
    $this->actingAs(fs4User())->getJson($url)->assertJsonPath('done', 5)->assertJsonPath('total', 5)->assertJsonPath('finished', true);
});

it('keeps the sync and status endpoints to supervisors and up', function () {
    $account = AdAccount::factory()->create();
    foreach ([UserRole::MediaBuyer, UserRole::Content, UserRole::Moderator] as $role) {
        $user = fs4User($role);
        $this->actingAs($user)->postJson('/ads/accounts/sync', ['accounts' => [$account->id]])->assertForbidden();
        $this->actingAs($user)->getJson('/ads/accounts/sync-status?accounts='.$account->id)->assertForbidden();
    }
    $this->actingAs(fs4User(UserRole::Supervisor))->getJson('/ads/accounts/sync-status?accounts='.$account->id)->assertOk();
});

/* ---- review round 1 ---- */

function fs4Queue(array $ready, array $delayed = []): void
{
    $payload = fn (SyncAdAccount $job) => json_encode(['displayName' => SyncAdAccount::class, 'attempts' => 1, 'data' => ['command' => serialize($job)]]);
    app()->instance(QueueInspector::class, new QueueInspector(fn () => [
        array_map($payload, $ready),
        array_map(fn (SyncAdAccount $j) => [$payload($j), (float) now()->addMinutes(15)->timestamp], $delayed),
    ]));
}

it('skips accounts of stopped or dead connections and reports them per connection (I2)', function () {
    Queue::fake();
    $ok = AdPlatformConnection::factory()->create(['name' => 'Live BM']);
    $dead = AdPlatformConnection::factory()->create(['name' => 'Dead BM', 'status' => 'needs_reconnect']);
    $off = AdPlatformConnection::factory()->create(['name' => 'Old BM', 'status' => 'disabled']);
    $a = AdAccount::factory()->create(['connection_id' => $ok->id]);
    $b = AdAccount::factory()->create(['connection_id' => $dead->id]);
    $c = AdAccount::factory()->create(['connection_id' => $off->id]);

    $this->actingAs(fs4User())->postJson('/ads/accounts/sync', ['accounts' => [$a->id, $b->id, $c->id]])->assertOk()
        ->assertJsonPath('accounts', [$a->id])
        ->assertJsonPath('errors.0.connection', 'Dead BM')
        ->assertJsonPath('errors.1.connection', 'Old BM');
    Queue::assertPushed(SyncAdAccount::class, 1);

    // All accounts: the dead one is reported and never re-discovered; the archived one stays quiet.
    Queue::fake();
    $res = $this->actingAs(fs4User())->postJson('/ads/accounts/sync')->assertOk();
    expect(collect($res->json('errors'))->pluck('connection')->all())->toBe(['Dead BM'])
        ->and($res->json('accounts'))->not->toContain($b->id)->not->toContain($c->id);
    Queue::assertNotPushed(SyncAdAccount::class, fn (SyncAdAccount $j) => in_array($j->accountId, [$b->id, $c->id], true));
});

it('counts stopped, dead-connection and missing accounts as finished (I1)', function () {
    $dead = AdPlatformConnection::factory()->create(['status' => 'needs_reconnect']);
    $paused = AdAccount::factory()->create(['is_active' => false]);
    $orphan = AdAccount::factory()->create(['connection_id' => $dead->id]);

    $this->actingAs(fs4User())->getJson("/ads/accounts/sync-status?accounts={$paused->id},{$orphan->id},999999&since=".urlencode(now()->toIso8601String()))
        ->assertOk()
        ->assertJsonPath('total', 2)->assertJsonPath('done', 2)->assertJsonPath('finished', true)
        ->assertJsonPath('accounts.0.state', 'skipped');
});

it('keeps a backfill running between its 30-day chunks and done only at the oldest chunk (I3)', function () {
    config(['crm.ads.backfill_days' => 90]);
    $a = AdAccount::factory()->create();
    $since = now()->subMinute();
    $today = CarbonImmutable::now('Africa/Cairo')->startOfDay();
    AdsSyncRun::factory()->create(['ad_account_id' => $a->id, 'kind' => 'backfill', 'status' => 'ok', 'from_date' => $today->subDays(29), 'to_date' => $today, 'started_at' => now(), 'finished_at' => now()]);
    $url = "/ads/accounts/sync-status?accounts={$a->id}&since=".urlencode($since->toIso8601String());

    $this->actingAs(fs4User())->getJson($url)->assertJsonPath('accounts.0.state', 'running')->assertJsonPath('finished', false);

    $days = min(90, HistoryWindow::daysFromStart($today));
    $oldest = $today->subDays($days - 1);
    AdsSyncRun::factory()->create(['ad_account_id' => $a->id, 'kind' => 'backfill', 'status' => 'ok', 'from_date' => $oldest, 'to_date' => $oldest->addDays(29), 'started_at' => now(), 'finished_at' => now()]);
    $this->actingAs(fs4User())->getJson($url)->assertJsonPath('accounts.0.state', 'done')->assertJsonPath('finished', true);
});

it('shows a rate-limited run whose job is back in the queue as retrying, not failed (I3)', function () {
    $a = AdAccount::factory()->create();
    AdsSyncRun::factory()->create(['ad_account_id' => $a->id, 'status' => 'error', 'error' => 'User request limit reached', 'started_at' => now(), 'finished_at' => now()]);
    fs4Queue([], [new SyncAdAccount($a->id, 3, 'recent', 'manual', 1)]);

    $this->actingAs(fs4User())->getJson("/ads/accounts/sync-status?accounts={$a->id}&since=".urlencode(now()->subMinute()->toIso8601String()))
        ->assertJsonPath('accounts.0.state', 'retrying')->assertJsonPath('done', 0)->assertJsonPath('finished', false);
});

it('resumes only the syncs this user started, from the server clock (I1)', function () {
    $me = fs4User();
    $other = fs4User();
    [$a, $b, $c] = AdAccount::factory()->count(3)->create()->all();
    $started = now()->subMinutes(2)->startOfSecond();
    AdsSyncRun::factory()->create(['ad_account_id' => $a->id, 'status' => 'running', 'trigger' => 'manual', 'triggered_by_id' => $me->id, 'started_at' => $started, 'finished_at' => null]);
    AdsSyncRun::factory()->create(['ad_account_id' => $b->id, 'status' => 'running', 'trigger' => 'schedule', 'triggered_by_id' => null, 'started_at' => now(), 'finished_at' => null]);
    fs4Queue([new SyncAdAccount($c->id, 3, 'recent', 'manual', $other->id)]);

    $this->actingAs($me)->get('/ads/accounts')->assertInertia(fn (Assert $p) => $p
        ->where('sync_resume.accounts', [$a->id])
        ->where('sync_resume.others', 2)
        ->where('sync_resume.since', fn ($v) => CarbonImmutable::parse($v)->equalTo($started)));
});

it('offers only syncable accounts to the picker and counts connection problems only for shown accounts', function () {
    $ok = AdPlatformConnection::factory()->create();
    $dead = AdPlatformConnection::factory()->create(['status' => 'needs_reconnect']);
    $a = AdAccount::factory()->create(['connection_id' => $ok->id, 'name' => 'A']);
    AdAccount::factory()->create(['connection_id' => $ok->id, 'name' => 'B', 'is_active' => false]);
    AdAccount::factory()->create(['connection_id' => $dead->id, 'name' => 'C']);

    $this->actingAs(fs4User())->get('/ads/accounts')->assertInertia(fn (Assert $p) => $p
        ->where('account_options.0.can_sync', true)
        ->where('account_options.1.can_sync', false)
        ->where('account_options.2.can_sync', false)
        ->where('summary.errors', 1));
    $this->actingAs(fs4User())->get("/ads/accounts?accounts={$a->id}")->assertInertia(fn (Assert $p) => $p->where('summary.errors', 0));
});

it('has no per-connection or per-account sync routes any more', function () {
    $c = AdPlatformConnection::factory()->create();
    $a = AdAccount::factory()->create(['connection_id' => $c->id]);
    $this->actingAs(fs4User())->post("/ads/connections/{$c->id}/sync")->assertNotFound();
    $this->actingAs(fs4User())->post("/ads/accounts/{$a->id}/sync")->assertNotFound();
});
