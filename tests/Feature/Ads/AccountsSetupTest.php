<?php

use App\Ads\Platforms\AdsApiException;
use App\Ads\Platforms\Fake\FakeAdsDriver;
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
