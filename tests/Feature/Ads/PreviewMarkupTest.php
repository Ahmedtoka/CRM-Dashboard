<?php

use App\Ads\Platforms\Data\CreativeMedia;
use App\Ads\Platforms\Fake\FakeAdsDriver;
use App\Ads\Platforms\PreviewMarkup;
use App\Ads\Sync\AdsSyncService;
use App\Models\Ad;
use App\Models\AdAccount;
use App\Models\AdDailyMetric;
use Illuminate\Support\Facades\Http;

const FB_PREVIEW = 'https://www.facebook.com/ads/api/preview_iframe.php?d=abc&t=1';

it('accepts only https facebook.com / fb.com hosts', function (string $url, bool $ok) {
    expect(PreviewMarkup::allowedUrl($url))->toBe($ok);
})->with([
    [FB_PREVIEW, true],
    ['https://facebook.com/x', true],
    ['https://business.fb.com/x', true],
    ['http://www.facebook.com/x', false],
    ['https://evilfacebook.com/x', false],
    ['https://facebook.com.evil.test/x', false],
    ['https://user:pw@www.facebook.com/x', false],
    ['javascript:alert(1)', false],
    ['//www.facebook.com/x', false],
]);

it('extracts the first iframe src and rebuilds a single iframe', function () {
    $html = '<iframe src="https://www.facebook.com/ads/api/preview_iframe.php?d=abc&amp;t=1" width="540" height="690" onload="x()" scrolling="yes"></iframe>';

    expect(PreviewMarkup::iframeSrc($html))->toBe(FB_PREVIEW)
        ->and(PreviewMarkup::singleIframe($html))
        ->toBe('<iframe src="https://www.facebook.com/ads/api/preview_iframe.php?d=abc&amp;t=1" width="540" height="690"></iframe>');
});

it('drops markup that is not exactly one allowed iframe', function (string $html, ?string $src) {
    expect(PreviewMarkup::singleIframe($html))->toBeNull()
        ->and(PreviewMarkup::iframeSrc($html))->toBe($src);
})->with([
    'script beside' => ['<script>parent.document.cookie</script><iframe src="'.FB_PREVIEW.'"></iframe>', FB_PREVIEW],
    'two iframes' => ['<iframe src="'.FB_PREVIEW.'"></iframe><iframe src="'.FB_PREVIEW.'"></iframe>', FB_PREVIEW],
    'text beside' => ['hello <iframe src="'.FB_PREVIEW.'"></iframe>', FB_PREVIEW],
    'wrapped' => ['<div><iframe src="'.FB_PREVIEW.'"></iframe></div>', FB_PREVIEW],
    'foreign host' => ['<iframe src="https://evil.test/x"></iframe>', null],
    'srcdoc only' => ['<iframe srcdoc="<script>x()</script>"></iframe>', null],
    'javascript src' => ['<iframe src="javascript:alert(1)"></iframe>', null],
    'no iframe' => ['<img src=x onerror=alert(1)>', null],
]);

/** A driver that returns the given preview markup for every ad. */
function previewDriver(?string $html, ?string $url = null): void
{
    app()->bind(FakeAdsDriver::class, fn () => new class($html, $url) extends FakeAdsDriver
    {
        public function __construct(private ?string $html, private ?string $url) {}

        public function creativeMedia(AdAccount $a, array $ids): array
        {
            return array_map(fn ($id) => new CreativeMedia($id, previewUrl: $this->url, previewHtml: $this->html), $ids);
        }
    });
}

function adWithRecentMetrics(): Ad
{
    $acc = AdAccount::factory()->meta()->create();
    $ad = Ad::factory()->for($acc, 'account')->create(['preview_html' => '<iframe src="https://old.test"></iframe>', 'preview_url' => 'https://old.test']);
    AdDailyMetric::factory()->create(['ad_id' => $ad->id]);

    return $ad;
}

it('stores only a host-checked src and a rebuilt iframe when syncing media', function () {
    Http::preventStrayRequests();
    config(['crm.ads.drivers.meta' => 'fake']);
    $ad = adWithRecentMetrics();
    previewDriver('<iframe src="https://www.facebook.com/ads/api/preview_iframe.php?d=abc&amp;t=1" width="540" height="690" onload="x()"></iframe>');

    app(AdsSyncService::class)->refreshCreatives($ad->account, 14);

    $ad->refresh();
    expect($ad->preview_url)->toBe(FB_PREVIEW)
        ->and($ad->preview_html)->toBe('<iframe src="https://www.facebook.com/ads/api/preview_iframe.php?d=abc&amp;t=1" width="540" height="690"></iframe>');
});

it('clears preview html and a foreign url when the markup is not a single allowed iframe', function () {
    Http::preventStrayRequests();
    config(['crm.ads.drivers.meta' => 'fake']);
    $ad = adWithRecentMetrics();
    previewDriver('<script>steal()</script><iframe src="https://evil.test/x"></iframe>', 'https://evil.test/x');

    app(AdsSyncService::class)->refreshCreatives($ad->account, 14);

    $ad->refresh();
    expect($ad->preview_html)->toBeNull()->and($ad->preview_url)->toBeNull();
});
