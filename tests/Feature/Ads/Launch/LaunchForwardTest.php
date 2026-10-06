<?php

use App\Ads\AdsSettings;
use App\Ads\Control\Jobs\PublishAd;
use App\Ads\Control\PublicationLinker;
use App\Ads\Control\PublishService;
use App\Ads\Launch\LaunchSettings;
use App\Ads\Launch\LaunchState;
use App\Ads\Sync\SyncAdAccount;
use App\Models\Ad;
use App\Models\AdLaunch;
use App\Models\AdPublication;
use App\Models\ProductVariant;
use App\Models\UserNotification;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use Tests\Support\LaunchWorld;

beforeEach(fn () => LaunchWorld::boot());

/** Two captions on the video: 2 ads. */
function lfReview(array $w): AdLaunch
{
    return LaunchWorld::launch($w, LaunchState::BuyerReview, ['captions' => [LaunchWorld::caption(1), LaunchWorld::caption(2)]]);
}

/** What the next sync does for the CRM-made ads: local rows, then the linker. */
function lfSync(array $w): void
{
    foreach (AdPublication::whereNotNull('external_ad_id')->get() as $p) {
        Ad::factory()->for($w['account'], 'account')->create(['external_id' => $p->external_ad_id, 'ad_set_id' => $w['adset']->id, 'ad_campaign_id' => $w['campaign']->id, 'status' => 'PAUSED', 'effective_status' => 'PAUSED']);
    }
    app(PublicationLinker::class)->link($w['account']);
}

it('TC-01 (first half): forward creates the PAUSED ads through the publish path, then awaits approval once synced', function () {
    Queue::fake([SyncAdAccount::class]); // PublishAd runs inline (sync queue); the follow-up sync is ours to simulate
    $w = LaunchWorld::make();
    $l = lfReview($w);

    $this->actingAs($w['buyerUser'])->postJson("/ads/launches/{$l->public_id}/forward", ['revision' => 1])->assertOk();

    $rows = AdPublication::where('ad_launch_id', $l->id)->get();
    expect($rows)->toHaveCount(2)->and($rows->pluck('status')->unique()->all())->toBe(['done'])
        ->and($rows->pluck('link')->unique()->all())->toBe([$l->link])
        ->and($rows[0]->idempotency_key)->toBe('launch-'.$l->public_id.'-r2')
        ->and(collect(Cache::get('ads-fake-writer')['ads'])->pluck('status')->unique()->all())->toBe(['paused'])
        ->and($l->fresh()->state)->toBe(LaunchState::CreatingPaused)->and($l->fresh()->forwarded_by_id)->toBe($w['buyerUser']->id)
        ->and(UserNotification::where('type', 'ads.launch.forwarded')->where('user_id', $w['content']->id)->count())->toBe(1);

    lfSync($w);

    $l->refresh();
    expect($l->state)->toBe(LaunchState::AwaitingApproval)->and($l->awaiting_at)->not->toBeNull()
        ->and($l->expires_at->diffInDays($l->awaiting_at, true))->toBeGreaterThanOrEqual(6.99)
        ->and($l->checks_hash)->toHaveLength(64)
        ->and(UserNotification::where('type', 'ads.launch.awaiting_approval')->pluck('user_id')->sort()->values()->all())
        ->toBe(collect([$w['manager']->id, $w['admin']->id])->sort()->values()->all());
});

it('uses launch.expiry_days for the deadline', function () {
    Queue::fake([SyncAdAccount::class]);
    app(AdsSettings::class)->set(LaunchSettings::EXPIRY_KEY, 3);
    $w = LaunchWorld::make();
    $l = lfReview($w);
    $this->actingAs($w['buyerUser'])->postJson("/ads/launches/{$l->public_id}/forward", ['revision' => 1])->assertOk();
    lfSync($w);

    expect((int) round($l->fresh()->awaiting_at->diffInHours($l->fresh()->expires_at, true)))->toBe(72);
});

it('refuses forward from content or another buyer, with writes off, or with a blocking check', function () {
    Queue::fake();
    $w = LaunchWorld::make();
    $l = lfReview($w);

    $this->actingAs($w['content'])->postJson("/ads/launches/{$l->public_id}/forward", ['revision' => 1])->assertForbidden();
    app(AdsSettings::class)->set('writes_enabled', false);
    $this->actingAs($w['buyerUser'])->postJson("/ads/launches/{$l->public_id}/forward", ['revision' => 1])->assertStatus(503)->assertJsonPath('code', 'writes_disabled');
    app(AdsSettings::class)->set('writes_enabled', true);
    ProductVariant::query()->update(['inventory_quantity' => 0]);
    $this->actingAs($w['buyerUser'])->postJson("/ads/launches/{$l->public_id}/forward", ['revision' => 1])->assertStatus(422)->assertJsonPath('code', 'checks_failed');

    expect(AdPublication::count())->toBe(0)->and($l->fresh()->state)->toBe(LaunchState::BuyerReview);
});

it('resolves the page identity: the remembered one, else the first the platform lists (A10)', function () {
    Queue::fake();
    $w = LaunchWorld::make();
    $l = lfReview($w);
    $this->actingAs($w['buyerUser'])->postJson("/ads/launches/{$l->public_id}/forward", ['revision' => 1])->assertOk();
    expect($l->fresh()->identity['page_id'])->toBe('fake_page_1');

    app(AdsSettings::class)->set(PublishService::identityKey($w['account']), ['page_id' => 'page_saved', 'page_name' => 'Saved', 'instagram_id' => null]);
    $m = LaunchWorld::launch($w, LaunchState::BuyerReview, ['captions' => [LaunchWorld::caption(5)]]);
    $this->actingAs($w['buyerUser'])->postJson("/ads/launches/{$m->public_id}/forward", ['revision' => 1])->assertOk();
    expect($m->fresh()->identity['page_id'])->toBe('page_saved');
});

it('E1: a failed ad makes the launch create_failed; retry re-queues only ads never requested', function () {
    Queue::fake([PublishAd::class, SyncAdAccount::class]);
    $w = LaunchWorld::make();
    $l = lfReview($w);
    $this->actingAs($w['buyerUser'])->postJson("/ads/launches/{$l->public_id}/forward", ['revision' => 1])->assertOk();
    [$a, $b] = AdPublication::where('ad_launch_id', $l->id)->orderBy('id')->get()->all();

    $a->update(['status' => AdPublication::DONE, 'external_ad_id' => 'x1']);
    $b->update(['status' => AdPublication::ERROR, 'error' => 'Invalid parameter']);

    expect($l->fresh()->state)->toBe(LaunchState::CreateFailed)->and($l->fresh()->last_error)->toBe('Invalid parameter')
        ->and(UserNotification::where('type', 'ads.launch.create_failed')->count())->toBe(2);

    $this->actingAs($w['buyerUser'])->postJson("/ads/launches/{$l->public_id}/retry")->assertOk()->assertJsonPath('launch.state', 'creating_paused');
    expect($b->fresh()->status)->toBe('queued')->and($b->fresh()->open_key)->not->toBeNull();
    Queue::assertPushed(PublishAd::class, fn (PublishAd $j) => $j->publicationId === $b->id);

    $b->fresh()->update(['status' => AdPublication::ERROR, 'error' => 'timeout', 'ad_requested_at' => now()]);
    expect($l->fresh()->state)->toBe(LaunchState::CreateFailed);
    $this->actingAs($w['buyerUser'])->postJson("/ads/launches/{$l->public_id}/retry")->assertStatus(422)->assertJsonPath('code', 'retry_not_possible');
});

it('O7: only an admin publishes directly; buyers go through launches', function () {
    Queue::fake();
    $w = LaunchWorld::make();
    $body = ['account_id' => $w['account']->id, 'campaign_id' => 'c1', 'adset_id' => 's1', 'identity' => ['page_id' => 'fake_page_1'],
        'file_ids' => [$w['files'][0]->id], 'captions' => [LaunchWorld::caption()]];

    $this->actingAs($w['buyerUser'])->postJson("/ads/materials/{$w['material']->id}/publish", $body, ['Idempotency-Key' => 'k-11111111'])->assertForbidden();
    $this->actingAs($w['manager'])->postJson("/ads/materials/{$w['material']->id}/publish", $body, ['Idempotency-Key' => 'k-22222222'])->assertForbidden();
    $this->actingAs($w['admin'])->postJson("/ads/materials/{$w['material']->id}/publish", $body, ['Idempotency-Key' => 'k-33333333'])->assertOk();
    expect(AdPublication::whereNull('ad_launch_id')->count())->toBe(1);
});
