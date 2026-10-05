<?php

use App\Ads\Platforms\AdsApiException;
use App\Ads\Platforms\Data\ObjectState;
use App\Ads\Platforms\Fake\FakeAdsDriver;
use App\Ads\Platforms\Meta\MetaAdsApi;
use App\Ads\Platforms\Meta\MetaAdsWriter;
use App\Ads\Platforms\MissingPermission;
use App\Ads\Platforms\PlatformUnreachable;
use App\Ads\Platforms\RateLimited;
use App\Ads\Platforms\ReadUnsupported;
use App\Ads\Platforms\TikTok\TikTokAdsWriter;
use App\Ads\Platforms\TokenInvalid;
use App\Models\Ad;
use App\Models\AdAccount;
use App\Models\AdPlatformConnection;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    Http::preventStrayRequests();
    Cache::forget('ads-fake-writer');
    FakeAdsDriver::reset();
});

function wrMetaAccount(): AdAccount
{
    $c = AdPlatformConnection::factory()->meta()->create(['credentials' => ['access_token' => 'tok']]);

    return AdAccount::factory()->meta()->create(['external_id' => 'act_9', 'connection_id' => $c->id, 'currency' => 'EGP']);
}

it('reads an ad with its ad set and campaign in one GET', function () {
    fakeMetaGraph(['GET 123' => [
        'id' => '123', 'status' => 'PAUSED', 'effective_status' => 'ADSET_PAUSED',
        'adset' => ['id' => '45', 'status' => 'ACTIVE', 'daily_budget' => '150000', 'lifetime_budget' => '0', 'end_time' => '2026-10-31T23:59:00+0200'],
        'campaign' => ['id' => '6', 'status' => 'ACTIVE', 'daily_budget' => '0'],
    ]]);

    $s = app(MetaAdsWriter::class)->readObject(wrMetaAccount(), 'ad', '123');

    expect($s)->toBeInstanceOf(ObjectState::class)
        ->and($s->status)->toBe('PAUSED')->and($s->effectiveStatus)->toBe('ADSET_PAUSED')
        ->and($s->dailyBudgetMinor)->toBeNull()->and($s->currency)->toBe('EGP')
        ->and($s->parents)->toHaveCount(2)
        ->and($s->parents[0]['level'])->toBe('adset')->and($s->parents[0]['dailyBudgetMinor'])->toBe(150000)
        ->and($s->parents[0]['lifetimeBudgetMinor'])->toBeNull()->and($s->parents[0]['endsAt']?->toIso8601String())->toBe('2026-10-31T21:59:00+00:00')
        ->and($s->parents[1]['level'])->toBe('campaign')->and($s->parents[1]['dailyBudgetMinor'])->toBeNull();
    Http::assertSent(fn (Request $r) => str_contains(urldecode($r->url()), 'fields=status,effective_status,adset{status,daily_budget,lifetime_budget,end_time},campaign{status,daily_budget,lifetime_budget,stop_time}')
        && $r->hasHeader('Authorization', 'Bearer tok') && ! str_contains($r->url(), 'access_token'));
});

it('reads an ad set with its campaign and a campaign alone', function () {
    fakeMetaGraph([
        'GET 45' => ['id' => '45', 'status' => 'ACTIVE', 'effective_status' => 'ACTIVE', 'daily_budget' => '150000', 'campaign' => ['status' => 'ACTIVE', 'lifetime_budget' => '9000000', 'stop_time' => '2026-11-01T00:00:00+0000']],
        'GET 6' => ['id' => '6', 'status' => 'PAUSED', 'effective_status' => 'PAUSED', 'daily_budget' => '250000'],
    ]);
    $w = app(MetaAdsWriter::class);
    $acc = wrMetaAccount();

    $set = $w->readObject($acc, 'adset', '45');
    $camp = $w->readObject($acc, 'campaign', '6');

    expect($set->dailyBudgetMinor)->toBe(150000)->and($set->parents)->toHaveCount(1)
        ->and($set->parents[0]['level'])->toBe('campaign')->and($set->parents[0]['lifetimeBudgetMinor'])->toBe(9000000)
        ->and($set->parents[0]['endsAt']?->toIso8601String())->toBe('2026-11-01T00:00:00+00:00')
        ->and($camp->status)->toBe('PAUSED')->and($camp->dailyBudgetMinor)->toBe(250000)->and($camp->parents)->toBe([]);
    Http::assertSent(fn (Request $r) => str_contains($r->url(), '/45?') && str_contains(urldecode($r->url()), 'fields=status,effective_status,daily_budget,lifetime_budget,end_time,campaign{status,daily_budget,lifetime_budget,stop_time}'));
    Http::assertSent(fn (Request $r) => str_contains($r->url(), '/6?') && str_contains(urldecode($r->url()), 'fields=status,effective_status,daily_budget,lifetime_budget,stop_time'));
});

it('sends a Meta status write with the short write timeout', function () {
    config(['crm.ads.write.timeout_seconds' => 20]);
    $api = Mockery::mock(MetaAdsApi::class)->makePartial();
    $api->shouldReceive('post')->once()->with('tok', '123', ['status' => 'PAUSED'], 20)->andReturn(['success' => true]);

    (new MetaAdsWriter($api))->setStatus(wrMetaAccount(), 'ad', '123', 'paused');
});

it('reads with the write timeout too', function () {
    config(['crm.ads.write.timeout_seconds' => 20]);
    $api = Mockery::mock(MetaAdsApi::class)->makePartial();
    $api->shouldReceive('get')->once()->withArgs(fn ($token, $path, $query, $timeout) => $token === 'tok' && $path === '6' && $timeout === 20)
        ->andReturn(['status' => 'ACTIVE', 'effective_status' => 'ACTIVE']);

    expect((new MetaAdsWriter($api))->readObject(wrMetaAccount(), 'campaign', '6')->status)->toBe('ACTIVE');
});

it('turns a transport failure into PlatformUnreachable (still an AdsApiException)', function () {
    Http::fake(fn () => throw new ConnectionException('cURL error 28: timed out'));

    try {
        app(MetaAdsWriter::class)->setStatus(wrMetaAccount(), 'ad', '123', 'paused');
        $this->fail('expected PlatformUnreachable');
    } catch (PlatformUnreachable $e) {
        expect($e)->toBeInstanceOf(AdsApiException::class)->and($e->getMessage())->toContain('unreachable');
    }
});

it('says TikTok cannot read objects yet', function () {
    $acc = AdAccount::factory()->tiktok()->create();

    expect(fn () => app(TikTokAdsWriter::class)->readObject($acc, 'ad', '123'))->toThrow(ReadUnsupported::class);
});

it('fake: reads the last fake status, else the local row, else PAUSED', function () {
    $acc = AdAccount::factory()->meta()->create(['currency' => 'EGP']);
    $ad = Ad::factory()->for($acc, 'account')->create(['status' => 'ACTIVE']);
    $fake = app(FakeAdsDriver::class);

    expect($fake->readObject($acc, 'ad', $ad->external_id)->status)->toBe('ACTIVE')
        ->and($fake->readObject($acc, 'ad', '999')->status)->toBe('PAUSED');

    $fake->setStatus($acc, 'ad', $ad->external_id, 'paused');
    $s = $fake->readObject($acc, 'ad', $ad->external_id);

    expect($s->status)->toBe('PAUSED')->and($s->currency)->toBe('EGP')
        ->and($s->parents[0]['level'])->toBe('adset')->and($s->parents[0]['dailyBudgetMinor'])->toBe(50000)
        ->and($s->parents[1]['level'])->toBe('campaign')->and($s->parents[1]['dailyBudgetMinor'])->toBeNull()
        ->and($fake->readObject($acc, 'adset', '1')->dailyBudgetMinor)->toBe(50000)
        ->and($fake->readObject($acc, 'campaign', '1')->dailyBudgetMinor)->toBeNull();
});

it('fake: seedObject overrides the default state', function () {
    $acc = AdAccount::factory()->meta()->create();
    $fake = app(FakeAdsDriver::class);
    $fake->seedObject('adset', '45', ['status' => 'ACTIVE', 'dailyBudgetMinor' => 9000000]);

    $s = app(FakeAdsDriver::class)->readObject($acc, 'adset', '45');

    expect($s->status)->toBe('ACTIVE')->and($s->dailyBudgetMinor)->toBe(9000000);
});

it('fake: unreachable_after records the change and then throws', function () {
    $acc = AdAccount::factory()->meta()->create();
    FakeAdsDriver::failNext('setStatus', 'unreachable_after');

    expect(fn () => app(FakeAdsDriver::class)->setStatus($acc, 'ad', '123', 'paused'))->toThrow(PlatformUnreachable::class);
    expect(Cache::get('ads-fake-writer')['statuses'])->toBe([['level' => 'ad', 'id' => '123', 'status' => 'paused']]);
});

it('fake: unreachable_before records nothing', function () {
    $acc = AdAccount::factory()->meta()->create();
    FakeAdsDriver::failNext('setStatus', 'unreachable_before');

    expect(fn () => app(FakeAdsDriver::class)->setStatus($acc, 'ad', '123', 'paused'))->toThrow(PlatformUnreachable::class);
    expect(Cache::get('ads-fake-writer')['statuses'] ?? [])->toBe([]);
});

it('fake: each fault kind throws its exception', function (string $kind, string $class) {
    $acc = AdAccount::factory()->meta()->create();
    FakeAdsDriver::failNext('setStatus', $kind);

    expect(fn () => app(FakeAdsDriver::class)->setStatus($acc, 'ad', '123', 'active'))->toThrow($class);
})->with([
    ['rate', RateLimited::class], ['rejected', AdsApiException::class], ['permission', MissingPermission::class], ['token', TokenInvalid::class],
]);

it('fake: a rate fault carries a regain time of 120 s', function () {
    $acc = AdAccount::factory()->meta()->create();
    FakeAdsDriver::failNext('readObject', 'rate');

    try {
        app(FakeAdsDriver::class)->readObject($acc, 'ad', '1');
        $this->fail('expected RateLimited');
    } catch (RateLimited $e) {
        expect($e->retryAfterSeconds)->toBe(120);
    }
});

it('fake: a fault is consumed after its times and reset clears faults and hooks', function () {
    $acc = AdAccount::factory()->meta()->create();
    $fake = app(FakeAdsDriver::class);
    FakeAdsDriver::failNext('setStatus', 'rejected', 2);

    expect(fn () => $fake->setStatus($acc, 'ad', '1', 'paused'))->toThrow(AdsApiException::class);
    expect(fn () => $fake->setStatus($acc, 'ad', '1', 'paused'))->toThrow(AdsApiException::class);
    $fake->setStatus($acc, 'ad', '1', 'paused');
    expect(Cache::get('ads-fake-writer')['statuses'])->toHaveCount(1);

    $calls = 0;
    FakeAdsDriver::failNext('setStatus', 'rejected');
    FakeAdsDriver::beforeSetStatus(function () use (&$calls) {
        $calls++;
    });
    FakeAdsDriver::reset();
    $fake->setStatus($acc, 'ad', '1', 'active');
    expect($calls)->toBe(0)->and(Cache::get('ads-fake-writer')['statuses'])->toHaveCount(2);
});

it('fake: the beforeSetStatus hook runs once, inside the call, before recording', function () {
    $acc = AdAccount::factory()->meta()->create();
    $seen = [];
    FakeAdsDriver::beforeSetStatus(function (AdAccount $a, string $level, string $id, string $status) use (&$seen) {
        $seen[] = [$id, $status, count(Cache::get('ads-fake-writer')['statuses'] ?? [])];
        app(FakeAdsDriver::class)->setStatus($a, $level, $id, 'paused'); // a nested call does not re-run the hook
    });

    app(FakeAdsDriver::class)->setStatus($acc, 'ad', '1', 'active');
    app(FakeAdsDriver::class)->setStatus($acc, 'ad', '1', 'active');

    expect($seen)->toBe([['1', 'active', 0]])
        ->and(array_column(Cache::get('ads-fake-writer')['statuses'], 'status'))->toBe(['paused', 'active', 'active']);
});
