<?php

use App\Ads\AdsSettings;
use App\Ads\Control\Write\Jobs\RetryStopWrite;
use App\Ads\Control\Write\WriteActionService;
use App\Ads\Platforms\Fake\FakeAdsDriver;
use App\Enums\UserRole;
use App\Models\Ad;
use App\Models\AdAccount;
use App\Models\AdAccountAssignment;
use App\Models\AdCampaign;
use App\Models\AdsAuditLog;
use App\Models\AdWriteAction;
use App\Models\AdWriteStep;
use App\Models\MediaBuyer;
use App\Models\User;
use App\Models\UserNotification;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    Http::preventStrayRequests();
    Cache::forget('ads-fake-writer');
    FakeAdsDriver::reset();
    Queue::fake();
    config(['crm.ads.drivers.meta' => 'fake', 'crm.ads.drivers.tiktok' => 'fake', 'crm.ads.drivers.google' => 'fake', 'crm.ads.write_sandbox_accounts' => []]);
});

function srBuyer(AdAccount $acc): User
{
    $user = User::factory()->create(['role' => UserRole::MediaBuyer]);
    $buyer = MediaBuyer::factory()->create(['user_id' => $user->id]);
    AdAccountAssignment::factory()->create(['ad_account_id' => $acc->id, 'media_buyer_id' => $buyer->id, 'starts_on' => '2026-01-01', 'ends_on' => null]);

    return $user;
}

function srPropose(User $u, AdAccount $acc, string $level, string $id, string $to): AdWriteAction
{
    return app(WriteActionService::class)->propose($u, $acc, $level, $id, $to, 'test', 'k-'.bin2hex(random_bytes(8)))['action'];
}

function srStatuses(): array
{
    return array_column(Cache::get('ads-fake-writer')['statuses'] ?? [], 'status');
}

/** Runs every pushed RetryStopWrite by hand (QUEUE_CONNECTION=sync would ignore the delay), in push order. */
function srRunJobs(int $from = 0): int
{
    $jobs = Queue::pushed(RetryStopWrite::class)->values();
    for ($i = $from; $i < $jobs->count(); $i++) {
        app()->call([$jobs[$i], 'handle']);
        $jobs = Queue::pushed(RetryStopWrite::class)->values();
    }

    return $jobs->count();
}

it('schedules a retry of a throttled Stop instead of failing it', function () {
    $acc = AdAccount::factory()->meta()->create();
    $ad = Ad::factory()->for($acc, 'account')->create(['status' => 'ACTIVE']);
    $buyer = srBuyer($acc);
    $x = srPropose($buyer, $acc, 'ad', $ad->external_id, 'paused');
    FakeAdsDriver::failNext('setStatus', 'rate', 1);

    $this->actingAs($buyer)->postJson("/ads/write-actions/{$x->public_id}/confirm", ['diff_hash' => $x->diff_hash])
        ->assertStatus(202)->assertJsonPath('action.state', 'executing')->assertJsonPath('message', __('ads.errors.stop_retrying'));

    $x->refresh();
    expect($x->state)->toBe('executing')->and($x->attempts)->toBe(1)->and($x->retry_at)->not->toBeNull()
        ->and($x->retry_at->diffInSeconds(now(), true))->toBeGreaterThanOrEqual(119)
        ->and(AdsAuditLog::where('action', 'write.retry_scheduled')->count())->toBe(1);
    Queue::assertPushedOn('commerce', RetryStopWrite::class, function (RetryStopWrite $job) use ($x) {
        $delay = $job->delay instanceof DateTimeInterface ? now()->diffInSeconds($job->delay) : (int) $job->delay;

        return $job->actionId === $x->id && $job->attempts === 1 && $job->tries === 1 && $delay >= 119;
    });

    srRunJobs();

    $x->refresh();
    expect($x->state)->toBe('succeeded')->and($x->retry_at)->toBeNull()->and(AdWriteStep::where('ad_write_action_id', $x->id)->count())->toBe(2)
        ->and(srStatuses())->toBe(['paused'])->and($ad->fresh()->status)->toBe('PAUSED');
});

it('does nothing when the same retry job is delivered twice', function () {
    $acc = AdAccount::factory()->meta()->create();
    $ad = Ad::factory()->for($acc, 'account')->create();
    $buyer = srBuyer($acc);
    $x = srPropose($buyer, $acc, 'ad', $ad->external_id, 'paused');
    FakeAdsDriver::failNext('setStatus', 'rate', 1);
    app(WriteActionService::class)->confirm($buyer, $x, $x->diff_hash);

    $job = Queue::pushed(RetryStopWrite::class)->first();
    app()->call([$job, 'handle']);
    app()->call([$job, 'handle']);

    expect(srStatuses())->toBe(['paused'])->and(AdWriteStep::count())->toBe(2)->and($x->fresh()->state)->toBe('succeeded');
});

it('fails after 3 throttled attempts with the Ads Manager link and tells every Ads authority holder', function () {
    $acc = AdAccount::factory()->meta()->create(['external_id' => 'act_123456']);
    $ad = Ad::factory()->for($acc, 'account')->create();
    $buyer = srBuyer($acc);
    $holderA = User::factory()->adsAuthority()->create(['role' => UserRole::Admin]);
    $holderB = User::factory()->adsAuthority()->create(['role' => UserRole::Supervisor]);
    $plainSup = User::factory()->create(['role' => UserRole::Supervisor]);
    $x = srPropose($buyer, $acc, 'ad', $ad->external_id, 'paused');
    FakeAdsDriver::failNext('setStatus', 'rate', 3);

    app(WriteActionService::class)->confirm($buyer, $x, $x->diff_hash);
    expect(srRunJobs())->toBe(2);

    $x->refresh();
    expect($x->state)->toBe('failed')->and($x->error_code)->toBe('rate_limited')->and($x->attempts)->toBe(3)
        ->and($x->outcome['deep_link'])->toBe('https://adsmanager.facebook.com/adsmanager/manage/ads?act=123456&selected_ad_ids='.$ad->external_id)
        ->and(srStatuses())->toBe([])
        ->and(UserNotification::where('type', 'ads.stop_failed')->pluck('user_id')->sort()->values()->all())->toBe([$holderA->id, $holderB->id])
        ->and(UserNotification::where('user_id', $plainSup->id)->count())->toBe(0);
    $n = UserNotification::where('user_id', $holderA->id)->sole();
    expect($n->data['action_id'])->toBe($x->public_id)->and($n->data['deep_link'])->toBe($x->outcome['deep_link']);
});

it('fails at once with the link when the regain time is longer than the wait cap', function () {
    $acc = AdAccount::factory()->meta()->create(['external_id' => 'act_77']);
    $camp = AdCampaign::factory()->for($acc, 'account')->create();
    $admin = User::factory()->adsAuthority()->create(['role' => UserRole::Admin]);
    $x = srPropose($admin, $acc, 'campaign', $camp->external_id, 'paused');
    FakeAdsDriver::failNext('setStatus', 'rate:3600');

    $this->actingAs($admin)->postJson("/ads/write-actions/{$x->public_id}/confirm", ['diff_hash' => $x->diff_hash])
        ->assertStatus(429)->assertJsonPath('code', 'rate_limited')
        ->assertJsonPath('details.deep_link', 'https://adsmanager.facebook.com/adsmanager/manage/campaigns?act=77&selected_campaign_ids='.$camp->external_id);

    Queue::assertNothingPushed();
    expect($x->fresh()->state)->toBe('failed')->and(UserNotification::where('type', 'ads.stop_failed')->count())->toBe(1);
});

it('retries a Stop whose read-back says it did not land', function () {
    $acc = AdAccount::factory()->meta()->create();
    $ad = Ad::factory()->for($acc, 'account')->create(['status' => 'ACTIVE']);
    $buyer = srBuyer($acc);
    $x = srPropose($buyer, $acc, 'ad', $ad->external_id, 'paused');
    FakeAdsDriver::failNext('setStatus', 'unreachable_before');

    app(WriteActionService::class)->confirm($buyer, $x, $x->diff_hash);
    expect($x->fresh()->state)->toBe('executing');
    srRunJobs();

    expect($x->fresh()->state)->toBe('succeeded')->and(srStatuses())->toBe(['paused']);
});

it('keeps retrying a confirmed Stop when the kill switch is turned off meanwhile', function () {
    $acc = AdAccount::factory()->meta()->create();
    $ad = Ad::factory()->for($acc, 'account')->create();
    $buyer = srBuyer($acc);
    $x = srPropose($buyer, $acc, 'ad', $ad->external_id, 'paused');
    FakeAdsDriver::failNext('setStatus', 'rate', 1);
    app(WriteActionService::class)->confirm($buyer, $x, $x->diff_hash);

    app(AdsSettings::class)->set('writes_enabled', false);
    srRunJobs();

    expect($x->fresh()->state)->toBe('succeeded')->and(srStatuses())->toBe(['paused']);
});

it('ends the retry failed when the account is switched off for writes meanwhile', function () {
    $acc = AdAccount::factory()->meta()->create();
    $ad = Ad::factory()->for($acc, 'account')->create();
    $buyer = srBuyer($acc);
    $x = srPropose($buyer, $acc, 'ad', $ad->external_id, 'paused');
    FakeAdsDriver::failNext('setStatus', 'rate', 1);
    app(WriteActionService::class)->confirm($buyer, $x, $x->diff_hash);

    $acc->update(['write_enabled' => false]);
    srRunJobs();

    expect($x->fresh()->state)->toBe('failed')->and($x->fresh()->error_code)->toBe('account_not_writable')->and(srStatuses())->toBe([]);
});

it('Stop beats an in-flight Run: the Run is superseded_by_stop and the Stop is re-applied after the Run lands', function () {
    $acc = AdAccount::factory()->meta()->create();
    $ad = Ad::factory()->for($acc, 'account')->create(['status' => 'PAUSED']);
    $buyer = srBuyer($acc);
    $admin = User::factory()->adsAuthority()->create(['role' => UserRole::Admin]);
    $run = srPropose($buyer, $acc, 'ad', $ad->external_id, 'active');
    $stop = null;
    FakeAdsDriver::beforeSetStatus(function () use ($admin, $acc, $ad, &$stop) {
        $stop = srPropose($admin, $acc, 'ad', $ad->external_id, 'paused');
        app(WriteActionService::class)->confirm($admin, $stop, $stop->diff_hash);
    });

    $this->actingAs($buyer)->postJson("/ads/write-actions/{$run->public_id}/confirm", ['diff_hash' => $run->diff_hash])
        ->assertStatus(409)->assertJsonPath('code', 'precondition_failed')->assertJsonPath('action.state', 'superseded_by_stop');

    $run->refresh();
    $stop->refresh();
    expect($run->state)->toBe('superseded_by_stop')->and($run->superseded_by_id)->toBe($stop->id)->and($run->open_business_key)->toBeNull()
        ->and($run->outcome['landed_after_stop'])->toBeTrue()
        ->and($stop->state)->toBe('succeeded')
        ->and(srStatuses())->toBe(['paused', 'active', 'paused'])
        ->and($stop->steps()->count())->toBe(2)->and($stop->steps()->get()->last()->meta['reapply_of'])->toBe($run->public_id)
        ->and(AdsAuditLog::where('action', 'write.stop_reapplied')->count())->toBe(1)
        ->and(AdsAuditLog::where('action', 'write.superseded_by_stop')->where('subject_id', $run->id)->count())->toBe(1)
        ->and($ad->fresh()->status)->toBe('PAUSED');
});

it('supersedes an unknown Run when a Stop on the same target succeeds', function () {
    $acc = AdAccount::factory()->meta()->create();
    $ad = Ad::factory()->for($acc, 'account')->create();
    $buyer = srBuyer($acc);
    $run = AdWriteAction::factory()->run()->unknown()->create([
        'ad_account_id' => $acc->id, 'target_external_id' => $ad->external_id, 'proposed_by_id' => $buyer->id,
        'target_key' => $acc->id.':ad:'.$ad->external_id, 'open_business_key' => 'run:'.$acc->id.':ad:'.$ad->external_id,
    ]);
    $stop = srPropose($buyer, $acc, 'ad', $ad->external_id, 'paused');

    app(WriteActionService::class)->confirm($buyer, $stop, $stop->diff_hash);

    expect($run->fresh()->state)->toBe('superseded_by_stop')->and($run->fresh()->open_business_key)->toBeNull()
        ->and($stop->fresh()->state)->toBe('succeeded');
});

it('resolves an unknown Run by hand: ads:write-resolve --failed --note', function () {
    $acc = AdAccount::factory()->meta()->create();
    $run = AdWriteAction::factory()->run()->unknown()->create(['ad_account_id' => $acc->id]);
    $run->update(['open_business_key' => 'run:'.$run->target_key]);

    $this->artisan('ads:write-resolve', ['public_id' => $run->public_id, '--failed' => true, '--note' => 'checked Ads Manager: still paused'])->assertSuccessful();

    $run->refresh();
    expect($run->state)->toBe('failed')->and($run->open_business_key)->toBeNull()->and($run->finished_at)->not->toBeNull()
        ->and($run->outcome['resolved_by_hand'])->toBe('checked Ads Manager: still paused');
    $audit = AdsAuditLog::where('action', 'write.resolved_by_hand')->sole();
    expect($audit->actor_type)->toBe('cli')->and($audit->meta['note'])->toBe('checked Ads Manager: still paused');
});

it('refuses ads:write-resolve on a finished action, without a note or with both outcomes', function () {
    $done = AdWriteAction::factory()->succeeded()->create();
    $unknown = AdWriteAction::factory()->unknown()->create();
    $fresh = AdWriteAction::factory()->unknown()->create(['state' => 'executing', 'executing_at' => now()]);

    $this->artisan('ads:write-resolve', ['public_id' => $done->public_id, '--failed' => true, '--note' => 'x'])->assertFailed();
    $this->artisan('ads:write-resolve', ['public_id' => $unknown->public_id, '--failed' => true])->assertFailed();
    $this->artisan('ads:write-resolve', ['public_id' => $unknown->public_id, '--failed' => true, '--succeeded' => true, '--note' => 'x'])->assertFailed();
    $this->artisan('ads:write-resolve', ['public_id' => $fresh->public_id, '--succeeded' => true, '--note' => 'x'])->assertFailed();
    $this->artisan('ads:write-resolve', ['public_id' => 'nope', '--failed' => true, '--note' => 'x'])->assertFailed();

    expect($done->fresh()->state)->toBe('succeeded')->and($unknown->fresh()->state)->toBe('unknown')->and($fresh->fresh()->state)->toBe('executing')
        ->and(AdsAuditLog::where('action', 'write.resolved_by_hand')->count())->toBe(0);
});

it('resolves an executing action older than 10 minutes with no pending retry', function () {
    $old = AdWriteAction::factory()->unknown()->create(['state' => 'executing', 'executing_at' => now()->subMinutes(11)]);

    $this->artisan('ads:write-resolve', ['public_id' => $old->public_id, '--succeeded' => true, '--note' => 'paused in Ads Manager'])->assertSuccessful();

    expect($old->fresh()->state)->toBe('succeeded');
});
