<?php

use App\Bot\Flow\FakeTurnUnderstanding;
use App\Bot\Flow\TurnUnderstanding;
use App\Bot\Grounding\ShippingFeeAnswer;
use App\Channels\Data\InboundMessageData;
use App\Enums\Platform;
use App\Enums\SenderType;
use App\Inbox\InboxIngestor;
use App\Models\BotFlow;
use App\Models\BotRun;
use App\Models\BotSetting;
use App\Models\ChannelAccount;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\ShippingRate;
use App\Models\ShippingZone;
use App\Models\ShippingZoneRegion;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    Event::fake();
    Http::preventStrayRequests();
    ChannelAccount::factory()->create(['platform' => Platform::Facebook, 'external_id' => 'PAGE1']);
    BotSetting::current()->update(['enabled' => true, 'ai_enabled' => true, 'working_hours' => null, 'min_confidence' => 0.6]);
    app()->bind(TurnUnderstanding::class, FakeTurnUnderstanding::class);
    config(['crm.drivers.ai' => 'fake']);
    BotFlow::query()->update(['is_active' => false]);
});

function feeSay(string $id, string $text): void
{
    app(InboxIngestor::class)->ingestMessage(new InboundMessageData(Platform::Facebook, 'PAGE1', 'PSID-1', 'Mona', $id, $text, CarbonImmutable::now()));
}

function lastFeeReply(): string
{
    return (string) Message::where('sender_type', SenderType::Bot->value)->latest('id')->value('body');
}

/** A synced Shopify zone covering these governorates with one rate. */
function shopifyZone(array $codes, float $price): void
{
    $zone = ShippingZone::factory()->create();

    foreach ($codes as $code) {
        ShippingZoneRegion::factory()->create(['shipping_zone_id' => $zone->id, 'province_code' => $code]);
    }

    ShippingRate::factory()->create(['shipping_zone_id' => $zone->id, 'price' => $price]);
}

it('gives the one domestic Shopify rate when every governorate shares it', function () {
    shopifyZone(['C', 'GZ'], 55);
    shopifyZone(['ALX'], 55);

    feeSay('m1', 'الشحن بكام؟');

    expect(lastFeeReply())->toContain('مصاريف الشحن لكل المحافظات: 55 جنيه')
        ->toContain('مصاريف الشحن بتتحسب حسب المحافظة')
        // "بكام" also matches the price intent: its product-link script is not sent.
        ->not->toContain('ده لينك فيه جميع الموديلات')
        ->and(BotRun::latest('id')->value('decision'))->toBe('reply');
});

it('asks for the governorate when rates differ, then quotes that governorate from Shopify', function () {
    shopifyZone(['C', 'GZ'], 45);
    shopifyZone(['ALX'], 70);

    feeSay('m1', 'مصاريف الشحن كام؟');

    expect(lastFeeReply())->toContain(ShippingFeeAnswer::ASK_GOVERNORATE)
        ->not->toContain('45')->not->toContain('70')
        ->and(Conversation::first()->bot_state['shipping_ask'])->toBeTrue();

    feeSay('m2', 'اسكندرية');

    expect(lastFeeReply())->toContain('مصاريف الشحن لـالإسكندرية: 70 جنيه')
        ->and(Conversation::first()->bot_state['shipping_ask'])->toBeFalse();
});

it('quotes the governorate in the question directly', function () {
    shopifyZone(['C', 'GZ'], 45);
    shopifyZone(['ALX'], 70);

    feeSay('m1', 'الشحن للجيزة بكام');

    expect(lastFeeReply())->toContain('مصاريف الشحن لـالجيزة: 45 جنيه')->not->toContain('70');
});

it('follows a changed Shopify rate without any script change', function () {
    shopifyZone(['C', 'GZ', 'ALX'], 50);
    ShippingRate::query()->update(['price' => 65]);

    feeSay('m1', 'سعر الشحن');

    expect(lastFeeReply())->toContain('65 جنيه')->not->toContain('50 جنيه');
});

it('never quotes a number when nothing is synced from Shopify', function () {
    feeSay('m1', 'الشحن بكام');

    expect(lastFeeReply())->toContain('مصاريف الشحن بتتحسب حسب المحافظة')
        ->not->toContain('جنيه')
        ->not->toContain(ShippingFeeAnswer::ASK_GOVERNORATE);
});

it('keeps the product price answer when a product price is asked too', function () {
    shopifyZone(['C', 'GZ', 'ALX'], 50);

    feeSay('m1', 'الفستان بكام والشحن بكام');

    expect(lastFeeReply())->toContain('مصاريف الشحن لكل المحافظات: 50 جنيه')
        ->toContain('ده لينك فيه جميع الموديلات');
});

it('tells a shipping-fee question from a delivery-time question', function (string $text, bool $fee) {
    expect(\App\Bot\Flow\TurnRunner::asksShippingFee([$text]))->toBe($fee);
})->with([
    ['الشحن للجيزة بكام', true],
    ['بكام الشحن', true],
    ['التوصيل لاسكندرية بكام؟', true],
    ['سعر الشحن', true],
    ['التوصيل بياخد كام يوم', false],
    ['الفستان ده بكام', false],
]);
