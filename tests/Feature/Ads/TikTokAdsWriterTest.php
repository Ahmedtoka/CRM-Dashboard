<?php

use App\Ads\Platforms\AdPlatform;
use App\Ads\Platforms\AdsApiException;
use App\Ads\Platforms\CreativeRejected;
use App\Ads\Platforms\Data\AdDraft;
use App\Ads\Platforms\Data\Identity;
use App\Ads\Platforms\Data\MediaRef;
use App\Ads\Platforms\DriverFactory;
use App\Ads\Platforms\MissingPermission;
use App\Ads\Platforms\RateLimited;
use App\Ads\Platforms\TikTok\TikTokAdsWriter;
use App\Models\AdAccount;
use App\Models\AdMaterialFile;
use App\Models\AdPlatformConnection;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

const TTW = 'business-api.tiktok.com/open_api/v1.3/';

function ttWriterAccount(): AdAccount
{
    $c = AdPlatformConnection::factory()->tiktok()->create(['credentials' => ['access_token' => 'tt-secret', 'advertiser_ids' => ['7001']]]);

    return AdAccount::factory()->tiktok()->create(['external_id' => '7001', 'connection_id' => $c->id]);
}

function ttDraft(MediaRef $media): AdDraft
{
    return new AdDraft('9001', 'M5 | Reel | C1', new Identity('id77', 'Le Voile', 'CUSTOMIZED_USER'), $media, 'primary text', 'headline', 'SHOP_NOW', 'https://shop.test/p', 'utm_source=tiktok&utm_medium=paid&utm_campaign=__CAMPAIGN_NAME__&utm_content=__CID__', $media->kind === 'video' ? 'https://cdn.test/cover.jpg' : null);
}

it('maps campaigns and their ad groups to CampaignNode', function () {
    Http::preventStrayRequests();
    Http::fake([
        TTW.'campaign/get/*' => Http::response(['code' => 0, 'data' => ['list' => [
            ['campaign_id' => '501', 'campaign_name' => 'Sales', 'operation_status' => 'ENABLE', 'objective_type' => 'WEB_CONVERSIONS'],
            ['campaign_id' => '502', 'campaign_name' => 'Idle', 'operation_status' => 'DISABLE', 'objective_type' => 'REACH'],
        ], 'page_info' => ['total_page' => 1]]]),
        TTW.'adgroup/get/*' => Http::response(['code' => 0, 'data' => ['list' => [
            ['adgroup_id' => '9001', 'adgroup_name' => 'Set 1', 'campaign_id' => '501', 'operation_status' => 'DISABLE'],
        ], 'page_info' => ['total_page' => 1]]]),
    ]);

    $nodes = app(TikTokAdsWriter::class)->liveCampaigns(ttWriterAccount());

    expect($nodes)->toHaveCount(2)
        ->and($nodes[0]->id)->toBe('501')->and($nodes[0]->status)->toBe('ACTIVE')->and($nodes[0]->objective)->toBe('WEB_CONVERSIONS')
        ->and($nodes[0]->adSets)->toBe([['id' => '9001', 'name' => 'Set 1', 'status' => 'PAUSED']])
        ->and($nodes[1]->status)->toBe('PAUSED')->and($nodes[1]->adSets)->toBe([]);
    Http::assertSent(fn (Request $r) => str_contains($r->url(), 'adgroup/get/') && $r->hasHeader('Access-Token', 'tt-secret')
        && ! str_contains($r->url(), 'tt-secret') && str_contains(urldecode($r->url()), '"campaign_ids":["501","502"]'));
});

it('lists identities', function () {
    Http::preventStrayRequests();
    Http::fake([TTW.'identity/get/*' => Http::response(['code' => 0, 'data' => ['identity_list' => [
        ['identity_id' => 'id77', 'identity_type' => 'CUSTOMIZED_USER', 'display_name' => 'Le Voile'],
    ]]])]);

    $i = app(TikTokAdsWriter::class)->identities(ttWriterAccount());

    expect($i)->toHaveCount(1)->and($i[0]->pageId)->toBe('id77')->and($i[0]->pageName)->toBe('Le Voile')->and($i[0]->instagramId)->toBe('CUSTOMIZED_USER');
});

it('uploads a video as multipart with the md5 signature and returns the video id', function () {
    Http::preventStrayRequests();
    Storage::fake('local');
    Storage::disk('local')->put('m/v.mp4', 'videobytes');
    $file = new AdMaterialFile(['disk' => 'local', 'path' => 'm/v.mp4', 'mime' => 'video/mp4', 'size' => 10, 'original_name' => 'v.mp4']);
    Http::fake([TTW.'file/video/ad/upload/' => Http::response(['code' => 0, 'data' => [['video_id' => 'v_123']]])]);

    $ref = app(TikTokAdsWriter::class)->uploadMedia(ttWriterAccount(), $file);

    expect($ref->kind)->toBe('video')->and($ref->id)->toBe('v_123')->and($ref->ready)->toBeFalse();
    $body = Http::recorded()->first()[0]->body();
    expect($body)->toContain('video_file')->toContain('videobytes')->toContain(md5('videobytes'))
        ->toContain('UPLOAD_BY_FILE')->toContain('7001');
});

it('uploads an image and returns its id as ready', function () {
    Http::preventStrayRequests();
    Storage::fake('local');
    Storage::disk('local')->put('m/i.jpg', 'imgbytes');
    $file = new AdMaterialFile(['disk' => 'local', 'path' => 'm/i.jpg', 'mime' => 'image/jpeg', 'original_name' => 'i.jpg']);
    Http::fake([TTW.'file/image/ad/upload/' => Http::response(['code' => 0, 'data' => ['image_id' => 'img_9']])]);

    $ref = app(TikTokAdsWriter::class)->uploadMedia(ttWriterAccount(), $file);

    expect($ref->kind)->toBe('image')->and($ref->id)->toBe('img_9')->and($ref->ready)->toBeTrue();
    expect(Http::recorded()->first()[0]->body())->toContain(md5('imgbytes'))->toContain('image_file');
});

it('checks video readiness through file/video/ad/info', function () {
    Http::preventStrayRequests();
    Http::fake([TTW.'file/video/ad/info/*' => Http::sequence()
        ->push(['code' => 0, 'data' => ['list' => []]])
        ->push(['code' => 0, 'data' => ['list' => [['video_id' => 'v_123', 'preview_url' => 'https://cdn.test/p.mp4']]]])]);
    $w = app(TikTokAdsWriter::class);
    $acc = ttWriterAccount();
    $ref = new MediaRef('video', 'v_123', false);

    expect($w->mediaReady($acc, $ref))->toBeFalse()->and($w->mediaReady($acc, $ref))->toBeTrue()
        ->and($w->mediaReady($acc, new MediaRef('image', 'i', true)))->toBeTrue();
});

it('creates the ad with operation_status DISABLE and the UTM in the landing page url', function () {
    Http::preventStrayRequests();
    Http::fake([TTW.'file/image/ad/upload/' => Http::response(['code' => 0, 'data' => ['image_id' => 'cover_1']]), TTW.'ad/create/' => Http::response(['code' => 0, 'data' => ['ad_ids' => ['6601']]]), TTW.'ad/status/update/' => Http::response(['code' => 0, 'data' => []])]);

    $id = app(TikTokAdsWriter::class)->createPausedAd(ttWriterAccount(), ttDraft(new MediaRef('video', 'v_123', true)));

    expect($id)->toBe('6601');
    Http::assertSent(function (Request $r) {
        $d = $r->data();
        $c = $d['creatives'][0] ?? [];

        return str_contains($r->url(), 'ad/create/') && $r->hasHeader('Access-Token', 'tt-secret') && $d['operation_status'] === 'DISABLE' && $d['advertiser_id'] === '7001' && $d['adgroup_id'] === '9001'
            && $c['ad_name'] === 'M5 | Reel | C1' && $c['identity_id'] === 'id77' && $c['identity_type'] === 'CUSTOMIZED_USER'
            && $c['video_id'] === 'v_123' && $c['image_ids'] === ['cover_1'] && $c['ad_format'] === 'SINGLE_VIDEO' && $c['ad_text'] === 'primary text' && $c['call_to_action'] === 'SHOP_NOW'
            && $c['landing_page_url'] === 'https://shop.test/p?utm_source=tiktok&utm_medium=paid&utm_campaign=__CAMPAIGN_NAME__&utm_content=__CID__';
    });
});

it('uses image_ids for an image ad and appends utm with & when the link has a query', function () {
    Http::preventStrayRequests();
    Http::fake([TTW.'ad/create/' => Http::response(['code' => 0, 'data' => ['ad_ids' => ['6602']]]), TTW.'ad/status/update/' => Http::response(['code' => 0, 'data' => []])]);
    $draft = ttDraft(new MediaRef('image', 'img_9', true));
    $draft->link = 'https://shop.test/p?v=1';

    app(TikTokAdsWriter::class)->createPausedAd(ttWriterAccount(), $draft);

    $c = Http::recorded()->first()[0]->data()['creatives'][0];
    expect($c['image_ids'])->toBe(['img_9'])->and($c)->not->toHaveKey('video_id')
        ->and($c['landing_page_url'])->toStartWith('https://shop.test/p?v=1&utm_source=tiktok');
});

it('fails when TikTok returns no ad id', function () {
    Http::preventStrayRequests();
    Http::fake([TTW.'file/image/ad/upload/' => Http::response(['code' => 0, 'data' => ['image_id' => 'cover_1']]), TTW.'ad/create/' => Http::response(['code' => 0, 'data' => []])]);

    app(TikTokAdsWriter::class)->createPausedAd(ttWriterAccount(), ttDraft(new MediaRef('video', 'v', true)));
})->throws(AdsApiException::class, 'ad id');

it('posts the status update for each level with ENABLE or DISABLE', function (string $level, string $path, string $key, string $status, string $expected) {
    Http::preventStrayRequests();
    Http::fake([TTW.$path => Http::response(['code' => 0, 'data' => []])]);

    app(TikTokAdsWriter::class)->setStatus(ttWriterAccount(), $level, '4401', $status);

    Http::assertSent(fn (Request $r) => $r->method() === 'POST' && str_contains($r->url(), $path) && $r->data() === [
        'advertiser_id' => '7001', $key => ['4401'], 'operation_status' => $expected,
    ]);
})->with([
    ['adset', 'adgroup/status/update/', 'adgroup_ids', 'paused', 'DISABLE'],
    ['ad', 'ad/status/update/', 'ad_ids', 'active', 'ENABLE'],
    ['campaign', 'campaign/status/update/', 'campaign_ids', 'paused', 'DISABLE'],
]);

it('rejects an unknown level and a non-numeric id before any request', function () {
    Http::preventStrayRequests();
    $w = app(TikTokAdsWriter::class);
    $acc = ttWriterAccount();

    expect(fn () => $w->setStatus($acc, 'account', '4401', 'paused'))->toThrow(AdsApiException::class)
        ->and(fn () => $w->setStatus($acc, 'ad', '44/../x', 'paused'))->toThrow(AdsApiException::class);
    Http::assertNothingSent();
});

it('raises AdsApiException with the message for a non-zero envelope code, scrubbed', function () {
    Http::preventStrayRequests();
    Http::fake([TTW.'ad/status/update/' => Http::response(['code' => 40002, 'message' => 'Invalid ad id for token tt-secret'])]);

    try {
        app(TikTokAdsWriter::class)->setStatus(ttWriterAccount(), 'ad', '4401', 'paused');
        $this->fail('expected AdsApiException');
    } catch (AdsApiException $e) {
        expect($e)->not->toBeInstanceOf(RateLimited::class)->and($e->getMessage())->toContain('Invalid ad id')->not->toContain('tt-secret');
    }
});

it('keeps the rate-limit code as RateLimited', function () {
    Http::preventStrayRequests();
    Http::fake([TTW.'ad/status/update/' => Http::response(['code' => 40100, 'message' => 'Too many requests'])]);

    app(TikTokAdsWriter::class)->setStatus(ttWriterAccount(), 'ad', '4401', 'paused');
})->throws(RateLimited::class);

it('returns the TikTok writer from the factory in live mode and the fake otherwise', function () {
    config(['crm.ads.drivers.tiktok' => 'live']);
    expect(app(DriverFactory::class)->writer(AdPlatform::Tiktok))->toBeInstanceOf(TikTokAdsWriter::class);

    config(['crm.ads.drivers.tiktok' => 'fake']);
    expect(app(DriverFactory::class)->writer(AdPlatform::Tiktok))->not->toBeInstanceOf(TikTokAdsWriter::class);
});

it('forces DISABLE after create: create then ad/status/update for the new id, and the creative carries DISABLE too', function () {
    Http::preventStrayRequests();
    Http::fake([
        TTW.'file/image/ad/upload/' => Http::response(['code' => 0, 'data' => ['image_id' => 'cover_1']]),
        TTW.'ad/create/' => Http::response(['code' => 0, 'data' => ['ad_ids' => ['6601']]]),
        TTW.'ad/status/update/' => Http::response(['code' => 0, 'data' => []]),
    ]);

    app(TikTokAdsWriter::class)->createPausedAd(ttWriterAccount(), ttDraft(new MediaRef('video', 'v_123', true)));

    $sent = Http::recorded()->map(fn ($p) => $p[0])->values();
    expect($sent)->toHaveCount(3)
        ->and($sent[0]->url())->toContain('file/image/ad/upload/')
        ->and($sent[0]->data())->toMatchArray(['advertiser_id' => '7001', 'upload_type' => 'UPLOAD_BY_URL', 'image_url' => 'https://cdn.test/cover.jpg'])
        ->and($sent[1]->url())->toContain('ad/create/')
        ->and($sent[1]->data()['creatives'][0]['operation_status'])->toBe('DISABLE')
        ->and($sent[2]->url())->toContain('ad/status/update/')
        ->and($sent[2]->data())->toBe(['advertiser_id' => '7001', 'ad_ids' => ['6601'], 'operation_status' => 'DISABLE']);
});

it('throws with the created ad id when the DISABLE confirmation fails', function () {
    Http::preventStrayRequests();
    Http::fake([
        TTW.'file/image/ad/upload/' => Http::response(['code' => 0, 'data' => ['image_id' => 'cover_1']]),
        TTW.'ad/create/' => Http::response(['code' => 0, 'data' => ['ad_ids' => ['6601']]]),
        TTW.'ad/status/update/' => Http::response(['code' => 40002, 'message' => 'Boom']),
    ]);

    app(TikTokAdsWriter::class)->createPausedAd(ttWriterAccount(), ttDraft(new MediaRef('video', 'v_123', true)));
})->throws(AdsApiException::class, '6601');

it('fails fast without a request when the identity type is missing', function () {
    Http::preventStrayRequests();
    $draft = ttDraft(new MediaRef('video', 'v', true));
    $draft->identity = new Identity('id77', 'Le Voile', null);

    expect(fn () => app(TikTokAdsWriter::class)->createPausedAd(ttWriterAccount(), $draft))->toThrow(AdsApiException::class);
    Http::assertNothingSent();
});

it('inserts the utm query before a url fragment', function () {
    Http::preventStrayRequests();
    Http::fake([
        TTW.'ad/create/' => Http::response(['code' => 0, 'data' => ['ad_ids' => ['6603']]]),
        TTW.'ad/status/update/' => Http::response(['code' => 0, 'data' => []]),
    ]);
    $draft = ttDraft(new MediaRef('image', 'img_9', true));
    $draft->link = 'https://shop.test/p#reviews';

    app(TikTokAdsWriter::class)->createPausedAd(ttWriterAccount(), $draft);

    expect(Http::recorded()->first()[0]->data()['creatives'][0]['landing_page_url'])
        ->toBe('https://shop.test/p?utm_source=tiktok&utm_medium=paid&utm_campaign=__CAMPAIGN_NAME__&utm_content=__CID__#reviews');
});

it('uploads the local poster as the cover of a video ad when there is no thumbnail url', function () {
    Http::preventStrayRequests();
    Storage::fake('local');
    Storage::disk('local')->put('thumbs/p.jpg', 'JPEGDATA');
    Http::fake([
        TTW.'file/image/ad/upload/' => Http::response(['code' => 0, 'data' => ['image_id' => 'cover_p']]),
        TTW.'ad/create/' => Http::response(['code' => 0, 'data' => ['ad_ids' => ['6601']]]),
        TTW.'ad/status/update/' => Http::response(['code' => 0, 'data' => []]),
    ]);
    $draft = ttDraft(new MediaRef('video', 'v_123', true));
    $draft->thumbnailUrl = null;
    $draft->posterDisk = 'local';
    $draft->posterPath = 'thumbs/p.jpg';

    app(TikTokAdsWriter::class)->createPausedAd(ttWriterAccount(), $draft);

    $sent = Http::recorded()->map(fn ($p) => $p[0])->values();
    expect($sent[0]->url())->toContain('file/image/ad/upload/')
        ->and($sent[0]->body())->toContain('UPLOAD_BY_FILE')->toContain(md5('JPEGDATA'))->toContain('image_file')
        ->and($sent[1]->data()['creatives'][0]['image_ids'])->toBe(['cover_p']);
});

it('uses the cover TikTok made for the video when there is no thumbnail url and no poster', function () {
    Http::preventStrayRequests();
    Http::fake([
        TTW.'file/video/ad/info/*' => Http::response(['code' => 0, 'data' => ['list' => [['video_id' => 'v_123', 'video_cover_url' => 'https://tt.test/cover.jpg']]]]),
        TTW.'file/image/ad/upload/' => Http::response(['code' => 0, 'data' => ['image_id' => 'cover_tt']]),
        TTW.'ad/create/' => Http::response(['code' => 0, 'data' => ['ad_ids' => ['6601']]]),
        TTW.'ad/status/update/' => Http::response(['code' => 0, 'data' => []]),
    ]);
    $draft = ttDraft(new MediaRef('video', 'v_123', true));
    $draft->thumbnailUrl = null;

    app(TikTokAdsWriter::class)->createPausedAd(ttWriterAccount(), $draft);

    Http::assertSent(fn (Request $r) => str_contains($r->url(), 'file/image/ad/upload/') && ($r->data()['image_url'] ?? null) === 'https://tt.test/cover.jpg');
    Http::assertSent(fn (Request $r) => str_contains($r->url(), 'ad/create/') && $r->data()['creatives'][0]['image_ids'] === ['cover_tt']);
});

it('rejects a video ad without any cover before ad/create and never stamps the ad request', function () {
    Http::preventStrayRequests();
    Http::fake([TTW.'file/video/ad/info/*' => Http::response(['code' => 0, 'data' => ['list' => []]])]);
    $draft = ttDraft(new MediaRef('video', 'v_123', true));
    $draft->thumbnailUrl = null;
    $stamped = false;
    $draft->beforeAdRequest = function () use (&$stamped) {
        $stamped = true;
    };

    expect(fn () => app(TikTokAdsWriter::class)->createPausedAd(ttWriterAccount(), $draft))->toThrow(CreativeRejected::class, 'cover');
    expect($stamped)->toBeFalse();
    Http::assertNotSent(fn (Request $r) => str_contains($r->url(), 'ad/create/'));
});

it('stamps the ad request right before ad/create', function () {
    Http::preventStrayRequests();
    Http::fake([TTW.'ad/create/' => Http::response(['code' => 50000, 'message' => 'Internal error'])]);
    $draft = ttDraft(new MediaRef('image', 'img_9', true));
    $stamped = false;
    $draft->beforeAdRequest = function () use (&$stamped) {
        $stamped = true;
    };

    try {
        app(TikTokAdsWriter::class)->createPausedAd(ttWriterAccount(), $draft);
        $this->fail('expected AdsApiException');
    } catch (AdsApiException $e) {
        expect($e)->not->toBeInstanceOf(CreativeRejected::class);
    }
    expect($stamped)->toBeTrue();
});

it('maps TikTok permission codes to MissingPermission with the platform text', function (int $code) {
    Http::preventStrayRequests();
    Http::fake([TTW.'ad/status/update/' => Http::response(['code' => $code, 'message' => 'No permission to operate this advertiser'])]);

    try {
        app(TikTokAdsWriter::class)->setStatus(ttWriterAccount(), 'ad', '4401', 'paused');
        $this->fail('expected MissingPermission');
    } catch (MissingPermission $e) {
        expect($e->getMessage())->toBe('No permission to operate this advertiser');
    }
})->with([40001, 40002]);
