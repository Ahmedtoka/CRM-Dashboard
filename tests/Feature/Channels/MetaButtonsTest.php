<?php

use App\Channels\Adapters\InstagramAdapter;
use App\Channels\Adapters\MessengerAdapter;
use App\Enums\Platform;
use App\Models\ChannelAccount;
use App\Models\CustomerIdentity;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    Http::preventStrayRequests();
    config(['crm.drivers.channels' => 'live']);
});

it('sends quick replies with messenger and instagram text', function (string $adapter, Platform $platform) {
    Http::fake(['graph.facebook.com/*' => Http::response(['message_id' => 'm.1'])]);
    $account = ChannelAccount::factory()->create(['platform' => $platform, 'driver' => 'live', 'credentials' => ['access_token' => 'tok']]);
    $to = CustomerIdentity::factory()->create(['platform' => $platform, 'external_id' => 'PSID']);

    app($adapter)->sendText($account, $to, 'اختاري', ['quick_replies' => [
        ['title' => 'المرتجع والاستبدال', 'payload' => 'flow:return_exchange'],
        ['title' => str_repeat('ا', 30), 'payload' => 'x'],
    ]]);

    Http::assertSent(fn ($r) => ($r['message']['quick_replies'][0] ?? null) === ['content_type' => 'text', 'title' => 'المرتجع والاستبدال', 'payload' => 'flow:return_exchange']
        && mb_strlen($r['message']['quick_replies'][1]['title']) === 20
        && $r['message']['text'] === 'اختاري');
})->with([[MessengerAdapter::class, Platform::Facebook], [InstagramAdapter::class, Platform::Instagram]]);

it('reads the quick reply payload and the postback payload', function () {
    $adapter = app(MessengerAdapter::class);
    $events = $adapter->normalize(['object' => 'page', 'entry' => [['id' => 'PAGE', 'messaging' => [
        ['sender' => ['id' => 'U'], 'timestamp' => 1700000000000, 'message' => ['mid' => 'mid.1', 'text' => 'شكوى', 'quick_reply' => ['payload' => 'flow:complaint']]],
        ['sender' => ['id' => 'U'], 'timestamp' => 1700000001000, 'postback' => ['title' => 'ابدأ', 'payload' => 'menu:main_menu']],
    ]]]]);

    expect($events[0]->payload)->toBe('flow:complaint')->and($events[1]->payload)->toBe('menu:main_menu');
});
