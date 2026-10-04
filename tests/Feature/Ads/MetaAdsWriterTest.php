<?php

use App\Ads\Platforms\AdsApiException;
use App\Ads\Platforms\Data\AdDraft;
use App\Ads\Platforms\Data\Identity;
use App\Ads\Platforms\Data\MediaRef;
use App\Ads\Platforms\Meta\MetaAdsWriter;
use App\Ads\Platforms\MissingPermission;
use App\Ads\Platforms\RateLimited;
use App\Models\AdAccount;
use App\Models\AdMaterialFile;
use App\Models\AdPlatformConnection;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

function writerAccount(): AdAccount
{
    $c = AdPlatformConnection::factory()->meta()->create(['credentials' => ['access_token' => 'tok']]);

    return AdAccount::factory()->meta()->create(['external_id' => 'act_9', 'connection_id' => $c->id]);
}

function draft(MediaRef $media, string $adset = 'as1'): AdDraft
{
    return new AdDraft($adset, 'M5 | Reel | C1', new Identity('p1', 'Page', 'ig1'), $media, 'primary', 'headline', 'SHOP_NOW', 'https://shop.test/p', 'utm_source=meta&utm_content={{ad.id}}', 'https://cdn.test/t.jpg');
}

it('lists live campaigns with ad sets and identities', function () {
    Http::preventStrayRequests();
    Http::fake([
        'graph.facebook.com/v23.0/act_9/campaigns*' => Http::response(['data' => [
            ['id' => 'c1', 'name' => 'Sales', 'status' => 'ACTIVE', 'objective' => 'OUTCOME_SALES', 'adsets' => ['data' => [['id' => 'as1', 'name' => 'Set 1', 'status' => 'PAUSED']]]],
            ['id' => 'c2', 'name' => 'Empty', 'status' => 'PAUSED'],
        ]]),
        'graph.facebook.com/v23.0/act_9/promote_pages*' => Http::response(['data' => [
            ['id' => 'p1', 'name' => 'Le Voile', 'instagram_business_account' => ['id' => 'ig1']],
            ['id' => 'p2', 'name' => 'Other'],
        ]]),
    ]);
    $w = app(MetaAdsWriter::class);
    $acc = writerAccount();

    $c = $w->liveCampaigns($acc);
    expect($c)->toHaveCount(2)
        ->and($c[0]->adSets)->toBe([['id' => 'as1', 'name' => 'Set 1', 'status' => 'PAUSED']])
        ->and($c[1]->adSets)->toBe([]);
    $i = $w->identities($acc);
    expect($i[0]->instagramId)->toBe('ig1')->and($i[1]->instagramId)->toBeNull();

    Http::assertSent(fn (Request $r) => str_contains($r->url(), '/campaigns') && str_contains(urldecode($r->url()), 'effective_status=["ACTIVE","PAUSED"]')
        && ! str_contains($r->url(), 'access_token') && $r->hasHeader('Authorization', 'Bearer tok'));
});

it('uploads a video in chunks: start, two transfers, finish', function () {
    Http::preventStrayRequests();
    Storage::fake('local');
    Storage::disk('local')->put('m/v.mp4', '0123456789');
    $file = new AdMaterialFile(['disk' => 'local', 'path' => 'm/v.mp4', 'mime' => 'video/mp4', 'size' => 10, 'original_name' => 'v.mp4']);
    Http::fake([
        'graph.facebook.com/v23.0/act_9/advideos' => Http::sequence()
            ->push(['upload_session_id' => 'sess', 'video_id' => 'vid1', 'start_offset' => '0', 'end_offset' => '6'])
            ->push(['start_offset' => '6', 'end_offset' => '10'])
            ->push(['start_offset' => '10', 'end_offset' => '10'])
            ->push(['success' => true]),
    ]);

    $ref = app(MetaAdsWriter::class)->uploadMedia(writerAccount(), $file);

    expect($ref->kind)->toBe('video')->and($ref->id)->toBe('vid1')->and($ref->ready)->toBeFalse();
    $sent = Http::recorded()->map(fn ($p) => $p[0])->values();
    expect($sent)->toHaveCount(4);
    expect($sent[0]->body())->toContain('upload_phase=start')->toContain('file_size=10');
    foreach ([1 => ['0', '012345'], 2 => ['6', '6789']] as $n => [$offset, $bytes]) {
        $body = $sent[$n]->body();
        expect($body)->toContain('upload_phase')->toContain('transfer')->toContain('sess')->toContain($bytes)->toContain('video_file_chunk');
        expect($body)->toMatch('/name="start_offset"\r\nContent-Length: \d+\r\n\r\n'.$offset.'\r\n/');
    }
    expect($sent[2]->body())->not->toContain('012345');
    expect($sent[3]->body())->toContain('upload_phase=finish')->toContain('upload_session_id=sess');
});

it('uploads an image and returns its hash, which is always ready', function () {
    Http::preventStrayRequests();
    Storage::fake('local');
    Storage::disk('local')->put('m/i.jpg', 'imgbytes');
    $file = new AdMaterialFile(['disk' => 'local', 'path' => 'm/i.jpg', 'mime' => 'image/jpeg', 'original_name' => 'i.jpg']);
    Http::fake(['graph.facebook.com/v23.0/act_9/adimages' => Http::response(['images' => ['i.jpg' => ['hash' => 'abc123']]])]);

    $w = app(MetaAdsWriter::class);
    $acc = writerAccount();
    $ref = $w->uploadMedia($acc, $file);

    expect($ref->kind)->toBe('image')->and($ref->id)->toBe('abc123')->and($ref->ready)->toBeTrue()
        ->and($w->mediaReady($acc, $ref))->toBeTrue();
});

it('checks video readiness from status.video_status', function () {
    Http::preventStrayRequests();
    Http::fake(['graph.facebook.com/v23.0/vid1*' => Http::sequence()
        ->push(['status' => ['video_status' => 'processing']])
        ->push(['status' => ['video_status' => 'ready']])]);
    $w = app(MetaAdsWriter::class);
    $acc = writerAccount();
    $ref = new MediaRef('video', 'vid1', false);

    expect($w->mediaReady($acc, $ref))->toBeFalse()->and($w->mediaReady($acc, $ref))->toBeTrue();
});

it('creates the creative then a PAUSED ad with url_tags and returns the ad id', function () {
    Http::preventStrayRequests();
    Http::fake([
        'graph.facebook.com/v23.0/act_9/adcreatives' => Http::response(['id' => 'cr1']),
        'graph.facebook.com/v23.0/act_9/ads' => Http::response(['id' => 'ad77']),
    ]);

    $id = app(MetaAdsWriter::class)->createPausedAd(writerAccount(), draft(new MediaRef('video', 'vid1', true)));

    expect($id)->toBe('ad77');
    $sent = Http::recorded()->map(fn ($p) => $p[0])->values();
    expect($sent[0]->url())->toContain('/adcreatives');
    $creative = $sent[0]->data();
    expect($creative['url_tags'])->toBe('utm_source=meta&utm_content={{ad.id}}');
    $spec = json_decode($creative['object_story_spec'], true);
    expect($spec['page_id'])->toBe('p1')->and($spec['instagram_user_id'])->toBe('ig1')
        ->and($spec['video_data'])->toMatchArray([
            'video_id' => 'vid1', 'image_url' => 'https://cdn.test/t.jpg', 'message' => 'primary', 'title' => 'headline',
            'call_to_action' => ['type' => 'SHOP_NOW', 'value' => ['link' => 'https://shop.test/p']],
        ]);
    expect($sent[1]->url())->toContain('/ads');
    $ad = $sent[1]->data();
    expect($ad['status'])->toBe('PAUSED')->and($ad['adset_id'])->toBe('as1')
        ->and(json_decode($ad['creative'], true))->toBe(['creative_id' => 'cr1']);
});

it('builds link_data for an image creative', function () {
    Http::preventStrayRequests();
    Http::fake([
        'graph.facebook.com/v23.0/act_9/adcreatives' => Http::response(['id' => 'cr2']),
        'graph.facebook.com/v23.0/act_9/ads' => Http::response(['id' => 'ad78']),
    ]);

    app(MetaAdsWriter::class)->createPausedAd(writerAccount(), draft(new MediaRef('image', 'hash9', true)));

    $spec = json_decode(Http::recorded()->first()[0]->data()['object_story_spec'], true);
    expect($spec['link_data'])->toMatchArray(['image_hash' => 'hash9', 'link' => 'https://shop.test/p', 'message' => 'primary', 'name' => 'headline'])
        ->and($spec)->not->toHaveKey('video_data');
});

it('sets status with a POST to the object id', function () {
    Http::preventStrayRequests();
    Http::fake(['graph.facebook.com/v23.0/ad77' => Http::response(['success' => true])]);

    app(MetaAdsWriter::class)->setStatus(writerAccount(), 'ad', 'ad77', 'paused');

    Http::assertSent(fn (Request $r) => $r->method() === 'POST' && str_ends_with($r->url(), '/v23.0/ad77') && $r->data()['status'] === 'PAUSED');
});

it('maps a missing ads_management permission to MissingPermission', function () {
    Http::preventStrayRequests();
    Http::fake(['graph.facebook.com/*' => Http::response(['error' => ['message' => '(#200) Requires ads_management permission to manage the object', 'code' => 200]], 403)]);

    app(MetaAdsWriter::class)->setStatus(writerAccount(), 'ad', 'ad77', 'active');
})->throws(MissingPermission::class, 'ads_management');

it('keeps rate-limit codes as RateLimited and other errors as AdsApiException', function () {
    Http::preventStrayRequests();
    Http::fake(['graph.facebook.com/v23.0/ad1' => Http::response(['error' => ['message' => 'Too many calls', 'code' => 17]], 400)]);
    $w = app(MetaAdsWriter::class);
    $acc = writerAccount();

    expect(fn () => $w->setStatus($acc, 'ad', 'ad1', 'paused'))->toThrow(RateLimited::class);

    Http::fake(['graph.facebook.com/v23.0/ad2' => Http::response(['error' => ['message' => 'Bad thing', 'code' => 100]], 400)]);
    expect(fn () => $w->setStatus($acc, 'ad', 'ad2', 'paused'))->toThrow(AdsApiException::class, 'Bad thing');
});
