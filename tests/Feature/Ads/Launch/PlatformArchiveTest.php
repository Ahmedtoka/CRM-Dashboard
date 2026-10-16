<?php

use App\Ads\Control\Write\WriteSwitch;
use App\Ads\Control\WriteActionLog;
use App\Ads\Launch\LaunchService;
use App\Ads\Launch\LaunchState;
use App\Ads\Launch\PlatformArchive;
use App\Ads\Platforms\Fake\FakeAdsDriver;
use App\Models\Ad;
use App\Models\AdDailyMetric;
use App\Models\AdPublication;
use App\Models\AdsAuditLog;
use App\Models\AdWriteAction;
use App\Models\AdWriteStep;
use Illuminate\Support\Facades\Cache;
use Tests\Support\LaunchWorld;

beforeEach(fn () => LaunchWorld::boot());

/** The fake writer's recorded status calls for the ad level. */
function fakeAdCalls(): array
{
    return array_values(array_filter((Cache::get('ads-fake-writer') ?? [])['statuses'] ?? [], fn ($s) => $s['level'] === 'ad'));
}

function rejectedLaunch(array $w)
{
    $l = LaunchWorld::launch($w, LaunchState::AwaitingApproval);

    return app(LaunchService::class)->reject($w['manager'], $l, 'off_brand', null);
}

it('archives on Meta the paused ad of a rejected launch, once, and shows it in the launch history', function () {
    $w = LaunchWorld::make();
    $l = rejectedLaunch($w);
    $ext = AdPublication::where('ad_launch_id', $l->id)->value('external_ad_id');

    $r = app(PlatformArchive::class)->run();

    expect($r['archived'])->toBe(1)
        ->and(fakeAdCalls())->toBe([['level' => 'ad', 'id' => $ext, 'status' => 'archived']])
        ->and(Ad::where('external_id', $ext)->value('status'))->toBe('ARCHIVED');
    $x = AdWriteAction::where('type', PlatformArchive::TYPE)->sole();
    expect($x->state)->toBe(AdWriteAction::SUCCEEDED)->and($x->to_status)->toBe('archived')
        ->and($x->source_ref)->toBe($l->public_id)->and($x->actor_type)->toBe('system')
        ->and(AdWriteStep::where('ad_write_action_id', $x->id)->value('state'))->toBe(AdWriteStep::SUCCEEDED)
        ->and(AdsAuditLog::where('subject_type', 'AdLaunch')->where('subject_id', $l->id)->where('action', 'launch.platform_archived')->count())->toBe(1);

    // idempotent: a second pass neither queues nor calls again
    expect(app(PlatformArchive::class)->run()['archived'])->toBe(0)
        ->and(fakeAdCalls())->toHaveCount(1)
        ->and(AdWriteAction::where('type', PlatformArchive::TYPE)->count())->toBe(1);
});

it('archives an expired launch and a manager-returned round from the hourly sweep', function () {
    $w = LaunchWorld::make();
    $expired = LaunchWorld::launch($w, LaunchState::AwaitingApproval, ['expires_at' => now()->subMinute()]);
    $returned = LaunchWorld::launch($w, LaunchState::AwaitingApproval, ['captions' => [LaunchWorld::caption(2)]]);
    app(LaunchService::class)->managerReturn($w['manager'], $returned, 'caption_wrong', null);

    $this->artisan('ads:launch-sweep')->expectsOutput('Archived on the platform 2, waiting 0, failed 0, skipped 0.')->assertSuccessful();

    expect($expired->fresh()->state)->toBe(LaunchState::Expired)
        ->and($returned->fresh()->state)->toBe(LaunchState::BuyerReview)
        ->and(fakeAdCalls())->toHaveCount(2);
});

it('never touches an ad that ran: a Run on record, delivery in the metrics, or ACTIVE on the platform', function (string $how) {
    $w = LaunchWorld::make();
    $l = rejectedLaunch($w);
    $p = AdPublication::where('ad_launch_id', $l->id)->sole();
    $ad = Ad::where('external_id', $p->external_ad_id)->sole();
    match ($how) {
        'run' => AdWriteAction::create([
            'type' => 'set_status', 'state' => AdWriteAction::SUCCEEDED, 'platform' => 'meta', 'ad_account_id' => $w['account']->id,
            'target_level' => 'ad', 'target_external_id' => $ad->external_id, 'target_key' => $w['account']->id.':ad:'.$ad->external_id,
            'to_status' => 'active', 'params' => ['to' => 'active'], 'diff' => [], 'diff_hash' => str_repeat('a', 64), 'idempotency_key' => 'k1',
            'proposed_by_id' => $w['manager']->id, 'confirmed_at' => now()->subDay(),
        ]),
        'metrics' => AdDailyMetric::create(['ad_id' => $ad->id, 'ad_account_id' => $w['account']->id, 'date' => now()->subDay()->toDateString(), 'spend' => 12.5, 'impressions' => 300]),
        'live' => app(FakeAdsDriver::class)->seedObject('ad', $ad->external_id, ['status' => 'ACTIVE', 'effectiveStatus' => 'ACTIVE']),
    };

    $r = app(PlatformArchive::class)->run();

    expect($r['skipped'])->toBe(1)->and($r['archived'])->toBe(0)->and(fakeAdCalls())->toBe([])
        ->and(AdWriteAction::where('type', PlatformArchive::TYPE)->value('error_code'))->toBe('ever_active')
        ->and(AdsAuditLog::where('subject_id', $l->id)->where('action', 'launch.platform_archive_skipped')->count())->toBe(1);
    // a skipped ad is never retried
    expect(app(PlatformArchive::class)->run()['skipped'])->toBe(0);
})->with(['run', 'metrics', 'live']);

it('leaves an ad of a launch that went live alone (retired launches keep their ads)', function () {
    $w = LaunchWorld::make();
    $l = LaunchWorld::launch($w, LaunchState::Stopped);
    AdPublication::where('ad_launch_id', $l->id)->update(['archived_at' => now()]);

    expect(app(PlatformArchive::class)->run())->toMatchArray(['archived' => 0, 'skipped' => 1])->and(fakeAdCalls())->toBe([]);
});

it('waits while CRM writes are switched off, without spending an attempt', function () {
    $w = LaunchWorld::make();
    rejectedLaunch($w);
    config(['crm.ads.write.enabled' => false]);

    expect(app(PlatformArchive::class)->run())->toMatchArray(['archived' => 0, 'waiting' => 1])->and(fakeAdCalls())->toBe([]);
    expect(AdWriteAction::where('type', PlatformArchive::TYPE)->value('attempts'))->toBe(0);

    config(['crm.ads.write.enabled' => true]);
    expect(WriteSwitch::enabled())->toBeTrue()->and(app(PlatformArchive::class)->run()['archived'])->toBe(1);
});

it('retries with back-off after a platform error, then fails for good with the Ads Manager link', function () {
    $w = LaunchWorld::make();
    $l = rejectedLaunch($w);
    FakeAdsDriver::failNext('setStatus', 'unreachable_before', 10);
    FakeAdsDriver::failNext('readObject', 'none', 10);
    $archive = app(PlatformArchive::class);

    expect($archive->run())->toMatchArray(['archived' => 0, 'waiting' => 1]);
    $x = AdWriteAction::where('type', PlatformArchive::TYPE)->sole();
    expect($x->state)->toBe(AdWriteAction::EXECUTING)->and($x->attempts)->toBe(1)
        ->and($x->retry_at->between(now()->addMinutes(59), now()->addMinutes(61)))->toBeTrue();

    // not due yet: nothing happens
    expect($archive->run()['waiting'])->toBe(1)->and(AdWriteAction::find($x->id)->attempts)->toBe(1);

    foreach (range(2, PlatformArchive::MAX_ATTEMPTS) as $n) {
        $this->travelTo(AdWriteAction::find($x->id)->retry_at->addSecond());
        $archive->run();
    }
    $x->refresh();
    expect($x->state)->toBe(AdWriteAction::FAILED)->and($x->attempts)->toBe(PlatformArchive::MAX_ATTEMPTS)
        ->and($x->outcome['deep_link'] ?? null)->toContain('selected_ad_ids=')
        ->and(AdsAuditLog::where('subject_id', $l->id)->where('action', 'launch.platform_archive_failed')->count())->toBe(1);
});

it('a lost answer is settled by the read-back: archived on the platform counts as done', function () {
    $w = LaunchWorld::make();
    rejectedLaunch($w);
    FakeAdsDriver::failNext('setStatus', 'unreachable_after');

    expect(app(PlatformArchive::class)->run()['archived'])->toBe(1)
        ->and(AdWriteAction::where('type', PlatformArchive::TYPE)->value('state'))->toBe(AdWriteAction::SUCCEEDED);
});

it('keeps refusing live writers outside the production sandbox (WriteGuard)', function () {
    $w = LaunchWorld::make();
    rejectedLaunch($w);
    config(['crm.ads.drivers.meta' => 'live']);

    expect(app(PlatformArchive::class)->run())->toMatchArray(['archived' => 0, 'waiting' => 1])
        ->and(AdWriteAction::where('type', PlatformArchive::TYPE)->value('error_code'))->toBe('sandbox_only');
});

it('lists the archive in the write log as ARCHIVED, never as a Stop', function () {
    $w = LaunchWorld::make();
    rejectedLaunch($w);
    app(PlatformArchive::class)->run();

    $rows = app(WriteActionLog::class)->rows($w['manager']);
    expect($rows)->toHaveCount(1)->and($rows[0]['to_status'])->toBe('ARCHIVED')->and($rows[0]['source'])->toBe('launch_archive');
});
