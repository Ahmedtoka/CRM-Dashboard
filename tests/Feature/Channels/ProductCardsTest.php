<?php

use App\Bot\Catalog\ProductCards;
use App\Channels\Adapters\InstagramAdapter;
use App\Channels\Adapters\MessengerAdapter;
use App\Channels\Adapters\WhatsAppAdapter;
use App\Enums\Platform;
use App\Models\ChannelAccount;
use App\Models\CustomerIdentity;
use App\Models\Product;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    Http::preventStrayRequests();
    config(['crm.drivers.channels' => 'live']);
});

function productCardsFor(int $count): array
{
    $products = collect(range(1, $count))->map(function (int $i) {
        $p = Product::factory()->create(['title' => "فستان {$i}", 'handle' => "dress-{$i}", 'status' => 'active', 'image_url' => "https://cdn.shopify.com/s/files/dress-{$i}.webp?v=1"]);
        $p->variants()->create(['shopify_id' => uniqid('v'), 'title' => 'أسود / M', 'price' => 900 + $i, 'inventory_quantity' => 2]);

        return $p->load('variants');
    });

    return app(ProductCards::class)->cards($products);
}

it('builds a picture card per product with a jpeg image, the page link and a details postback', function () {
    $cards = productCardsFor(2);

    expect($cards['type'])->toBe('generic')
        ->and($cards['cards'][0]['image_url'])->toBe('https://cdn.shopify.com/s/files/dress-1.webp?v=1&width=800&format=jpg')
        ->and($cards['cards'][0]['url'])->toEndWith('/products/dress-1')
        ->and($cards['cards'][0]['subtitle'])->toBe('901 جنيه · متوفر ✅')
        ->and($cards['cards'][0]['buttons'][1]['type'])->toBe('postback')
        ->and($cards['cards'][0]['buttons'][1]['payload'])->toStartWith('product:');
});

it('sends the product carousel as a generic template with pictures on messenger and instagram', function (string $adapter, Platform $platform) {
    Http::fake(['graph.facebook.com/*' => Http::response(['message_id' => 'm.1'])]);
    $account = ChannelAccount::factory()->create(['platform' => $platform, 'driver' => 'live', 'credentials' => ['access_token' => 'tok']]);
    $to = CustomerIdentity::factory()->create(['platform' => $platform, 'external_id' => 'PSID']);

    app($adapter)->sendText($account, $to, 'fallback', ['cards' => productCardsFor(3)]);

    Http::assertSent(function ($r) {
        $el = $r['message']['attachment']['payload']['elements'] ?? [];

        return count($el) === 3
            && str_contains($el[0]['image_url'], 'format=jpg')
            && $el[0]['default_action']['type'] === 'web_url'
            && $el[0]['buttons'][0]['type'] === 'web_url'
            && $el[0]['buttons'][1]['type'] === 'postback';
    });
})->with([[MessengerAdapter::class, Platform::Facebook], [InstagramAdapter::class, Platform::Instagram]]);

it('sends the product carousel as a whatsapp media carousel with one link button per card', function () {
    Http::fake(['graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.1']]])]);
    $account = ChannelAccount::factory()->create(['platform' => Platform::WhatsApp, 'driver' => 'live', 'external_id' => 'PHONE', 'credentials' => ['access_token' => 'tok']]);
    $to = CustomerIdentity::factory()->create(['platform' => Platform::WhatsApp, 'external_id' => '201001234567']);

    app(WhatsAppAdapter::class)->sendText($account, $to, 'fallback', ['cards' => productCardsFor(2)]);

    Http::assertSent(fn ($r) => ($r['interactive']['type'] ?? null) === 'carousel'
        && count($r['interactive']['action']['cards']) === 2
        && $r['interactive']['action']['cards'][1]['card_index'] === 1
        && $r['interactive']['action']['cards'][0]['header']['image']['link'] !== ''
        && $r['interactive']['action']['cards'][0]['action']['name'] === 'cta_url');
});

it('sends a single whatsapp product as a picture with a link button, and pictures one by one when the carousel is refused', function () {
    Http::fakeSequence('graph.facebook.com/*')
        ->push(['messages' => [['id' => 'wamid.1']]])
        ->push(['error' => ['message' => 'unsupported', 'code' => 131009]], 400)
        ->push(['error' => ['message' => 'unsupported', 'code' => 131009]], 400) // the graph client tries twice
        ->push(['messages' => [['id' => 'wamid.2']]])
        ->push(['messages' => [['id' => 'wamid.3']]]);
    $account = ChannelAccount::factory()->create(['platform' => Platform::WhatsApp, 'driver' => 'live', 'external_id' => 'PHONE', 'credentials' => ['access_token' => 'tok']]);
    $to = CustomerIdentity::factory()->create(['platform' => Platform::WhatsApp, 'external_id' => '201001234567']);

    app(WhatsAppAdapter::class)->sendText($account, $to, 'fallback', ['cards' => productCardsFor(1)]);
    $result = app(WhatsAppAdapter::class)->sendText($account, $to, 'fallback', ['cards' => productCardsFor(2)]);

    $sent = Http::recorded()->map(fn ($pair) => $pair[0]->data());
    expect($sent[0]['interactive']['type'])->toBe('cta_url')
        ->and($sent[0]['interactive']['header']['image']['link'])->toContain('format=jpg')
        ->and($sent[1]['interactive']['type'])->toBe('carousel')
        ->and($sent[3]['type'])->toBe('image')
        ->and($sent[4]['image']['caption'])->toContain('/products/')
        ->and($result->success)->toBeTrue();
});
