<?php

use App\Ads\Control\Jobs\PublishAd;
use App\Ads\Control\PublicationLinker;
use App\Ads\Control\PublishService;
use App\Ads\Platforms\AdsApiException;
use App\Ads\Platforms\CreativeRejected;
use App\Ads\Platforms\Data\AdDraft;
use App\Ads\Platforms\Data\MediaRef;
use App\Ads\Platforms\DriverFactory;
use App\Ads\Platforms\Fake\FakeAdsDriver;
use App\Ads\Platforms\MissingPermission;
use App\Ads\Platforms\RateLimited;
use App\Ads\Sync\QueueInspector;
use App\Ads\Sync\SyncAdAccount;
use App\Enums\UserRole;
use App\Models\Ad;
use App\Models\AdAccount;
use App\Models\AdAccountAssignment;
use App\Models\AdMaterial;
use App\Models\AdMaterialFile;
use App\Models\AdPlatformConnection;
use App\Models\AdPublication;
use App\Models\BotSetting;
use App\Models\MediaBuyer;
use App\Models\Product;
use App\Models\User;
use Illuminate\Queue\MaxAttemptsExceededException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Illuminate\Validation\ValidationException;

beforeEach(function () {
    Http::preventStrayRequests();
    Cache::forget('ads-fake-writer');
    config(['crm.ads.drivers.meta' => 'fake', 'crm.ads.drivers.tiktok' => 'fake', 'crm.ads.drivers.google' => 'fake']);
    $this->withoutVite();
});

function pubSetup(array $mime = ['video/mp4']): array
{
    $acc = AdAccount::factory()->meta()->create(['name' => 'LV Main']);
    $product = Product::factory()->create(['handle' => 'silk-abaya']);
    $material = AdMaterial::factory()->create(['product_id' => $product->id, 'types' => ['reel'], 'title' => 'Silk reel', 'content_notes' => 'Soft silk abaya']);
    $files = array_map(fn ($m) => AdMaterialFile::factory()->create(['ad_material_id' => $material->id, 'mime' => $m]), $mime);

    return [$acc, $material, $files];
}

function pubInput(array $fileIds, int $captions = 3, array $over = []): array
{
    return $over + [
        'campaign_id' => 'c1', 'campaign_name' => 'LV | Abaya | Sales | Ali | 261004',
        'adset_id' => 's1', 'adset_name' => 'Broad | EG | Advantage+',
        'identity' => ['page_id' => 'fake_page_1', 'page_name' => 'Le Voile', 'instagram_id' => 'fake_ig_1'],
        'file_ids' => $fileIds,
        'captions' => array_map(fn ($i) => ['headline' => "H{$i}", 'primary_text' => "Text {$i}", 'cta' => 'SHOP_NOW'], range(1, $captions)),
    ];
}

function pubKey(): array
{
    return ['Idempotency-Key' => (string) Str::uuid()];
}

function pubBuyer(AdAccount $acc): User
{
    $user = User::factory()->create(['role' => UserRole::MediaBuyer]);
    $buyer = MediaBuyer::factory()->create(['user_id' => $user->id]);
    AdAccountAssignment::factory()->create(['ad_account_id' => $acc->id, 'media_buyer_id' => $buyer->id, 'starts_on' => '2026-01-01', 'ends_on' => null]);

    return $user;
}

function pubRun(AdPublication $row): void
{
    (new PublishAd($row->id))->handle(app(DriverFactory::class));
}

/** One queued publication for a fresh setup (nothing dispatched). */
function pubOne(): array
{
    [$acc, $material, $files] = pubSetup();
    $admin = User::factory()->create(['role' => UserRole::Admin]);
    Queue::fake();
    $row = app(PublishService::class)->publish($admin, $material, $acc, pubInput([$files[0]->id], 1))->first();

    return [$row, $acc, $material, $files, $admin];
}

it('creates one queued row per file and caption with names, link and url tags', function () {
    Queue::fake();
    [$acc, $material, $files] = pubSetup();
    $admin = User::factory()->create(['role' => UserRole::Admin]);

    $rows = app(PublishService::class)->publish($admin, $material, $acc, pubInput([$files[0]->id]));

    expect($rows)->toHaveCount(3)
        ->and($rows->pluck('ad_name')->all())->toBe(['M'.$material->id.' | Reel | C1', 'M'.$material->id.' | Reel | C2', 'M'.$material->id.' | Reel | C3'])
        ->and($rows->pluck('status')->unique()->all())->toBe(['queued'])
        ->and($rows[0]->link)->toBe(rtrim(BotSetting::current()->storeUrl(), '/').'/products/silk-abaya')
        ->and($rows[0]->url_tags)->toBe('utm_source=meta&utm_medium=paid&utm_campaign={{campaign.name}}&utm_content={{ad.id}}')
        ->and($rows[1]->headline)->toBe('H2')->and($rows[0]->caption_index)->toBe(1)->and($rows[0]->created_by_id)->toBe($admin->id);
    Queue::assertPushed(PublishAd::class, 3);
});

it('uses the material website link when there is no product and rejects when there is neither', function () {
    Queue::fake();
    [$acc, $material, $files] = pubSetup();
    $admin = User::factory()->create(['role' => UserRole::Admin]);
    $material->update(['product_id' => null, 'website_links' => ['https://lv.example/p/1']]);

    $rows = app(PublishService::class)->publish($admin, $material->fresh(), $acc, pubInput([$files[0]->id], 1));
    expect($rows[0]->link)->toBe('https://lv.example/p/1');

    $material->update(['website_links' => null]);
    expect(fn () => app(PublishService::class)->publish($admin, $material->fresh(), $acc, pubInput([$files[0]->id], 1)))
        ->toThrow(ValidationException::class);
});

it('uses TikTok url tags for a TikTok account and Image for an image without a type', function () {
    Queue::fake();
    [, $material, $files] = pubSetup(['image/jpeg']);
    $material->update(['types' => []]);
    $tt = AdAccount::factory()->tiktok()->create();
    $admin = User::factory()->create(['role' => UserRole::Admin]);

    $rows = app(PublishService::class)->publish($admin, $material->fresh(), $tt, pubInput([$files[0]->id], 1));

    expect($rows[0]->url_tags)->toBe('utm_source=tiktok&utm_medium=paid&utm_campaign=__CAMPAIGN_NAME__&utm_content=__CID__')
        ->and($rows[0]->ad_name)->toBe('M'.$material->id.' | Image | C1');
});

it('runs the job to done with a fake ad id and queues a recent sync', function () {
    [$row, $acc, , $files, $admin] = pubOne();

    Queue::fake(); // only what the job itself queues from here on
    pubRun($row);

    $row->refresh();
    expect($row->status)->toBe('done')->and($row->external_ad_id)->toStartWith('fake_ad_')->and($row->attempts)->toBe(1)
        ->and($files[0]->fresh()->platform_media[(string) $acc->id])->toHaveKey('id');
    Queue::assertPushed(SyncAdAccount::class, fn ($j) => $j->accountId === $acc->id && $j->days === 3 && $j->kind === 'recent' && $j->triggeredById === $admin->id);
    $state = Cache::get('ads-fake-writer');
    expect($state['ads'][$row->external_ad_id]['name'])->toBe($row->ad_name)->and($state['ads'][$row->external_ad_id]['status'])->toBe('paused');
});

it('reuses the uploaded media of a file for the next ad on the same account', function () {
    [$acc, $material, $files] = pubSetup();
    $admin = User::factory()->create(['role' => UserRole::Admin]);
    Queue::fake();
    $rows = app(PublishService::class)->publish($admin, $material, $acc, pubInput([$files[0]->id], 2));
    foreach ($rows as $r) {
        pubRun($r);
    }

    expect(Cache::get('ads-fake-writer')['media'])->toBe(1)->and(AdPublication::pluck('status')->unique()->all())->toBe(['done']);
});

it('releases the job while the platform is still processing the video', function () {
    [$row] = pubOne();
    $double = new class extends FakeAdsDriver
    {
        public function uploadMedia(AdAccount $a, AdMaterialFile $file): MediaRef
        {
            return new MediaRef('video', 'v1', false);
        }

        public function mediaReady(AdAccount $a, MediaRef $ref): bool
        {
            return false;
        }
    };
    app()->bind(FakeAdsDriver::class, fn () => $double);

    $job = (new PublishAd($row->id))->withFakeQueueInteractions();
    $job->handle(app(DriverFactory::class));

    $job->assertReleased(60);
    expect($row->fresh()->status)->toBe('processing');
});

it('records a readable error when the platform refuses', function () {
    [$row] = pubOne();
    $double = new class extends FakeAdsDriver
    {
        public function createPausedAd(AdAccount $a, AdDraft $draft): string
        {
            throw new MissingPermission('Token lacks ads_management');
        }
    };
    app()->bind(FakeAdsDriver::class, fn () => $double);

    pubRun($row);

    $row->refresh();
    expect($row->status)->toBe('error')->and($row->error)->toContain('ads_management')->and($row->external_ad_id)->toBeNull();
});

it('releases for 15 minutes on a rate limit and keeps the row retryable', function () {
    [$row] = pubOne();
    $double = new class extends FakeAdsDriver
    {
        public function uploadMedia(AdAccount $a, AdMaterialFile $file): MediaRef
        {
            throw new RateLimited('slow down');
        }
    };
    app()->bind(FakeAdsDriver::class, fn () => $double);

    $job = (new PublishAd($row->id))->withFakeQueueInteractions();
    $job->handle(app(DriverFactory::class));

    $job->assertReleased(900);
    expect($row->fresh()->status)->toBe('uploading');
});

it('does not repeat a finished run when the same job is delivered again', function () {
    [$row] = pubOne();
    $job = new PublishAd($row->id);
    $job->handle(app(DriverFactory::class));
    $job->handle(app(DriverFactory::class));

    expect(Cache::get('ads-fake-writer')['n'])->toBe(1)->and($row->fresh()->attempts)->toBe(1);
});

it('links the created ad to the material when the sync brings it in, once', function () {
    [$row, $acc, $material] = pubOne();
    pubRun($row);
    expect($material->ads()->count())->toBe(0);

    $ad = Ad::factory()->for($acc, 'account')->create(['external_id' => $row->fresh()->external_ad_id]);
    app(PublicationLinker::class)->link($acc);

    expect($material->ads()->pluck('ads.id')->all())->toBe([$ad->id])->and($row->fresh()->linked_at)->not->toBeNull();

    $material->ads()->detach(); // a buyer unlinks it: the next sync must not bring it back
    app(PublicationLinker::class)->link($acc);
    expect($material->ads()->count())->toBe(0);
});

it('lets a media buyer publish only into an own account and content never', function () {
    Queue::fake();
    [$acc, $material, $files] = pubSetup();
    $other = AdAccount::factory()->meta()->create();
    $buyer = pubBuyer($acc);
    $content = User::factory()->create(['role' => UserRole::Content]);
    $body = pubInput([$files[0]->id], 1);

    $this->actingAs($buyer)->postJson("/ads/materials/{$material->id}/publish", $body + ['account_id' => $acc->id], pubKey())->assertOk();
    $this->actingAs($buyer)->postJson("/ads/materials/{$material->id}/publish", $body + ['account_id' => $other->id], pubKey())->assertForbidden();
    $this->actingAs($content)->postJson("/ads/materials/{$material->id}/publish", $body + ['account_id' => $acc->id], pubKey())->assertForbidden();
    expect(AdPublication::count())->toBe(1);
});

it('rejects files that belong to another material', function () {
    Queue::fake();
    [$acc, $material] = pubSetup();
    [, , $foreign] = pubSetup();
    $admin = User::factory()->create(['role' => UserRole::Admin]);

    $this->actingAs($admin)->postJson("/ads/materials/{$material->id}/publish", pubInput([$foreign[0]->id], 1) + ['account_id' => $acc->id], pubKey())->assertStatus(422);
});

it('remembers the identity used per account and preselects it next time', function () {
    Queue::fake();
    [$acc, $material, $files] = pubSetup();
    $admin = User::factory()->create(['role' => UserRole::Admin]);

    $this->actingAs($admin)->postJson("/ads/materials/{$material->id}/publish", pubInput([$files[0]->id], 1) + ['account_id' => $acc->id], pubKey())->assertOk()
        ->assertJsonPath('publications.0.status', 'queued');

    $this->actingAs($admin)->getJson("/ads/publish/options?account={$acc->id}")->assertOk()->assertJsonPath('last_identity.page_id', 'fake_page_1');
});

it('serves accounts, then campaigns with ad sets and identities from the writer', function () {
    $acc = AdAccount::factory()->meta()->create(['name' => 'LV Main']);
    Ad::factory()->for($acc, 'account')->create();
    $admin = User::factory()->create(['role' => UserRole::Admin]);
    $buyer = pubBuyer($acc);
    $content = User::factory()->create(['role' => UserRole::Content]);

    $this->actingAs($admin)->getJson('/ads/publish/options')->assertOk()->assertJsonPath('accounts.0.id', $acc->id);
    $this->actingAs($admin)->getJson("/ads/publish/options?account={$acc->id}")->assertOk()
        ->assertJsonStructure(['campaigns' => [['id', 'name', 'adsets' => [['id', 'name']]]], 'identities' => [['page_id', 'page_name', 'instagram_id']]])
        ->assertJsonPath('identities.0.page_id', 'fake_page_1');
    $this->actingAs($buyer)->getJson("/ads/publish/options?account={$acc->id}")->assertOk();
    $this->actingAs($content)->getJson("/ads/publish/options?account={$acc->id}")->assertForbidden();
    $this->actingAs($content)->getJson('/ads/publish/options')->assertForbidden();
});

it('lists the publications of a material with an Ads Manager link for a created Meta ad', function () {
    [$row, $acc, $material] = pubOne();
    pubRun($row);
    $num = preg_replace('/^act_/', '', $acc->external_id);
    $admin = User::factory()->create(['role' => UserRole::Admin]);

    $this->actingAs($admin)->getJson("/ads/materials/{$material->id}/publications")->assertOk()
        ->assertJsonPath('data.0.status', 'done')
        ->assertJsonPath('data.0.manager_url', "https://adsmanager.facebook.com/adsmanager/manage/ads?act={$num}&selected_ad_ids={$row->fresh()->external_ad_id}");
    $this->actingAs(User::factory()->create(['role' => UserRole::Content]))->getJson("/ads/materials/{$material->id}/publications")->assertForbidden();
});

it('decodes a queued PublishAd in the queue inspector', function () {
    [$row, $acc] = pubOne();
    $payload = json_encode(['displayName' => PublishAd::class, 'attempts' => 0, 'data' => ['command' => serialize(new PublishAd($row->id))]]);

    $rows = (new QueueInspector(fn () => [[$payload], []]))->waiting();

    expect($rows[0]['job'])->toBe('PublishAd')->and($rows[0]['account_id'])->toBe($acc->id)->and($rows[0]['kind'])->toBe('publish');
});

it('never creates the ad again when a previous attempt stopped while creating', function () {
    [$row] = pubOne();
    $row->update(['status' => 'creating', 'ad_requested_at' => now()]);
    $double = new class extends FakeAdsDriver
    {
        public static int $creates = 0;

        public function createPausedAd(AdAccount $a, AdDraft $draft): string
        {
            self::$creates++;

            return 'dup';
        }
    };
    $double::$creates = 0;
    app()->bind(FakeAdsDriver::class, fn () => $double);

    pubRun($row);

    $row->refresh();
    expect($double::$creates)->toBe(0)->and($row->status)->toBe('error')->and($row->error)->toContain($row->ad_name)->and($row->external_ad_id)->toBeNull();
});

it('warns that the ad may already exist when the create call fails', function () {
    [$row] = pubOne();
    $double = new class extends FakeAdsDriver
    {
        public function createPausedAd(AdAccount $a, AdDraft $draft): string
        {
            $draft->adRequestSending();
            throw new AdsApiException('timeout');
        }
    };
    app()->bind(FakeAdsDriver::class, fn () => $double);

    pubRun($row);

    expect($row->fresh()->status)->toBe('error')->and($row->fresh()->error)->toContain('timeout')->and($row->fresh()->error)->toContain(__('ads.publish.create_may_exist'));
});

it('does not add the warning to an upload failure', function () {
    [$row] = pubOne();
    $double = new class extends FakeAdsDriver
    {
        public function uploadMedia(AdAccount $a, AdMaterialFile $file): MediaRef
        {
            throw new AdsApiException('bad file');
        }
    };
    app()->bind(FakeAdsDriver::class, fn () => $double);

    pubRun($row);

    expect($row->fresh()->error)->toBe('bad file');
});

it('numbers captions across files so every ad name is unique', function () {
    Queue::fake();
    [$acc, $material, $files] = pubSetup(['video/mp4', 'video/mp4']);
    $admin = User::factory()->create(['role' => UserRole::Admin]);

    $rows = app(PublishService::class)->publish($admin, $material, $acc, pubInput([$files[0]->id, $files[1]->id], 3));

    $names = $rows->pluck('ad_name')->all();
    expect($names)->toHaveCount(6)->and(array_unique($names))->toHaveCount(6)
        ->and($names[0])->toBe('M'.$material->id.' | Reel | C1')->and($names[5])->toBe('M'.$material->id.' | Reel | C6')
        ->and($rows->pluck('caption_index')->all())->toBe([1, 2, 3, 4, 5, 6]);
});

it('shows the media-not-ready message when the job runs out of attempts', function () {
    [$row] = pubOne();
    $row->update(['status' => 'processing']);

    (new PublishAd($row->id))->failed(new MaxAttemptsExceededException('too many'));

    expect($row->fresh()->status)->toBe('error')->and($row->fresh()->error)->toBe(__('ads.publish.media_not_ready'));
});

it('treats a rate limit at the create step as a possible duplicate: error with the warning, no release', function () {
    [$row] = pubOne();
    $double = new class extends FakeAdsDriver
    {
        public static int $creates = 0;

        public function createPausedAd(AdAccount $a, AdDraft $draft): string
        {
            self::$creates++;
            $draft->adRequestSending();
            throw new RateLimited('usage high');
        }
    };
    $double::$creates = 0;
    app()->bind(FakeAdsDriver::class, fn () => $double);

    $job = (new PublishAd($row->id))->withFakeQueueInteractions();
    $job->handle(app(DriverFactory::class));

    $job->assertNotReleased();
    expect($double::$creates)->toBe(1)->and($row->fresh()->status)->toBe('error')->and($row->fresh()->error)->toContain(__('ads.publish.create_may_exist'));
});

it('adds the may-exist hint when an unexpected error leaves a row in creating', function () {
    [$row] = pubOne();
    $row->update(['status' => 'creating', 'ad_requested_at' => now()]);

    (new PublishAd($row->id))->failed(new RuntimeException('boom'));

    expect($row->fresh()->status)->toBe('error')->and($row->fresh()->error)->toContain('boom')->and($row->fresh()->error)->toContain(__('ads.publish.create_may_exist'));
});

it('ends a row stopped in creating without the warning when the ad request was never sent', function () {
    [$row] = pubOne();
    $row->update(['status' => 'creating', 'ad_requested_at' => null]);

    pubRun($row);

    expect($row->fresh()->status)->toBe('error')->and($row->fresh()->error)->toBe(__('ads.publish.stopped_before_ad'))
        ->and(Cache::get('ads-fake-writer')['n'] ?? 0)->toBe(0);
});

it('does not add the may-exist hint on a crash in creating before the ad request', function () {
    [$row] = pubOne();
    $row->update(['status' => 'creating', 'ad_requested_at' => null]);

    (new PublishAd($row->id))->failed(new RuntimeException('boom'));

    expect($row->fresh()->error)->toBe('boom');
});

it('records a creative-step failure without the may-exist warning and stamps no ad request', function () {
    [$row] = pubOne();
    $double = new class extends FakeAdsDriver
    {
        public function createPausedAd(AdAccount $a, AdDraft $draft): string
        {
            throw CreativeRejected::from(new AdsApiException('Invalid thumbnail'));
        }
    };
    app()->bind(FakeAdsDriver::class, fn () => $double);

    pubRun($row);

    $row->refresh();
    expect($row->status)->toBe('error')->and($row->error)->toBe('Invalid thumbnail')->and($row->ad_requested_at)->toBeNull();
});

it('stamps ad_requested_at right before the ad request and keeps the warning on a failure after it', function () {
    [$row] = pubOne();
    $double = new class extends FakeAdsDriver
    {
        public function createPausedAd(AdAccount $a, AdDraft $draft): string
        {
            $draft->adRequestSending();
            throw new AdsApiException('Meta is unreachable');
        }
    };
    app()->bind(FakeAdsDriver::class, fn () => $double);

    pubRun($row);

    $row->refresh();
    expect($row->ad_requested_at)->not->toBeNull()->and($row->error)->toContain(__('ads.publish.create_may_exist'));
});

it('retries a rate limit before the ad request: back to processing, released for 15 minutes, then created once', function () {
    [$row] = pubOne();
    $double = new class extends FakeAdsDriver
    {
        public static int $calls = 0;

        public function createPausedAd(AdAccount $a, AdDraft $draft): string
        {
            if (self::$calls++ === 0) {
                throw CreativeRejected::from(new RateLimited('usage high'));
            }

            return parent::createPausedAd($a, $draft);
        }
    };
    $double::$calls = 0;
    app()->bind(FakeAdsDriver::class, fn () => $double);

    $job = (new PublishAd($row->id))->withFakeQueueInteractions();
    $job->handle(app(DriverFactory::class));

    $job->assertReleased(900);
    expect($row->fresh()->status)->toBe('processing')->and($row->fresh()->error)->toBeNull();

    $retry = (new PublishAd($row->id))->withFakeQueueInteractions();
    $retry->handle(app(DriverFactory::class));

    expect($row->fresh()->status)->toBe('done')->and(Cache::get('ads-fake-writer')['n'])->toBe(1);
});

it('passes the local poster of a video to the writer', function () {
    [$row, , , $files] = pubOne();
    $files[0]->update(['thumb_path' => 'ad-materials/thumbs/p.jpg', 'disk' => 'local']);
    $double = new class extends FakeAdsDriver
    {
        public static ?AdDraft $draft = null;

        public function createPausedAd(AdAccount $a, AdDraft $draft): string
        {
            self::$draft = $draft;

            return parent::createPausedAd($a, $draft);
        }
    };
    app()->bind(FakeAdsDriver::class, fn () => $double);

    pubRun($row);

    expect($double::$draft->posterDisk)->toBe('local')->and($double::$draft->posterPath)->toBe('ad-materials/thumbs/p.jpg');
});

it('releases for a minute while another worker holds the upload lock, keeping the row uploading', function () {
    config(['crm.ads.publish_upload_wait' => 1]);
    [$row, $acc] = pubOne();
    $held = Cache::lock("ads-media:{$row->ad_material_file_id}:{$acc->id}", 900);
    expect($held->get())->toBeTrue();

    $job = (new PublishAd($row->id))->withFakeQueueInteractions();
    $job->handle(app(DriverFactory::class));

    $job->assertReleased(60);
    expect($row->fresh()->status)->toBe('uploading')->and($row->fresh()->error)->toBeNull();
    $held->release();
});

it('drops a cached media ref the platform reports as failed so the next publish re-uploads', function () {
    [$row, $acc, , $files] = pubOne();
    $other = (string) ($acc->id + 1000);
    $files[0]->update(['platform_media' => [(string) $acc->id => ['kind' => 'video', 'id' => 'v_old'], $other => ['kind' => 'video', 'id' => 'v_x']]]);
    $double = new class extends FakeAdsDriver
    {
        public function mediaReady(AdAccount $a, MediaRef $ref): bool
        {
            throw new AdsApiException('Meta could not process the video.');
        }
    };
    app()->bind(FakeAdsDriver::class, fn () => $double);

    pubRun($row);

    expect($row->fresh()->status)->toBe('error')->and($row->fresh()->error)->toContain('could not process')
        ->and($files[0]->fresh()->platform_media)->toBe([$other => ['kind' => 'video', 'id' => 'v_x']]);
});

it('refuses at run time when the account was switched off or its connection disabled, without a platform call', function () {
    [$row, $acc] = pubOne();
    $acc->update(['is_active' => false]);
    pubRun($row);
    expect($row->fresh()->status)->toBe('error')->and($row->fresh()->error)->toBe(__('ads.publish.account_inactive'));

    [$row2, $acc2] = pubOne();
    AdPlatformConnection::whereKey($acc2->connection_id)->update(['status' => 'disabled']);
    pubRun($row2);
    expect($row2->fresh()->error)->toBe(__('ads.publish.account_inactive'))
        ->and(Cache::get('ads-fake-writer')['media'] ?? 0)->toBe(0)->and(Cache::get('ads-fake-writer')['n'] ?? 0)->toBe(0);
});

it('keeps the publication when its material, file or account is deleted', function () {
    [$row, $acc, $material, $files] = pubOne();
    pubRun($row);

    $files[0]->delete();
    expect($row->fresh())->not->toBeNull()->and($row->fresh()->ad_material_file_id)->toBeNull();
    $material->delete();
    expect($row->fresh()->ad_material_id)->toBeNull();
    $acc->delete();
    expect($row->fresh()->ad_account_id)->toBeNull()->and($row->fresh()->ad_name)->not->toBe('')->and($row->fresh()->status)->toBe('done');
});

it('ends a queued row whose file was deleted with a readable error', function () {
    [$row, , , $files] = pubOne();
    $files[0]->delete();

    pubRun($row);

    expect($row->fresh()->status)->toBe('error')->and($row->fresh()->error)->toBe(__('ads.publish.file_gone'));
});

function pubPost($test, User $user, AdMaterial $material, array $body, ?string $key = 'key-0000-aaaa'): TestResponse
{
    return $test->actingAs($user)->postJson("/ads/materials/{$material->id}/publish", $body, $key === null ? [] : ['Idempotency-Key' => $key]);
}

it('replays the same user + key: one set of rows, same ids, one job per row', function () {
    Queue::fake();
    [$acc, $material, $files] = pubSetup(['video/mp4', 'video/mp4']);
    $admin = User::factory()->create(['role' => UserRole::Admin]);
    $body = pubInput([$files[0]->id, $files[1]->id], 2) + ['account_id' => $acc->id];

    $first = pubPost($this, $admin, $material, $body)->assertOk();
    $second = pubPost($this, $admin, $material, $body)->assertOk();

    expect(AdPublication::count())->toBe(4)
        ->and(collect($second->json('publications'))->pluck('id')->all())->toBe(collect($first->json('publications'))->pluck('id')->all());
    Queue::assertPushed(PublishAd::class, 4);
});

it('answers 409 duplicate_in_flight for another key with the same file, caption and ad set', function () {
    Queue::fake();
    [$acc, $material, $files] = pubSetup();
    $admin = User::factory()->create(['role' => UserRole::Admin]);
    $body = pubInput([$files[0]->id], 1) + ['account_id' => $acc->id];

    $first = pubPost($this, $admin, $material, $body, 'key-0000-aaaa')->assertOk();
    pubPost($this, $admin, $material, $body, 'key-0000-bbbb')->assertStatus(409)->assertJsonPath('code', 'duplicate_in_flight')
        ->assertJsonPath('publications.0.id', $first->json('publications.0.id'));

    expect(AdPublication::count())->toBe(1);
    Queue::assertPushed(PublishAd::class, 1);
});

it('lets allow_duplicate publish again with open_key null', function () {
    Queue::fake();
    [$acc, $material, $files] = pubSetup();
    $admin = User::factory()->create(['role' => UserRole::Admin]);
    $body = pubInput([$files[0]->id], 1) + ['account_id' => $acc->id];

    pubPost($this, $admin, $material, $body, 'key-0000-aaaa')->assertOk();
    pubPost($this, $admin, $material, $body + ['allow_duplicate' => true], 'key-0000-bbbb')->assertOk();

    $second = AdPublication::orderByDesc('id')->first();
    expect(AdPublication::count())->toBe(2)->and($second->open_key)->toBeNull()->and($second->allow_duplicate)->toBeTrue();
});

it('releases the open key when a publication errors, so the same ad can be published again', function () {
    Queue::fake();
    [$acc, $material, $files] = pubSetup();
    $admin = User::factory()->create(['role' => UserRole::Admin]);
    $body = pubInput([$files[0]->id], 1) + ['account_id' => $acc->id];

    pubPost($this, $admin, $material, $body, 'key-0000-aaaa')->assertOk();
    $row = AdPublication::first();
    expect($row->open_key)->not->toBeNull();
    $row->update(['status' => AdPublication::ERROR, 'error' => 'x']);
    expect($row->fresh()->open_key)->toBeNull();

    pubPost($this, $admin, $material, $body, 'key-0000-bbbb')->assertOk();
    expect(AdPublication::count())->toBe(2);
});

it('ads:clear-open-keys releases done rows older than 24 h only', function () {
    Queue::fake();
    [$acc, $material, $files] = pubSetup();
    $admin = User::factory()->create(['role' => UserRole::Admin]);
    $a = pubPost($this, $admin, $material, pubInput([$files[0]->id], 1) + ['account_id' => $acc->id], 'key-0000-aaaa')->json('publications.0.id');
    $b = pubPost($this, $admin, $material, pubInput([$files[0]->id], 1, ['adset_id' => 's2']) + ['account_id' => $acc->id], 'key-0000-bbbb')->json('publications.0.id');
    AdPublication::whereKey($a)->update(['status' => 'done', 'updated_at' => now()->subHours(25)]);
    AdPublication::whereKey($b)->update(['status' => 'done', 'updated_at' => now()->subHour()]);

    $this->artisan('ads:clear-open-keys')->assertSuccessful();

    expect(AdPublication::find($a)->open_key)->toBeNull()->and(AdPublication::find($b)->open_key)->not->toBeNull();
});

it('refuses a publish without a valid Idempotency-Key header', function () {
    Queue::fake();
    [$acc, $material, $files] = pubSetup();
    $admin = User::factory()->create(['role' => UserRole::Admin]);
    $body = pubInput([$files[0]->id], 1) + ['account_id' => $acc->id];

    pubPost($this, $admin, $material, $body, null)->assertStatus(422)->assertJsonValidationErrors(['idempotency_key']);
    pubPost($this, $admin, $material, $body, 'short')->assertStatus(422);
    expect(AdPublication::count())->toBe(0);
});

it('stays safe after the cache is flushed: a finished row is never created twice, a half-created row never re-creates', function () {
    [$row] = pubOne();
    $double = new class extends FakeAdsDriver
    {
        public static int $creates = 0;

        public function createPausedAd(AdAccount $a, AdDraft $draft): string
        {
            self::$creates++;

            return parent::createPausedAd($a, $draft);
        }
    };
    $double::$creates = 0;
    app()->bind(FakeAdsDriver::class, fn () => $double);

    $first = new PublishAd($row->id);
    $second = clone $first; // a Redis re-delivery: same payload, same runKey
    $first->handle(app(DriverFactory::class));
    expect($row->fresh()->status)->toBe('done')->and($double::$creates)->toBe(1);

    Cache::flush(); // a deploy, a Redis restart: the done mark and locks are gone
    $second->handle(app(DriverFactory::class));
    expect($double::$creates)->toBe(1)->and($row->fresh()->status)->toBe('done');

    // a row left in creating (request sent) with the cache flushed: error with the warning, no create call
    [$stuck] = pubOne();
    $stuck->update(['status' => 'creating', 'ad_requested_at' => now()]);
    Cache::flush();
    (new PublishAd($stuck->id))->handle(app(DriverFactory::class));
    expect($double::$creates)->toBe(1)->and($stuck->fresh()->status)->toBe('error')
        ->and($stuck->fresh()->error)->toBe(__('ads.publish.stopped_creating', ['name' => $stuck->ad_name]));
});

it('keeps the open key on an error row whose ad request was sent, and clear-open-keys releases it after 24 h', function () {
    Queue::fake();
    [$acc, $material, $files] = pubSetup();
    $admin = User::factory()->create(['role' => UserRole::Admin]);
    $body = pubInput([$files[0]->id], 1) + ['account_id' => $acc->id];
    $id = pubPost($this, $admin, $material, $body, 'key-0000-aaaa')->json('publications.0.id');

    AdPublication::find($id)->update(['status' => AdPublication::ERROR, 'error' => 'timeout', 'ad_requested_at' => now()]);
    expect(AdPublication::find($id)->open_key)->not->toBeNull();
    pubPost($this, $admin, $material, $body, 'key-0000-bbbb')->assertStatus(409);

    $this->artisan('ads:clear-open-keys')->assertSuccessful();
    expect(AdPublication::find($id)->open_key)->not->toBeNull();

    AdPublication::whereKey($id)->update(['updated_at' => now()->subHours(25)]);
    $this->artisan('ads:clear-open-keys')->assertSuccessful();
    expect(AdPublication::find($id)->open_key)->toBeNull();
});

it('clear-open-keys releases queued rows nobody touched for 24 h (queue lost) but not fresh ones', function () {
    Queue::fake();
    [$acc, $material, $files] = pubSetup();
    $admin = User::factory()->create(['role' => UserRole::Admin]);
    $a = pubPost($this, $admin, $material, pubInput([$files[0]->id], 1) + ['account_id' => $acc->id], 'key-0000-aaaa')->json('publications.0.id');
    $b = pubPost($this, $admin, $material, pubInput([$files[0]->id], 1, ['adset_id' => 's2']) + ['account_id' => $acc->id], 'key-0000-bbbb')->json('publications.0.id');
    AdPublication::whereKey($a)->update(['updated_at' => now()->subHours(25)]);

    $this->artisan('ads:clear-open-keys')->assertSuccessful();

    expect(AdPublication::find($a)->open_key)->toBeNull()->and(AdPublication::find($b)->open_key)->not->toBeNull();
});

it('rejects two identical captions in one request with a 422', function () {
    Queue::fake();
    [$acc, $material, $files] = pubSetup();
    $admin = User::factory()->create(['role' => UserRole::Admin]);
    $body = pubInput([$files[0]->id], 2) + ['account_id' => $acc->id];
    $body['captions'][1] = ['headline' => ' H1 ', 'primary_text' => 'Text 1 ', 'cta' => 'SHOP_NOW'];

    pubPost($this, $admin, $material, $body)->assertStatus(422)->assertJsonValidationErrors(['captions']);
    expect(AdPublication::count())->toBe(0);
});

it('trims captions before computing the open key', function () {
    Queue::fake();
    [$acc, $material, $files] = pubSetup();
    $admin = User::factory()->create(['role' => UserRole::Admin]);
    $a = pubInput([$files[0]->id], 1) + ['account_id' => $acc->id];
    $b = $a;
    $b['captions'][0]['headline'] = '  H1  ';

    pubPost($this, $admin, $material, $a, 'key-0000-aaaa')->assertOk();
    pubPost($this, $admin, $material, $b, 'key-0000-bbbb')->assertStatus(409);
});

it('fails a stale queued row when clearing its key, so a late PublishAd job never creates a second ad', function () {
    Queue::fake();
    [$acc, $material, $files] = pubSetup();
    $admin = User::factory()->create(['role' => UserRole::Admin]);
    $id = pubPost($this, $admin, $material, pubInput([$files[0]->id], 1) + ['account_id' => $acc->id], 'key-0000-aaaa')->json('publications.0.id');
    AdPublication::whereKey($id)->update(['updated_at' => now()->subHours(25)]);

    $this->artisan('ads:clear-open-keys')->assertSuccessful();

    $row = AdPublication::find($id);
    expect($row->status)->toBe('error')->and($row->open_key)->toBeNull()->and($row->error)->toBe(__('ads.publish.never_sent'));

    $double = new class extends FakeAdsDriver
    {
        public static int $creates = 0;

        public function createPausedAd(AdAccount $a, AdDraft $draft): string
        {
            self::$creates++;

            return parent::createPausedAd($a, $draft);
        }
    };
    $double::$creates = 0;
    app()->bind(FakeAdsDriver::class, fn () => $double);
    (new PublishAd($id))->handle(app(DriverFactory::class));
    expect($double::$creates)->toBe(0)->and($row->fresh()->status)->toBe('error');
});
