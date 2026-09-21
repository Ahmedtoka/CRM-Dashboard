<?php

use App\Bot\Ai\ClaudeAiResponder;
use App\Bot\Ai\MessageClassifier;
use App\Bot\CatalogSearch;
use App\Bot\Grounding\BotContextBuilder;
use App\Bot\Grounding\GovernorateMatcher;
use App\Bot\Grounding\VariantOptions;
use App\Bot\HandoverSignals;
use App\Bot\PriceGuard;
use App\Enums\BotIntent;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\ShippingRate;
use App\Models\ShippingZone;
use App\Models\ShippingZoneRegion;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

it('matches governorates with common spellings', function (string $text, ?string $code) {
    expect(app(GovernorateMatcher::class)->match($text))->toBe($code);
})->with([
    ['الشحن للجيزه بكام', 'GZ'], ['انا من اسكندريه', 'ALX'], ['عايشة في القاهرة', 'C'], ['المنصورة', 'DK'],
    ['6 أكتوبر', 'GZ'], ['from alex', 'ALX'], ['طنطا', 'GH'], ['مفيش محافظة هنا', null],
]);

it('needs a word boundary, a 6th-of-October qualifier and a single governorate', function (string $text, ?string $code) {
    expect(app(GovernorateMatcher::class)->match($text))->toBe($code);
})->with([
    ['في اكتوبر', null], ['هيوصل اكتوبر الجاي؟', null], ['مدينة 6 اكتوبر', 'GZ'], ['٦ أكتوبر', 'GZ'], ['مدينة اكتوبر', 'GZ'],
    // Two different governorates are ambiguous for a shipping quote: no match (documented choice).
    ['من القاهرة للجيزة', null],
    ['بالجيزة', 'GZ'], ['واسكندرية', 'ALX'], ['كلقاهرة', null], ['سقنا', null],
]);

it('does not treat several prices as a phone number', function (string $text, ?string $reason) {
    expect(app(HandoverSignals::class)->detect($text))->toBe($reason);
})->with([
    ['1500 1200 1100', null], ['الفستان 1250 والطقم 1350', null], ['010 0123 4567', 'contact_details'], ['+201001234567', 'contact_details'],
]);

it('caps the catalog search at eight tokens', function () {
    Product::factory()->create(['title' => 'فستان ستان'])->variants()->create(['shopify_id' => '1', 'title' => 'Default', 'price' => 900, 'inventory_quantity' => 1]);
    $queries = [];
    DB::listen(function ($q) use (&$queries) {
        $queries[] = $q;
    });

    app(CatalogSearch::class)->groupedLinesFor('فستان '.implode(' ', array_map(fn ($i) => 'كلمه'.$i, range(1, 30))));

    $products = collect($queries)->first(fn ($q) => str_contains($q->sql, 'from "products"'));
    expect(count(array_filter($products->bindings, fn ($b) => is_string($b) && str_starts_with($b, '%'))))->toBeLessThanOrEqual(32); // 8 tokens x title, type, tags, sku
});

it('uses the configured per-call claude timeouts', function () {
    config(['crm.drivers.ai' => 'claude', 'crm.anthropic.classify_timeout' => 3, 'crm.anthropic.reply_timeout' => 5]);
    $claude = app(MessageClassifier::class);
    $read = fn (string $p) => (new ReflectionProperty($claude, $p))->getValue($claude);

    expect($claude)->toBeInstanceOf(ClaudeAiResponder::class)
        ->and($read('classifyTimeout'))->toBe(3)
        ->and($read('replyTimeout'))->toBe(5);
});

it('parses colour and size from variant titles', function () {
    expect(VariantOptions::parse('أحمر / M'))->toBe(['color' => 'أحمر', 'size' => 'M'])
        ->and(VariantOptions::parse('XL / black'))->toBe(['color' => 'أسود', 'size' => 'XL'])
        ->and(VariantOptions::parse('Default Title'))->toBe(['color' => null, 'size' => null]);
});

it('groups catalog lines per product with colours, sizes, stock and out-of-stock flags', function () {
    $p = Product::factory()->create(['title' => 'فستان ستان']);
    ProductVariant::factory()->for($p)->create(['title' => 'أحمر / S', 'price' => 1250, 'inventory_quantity' => 3]);
    ProductVariant::factory()->for($p)->create(['title' => 'أحمر / M', 'price' => 1250, 'inventory_quantity' => 0]);
    ProductVariant::factory()->for($p)->create(['title' => 'اسود / L', 'price' => 1350, 'inventory_quantity' => 2]);

    expect(app(CatalogSearch::class)->groupedLinesFor('الفستان الستان الاحمر متاح؟'))
        ->toBe(['فستان ستان | 1250 - 1350 جنيه | أحمر: S (3)، M (نفد) | أسود: L (2)']);
});

it('detects handover signals', function (string $text, ?string $reason) {
    expect(app(HandoverSignals::class)->detect($text))->toBe($reason);
})->with([
    ['عاوزة أطلب الفستان ده', 'purchase'], ['احجزيلي واحد', 'purchase'], ['هاخد اتنين', 'purchase'],
    ['رقمي 01001234567', 'contact_details'], ['٠١١٢٣٤٥٦٧٨٩', 'contact_details'], ['العنوان: 5 شارع التحرير', 'contact_details'],
    ['مقاسي ايه؟', 'size_recommendation'], ['وزني 70 كيلو وطولي 165', 'size_recommendation'],
    ['الفستان ده بكام', null], ['جدول المقاسات لو سمحتي', null],
]);

/** A synced Shopify zone for one governorate with one rate. */
function groundingShopifyRate(string $code, float $price): void
{
    $zone = ShippingZone::factory()->create();
    ShippingZoneRegion::factory()->create(['shipping_zone_id' => $zone->id, 'province_code' => $code]);
    ShippingRate::factory()->create(['shipping_zone_id' => $zone->id, 'price' => $price]);
}

it('builds shipping and knowledge grounding by intent', function () {
    groundingShopifyRate('GZ', 60);
    $ctx = app(BotContextBuilder::class)->build('الشحن للجيزة بكام وبياخد قد ايه', BotIntent::Shipping);

    expect($ctx->governorate)->toBe('GZ')
        ->and($ctx->lines[0])->toBe('مصاريف الشحن لـالجيزة: 60 جنيه')
        ->and(collect($ctx->lines)->contains(fn ($l) => str_starts_with($l, '[مدة التوصيل]')))->toBeTrue();
});

it('rejects a reply whose price or fee is not in the grounding', function () {
    groundingShopifyRate('GZ', 60);
    $ctx = app(BotContextBuilder::class)->build('الشحن للجيزة بكام', BotIntent::Shipping);
    $guard = app(PriceGuard::class);

    expect($guard->isSafe('الشحن للجيزة 60 جنيه والتوصيل من 3 لـ 5 أيام', $ctx->lines))->toBeTrue()
        ->and($guard->isSafe('الشحن للجيزة 45 جنيه بس', $ctx->lines))->toBeFalse()
        ->and($guard->isSafe('الاستبدال خلال 30 يوم', $ctx->lines))->toBeFalse();
});

it('classifies a message with claude using a strict json parse and falls back on malformed output', function () {
    Http::preventStrayRequests();
    Http::fakeSequence('api.anthropic.com/*')
        ->push(['content' => [['type' => 'text', 'text' => '{"intent":"shipping","sentiment":"neutral","confidence":0.82}']], 'usage' => ['input_tokens' => 30, 'output_tokens' => 9]])
        ->push(['content' => [['type' => 'text', 'text' => 'مش عارف']], 'usage' => ['input_tokens' => 30, 'output_tokens' => 2]])
        ->push(['content' => [['type' => 'text', 'text' => '{"intent":"refund_now","sentiment":"furious","confidence":0.9}']], 'usage' => []]);

    $claude = new ClaudeAiResponder('sk-test-key', 'claude-haiku-4-5-20251001', 'claude-sonnet-5', 10);

    $ok = $claude->classifyMessage('الشحن لأسيوط بكام');
    $bad = $claude->classifyMessage('...');
    $unknown = $claude->classifyMessage('...');

    expect($ok->intent)->toBe(BotIntent::Shipping)->and($ok->confidence)->toBe(0.82)->and($ok->inputTokens)->toBe(30)
        ->and($bad->intent)->toBe(BotIntent::Other)->and($bad->confidence)->toBe(0.0)->and($bad->model)->toBe('claude-haiku-4-5-20251001')
        ->and($unknown->intent)->toBe(BotIntent::Other)->and($unknown->sentiment)->toBe('neutral');
});
