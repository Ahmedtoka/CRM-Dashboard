<?php

use App\Ads\Attribution\OrderAttribution;
use App\Ads\Attribution\UtmParser;
use App\Enums\OrderStatus;
use App\Models\Ad;
use App\Models\AdAccount;
use App\Models\AdCampaign;
use App\Models\AdDailyMetric;
use App\Models\Conversation;
use App\Models\Customer;
use App\Models\Order;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

function attributionRange(): array
{
    return [CarbonImmutable::parse('2026-09-01'), CarbonImmutable::parse('2026-09-30')];
}

function placedOrder(array $attrs = []): Order
{
    return Order::factory()->create(array_merge([
        'customer_id' => Customer::factory()->create()->id,
        'placed_at' => '2026-09-10 10:00',
        'status' => OrderStatus::Confirmed,
    ], $attrs));
}

it('attributes orders by utm ad id, then utm campaign, then inbox first touch', function () {
    $acc = AdAccount::factory()->create();
    $camp = AdCampaign::factory()->for($acc, 'account')->create(['external_id' => '9001', 'name' => 'SALES | UP TO 50%']);
    $ad = Ad::factory()->for($acc, 'account')->create(['external_id' => '120200000001', 'ad_campaign_id' => $camp->id]);
    $ad2 = Ad::factory()->for($acc, 'account')->create(['external_id' => '120200000002']);
    $c3 = Customer::factory()->create();
    $o1 = placedOrder(['utm_content' => '120200000001']);
    $o2 = placedOrder(['utm_campaign' => 'SALES | UP TO 50%']);
    Conversation::factory()->create(['customer_id' => $c3->id, 'ad_id' => '120200000002', 'ad_attributed_at' => '2026-09-09 10:00']);
    $o3 = placedOrder(['customer_id' => $c3->id]);
    $o4 = placedOrder(['customer_id' => $c3->id, 'utm_content' => '120200000001', 'status' => OrderStatus::Cancelled]);

    [$from, $to] = attributionRange();
    $n = app(OrderAttribution::class)->run($from, $to);

    expect($n)->toBe(3)
        ->and($o1->fresh()->ad_id)->toBe($ad->id)->and($o1->fresh()->ad_campaign_id)->toBe($camp->id)->and($o1->fresh()->ad_attribution)->toBe('utm_ad')
        ->and($o2->fresh()->ad_campaign_id)->toBe($camp->id)->and($o2->fresh()->ad_id)->toBeNull()->and($o2->fresh()->ad_attribution)->toBe('utm_campaign')
        ->and($o3->fresh()->ad_id)->toBe($ad2->id)->and($o3->fresh()->ad_attribution)->toBe('inbox')
        ->and($o4->fresh()->ad_attribution)->toBeNull();
});

it('parses utm parameters from a landing page url', function () {
    expect(UtmParser::fromUrl('https://levoile.com/products/x?utm_source=facebook&utm_campaign=Summer&utm_content=1202'))
        ->toMatchArray(['utm_source' => 'facebook', 'utm_campaign' => 'Summer', 'utm_content' => '1202', 'utm_medium' => null, 'utm_term' => null])
        ->and(UtmParser::fromUrl('/?UTM_Source=ig&fbclid=x#top')['utm_source'])->toBe('ig')
        ->and(UtmParser::fromUrl(null)['utm_source'])->toBeNull()
        ->and(UtmParser::fromUrl('https://levoile.com/')['utm_content'])->toBeNull();
});

it('ignores an inbox touch that happened after the order', function () {
    $ad = Ad::factory()->create(['external_id' => '555']);
    $c = Customer::factory()->create();
    Conversation::factory()->create(['customer_id' => $c->id, 'ad_id' => '555', 'ad_attributed_at' => '2026-09-11 10:00']);
    $o = placedOrder(['customer_id' => $c->id]);

    [$from, $to] = attributionRange();
    expect(app(OrderAttribution::class)->run($from, $to))->toBe(0)
        ->and($o->fresh()->ad_attribution)->toBeNull();
});

it('matches an ad by name and picks the one with most spend when names collide', function () {
    $acc = AdAccount::factory()->create();
    $low = Ad::factory()->for($acc, 'account')->create(['name' => 'Summer Reel']);
    $high = Ad::factory()->for($acc, 'account')->create(['name' => 'summer reel']);
    $solo = Ad::factory()->for($acc, 'account')->create(['name' => 'Solo Ad']);
    AdDailyMetric::factory()->create(['ad_id' => $low->id, 'date' => '2026-09-08', 'spend' => 10]);
    AdDailyMetric::factory()->create(['ad_id' => $high->id, 'date' => '2026-09-08', 'spend' => 90]);
    AdDailyMetric::factory()->create(['ad_id' => $low->id, 'date' => '2026-08-01', 'spend' => 5000]); // outside the 7 days
    $a = placedOrder(['utm_term' => 'SUMMER REEL']);
    $b = placedOrder(['utm_content' => 'solo ad']);

    [$from, $to] = attributionRange();
    app(OrderAttribution::class)->run($from, $to);

    expect($a->fresh()->ad_id)->toBe($high->id)->and($a->fresh()->ad_attribution)->toBe('utm_ad')
        ->and($b->fresh()->ad_id)->toBe($solo->id);
});

it('keeps existing attributions unless forced and ignores orders outside the range', function () {
    $ad = Ad::factory()->create(['external_id' => '777']);
    $other = Ad::factory()->create(['external_id' => '888']);
    $done = placedOrder(['utm_content' => '888', 'ad_id' => $ad->id, 'ad_attribution' => 'utm_ad']);
    $old = placedOrder(['utm_content' => '777', 'placed_at' => '2026-07-01 10:00']);

    [$from, $to] = attributionRange();
    expect(app(OrderAttribution::class)->run($from, $to))->toBe(0)
        ->and($done->fresh()->ad_id)->toBe($ad->id)
        ->and($old->fresh()->ad_attribution)->toBeNull();

    expect(app(OrderAttribution::class)->run($from, $to, force: true))->toBe(1)
        ->and($done->fresh()->ad_id)->toBe($other->id)->and($done->fresh()->ad_attribution)->toBe('utm_ad');
});

it('does not query per order for ad lookups', function () {
    $acc = AdAccount::factory()->create();
    $ads = Ad::factory()->count(3)->for($acc, 'account')->create();
    foreach (range(1, 12) as $i) {
        $c = Customer::factory()->create();
        Conversation::factory()->create(['customer_id' => $c->id, 'ad_id' => $ads[$i % 3]->external_id, 'ad_attributed_at' => '2026-09-05 10:00']);
        placedOrder(['customer_id' => $c->id, 'utm_content' => $ads[($i + 1) % 3]->external_id]);
    }

    [$from, $to] = attributionRange();
    DB::enableQueryLog();
    $n = app(OrderAttribution::class)->run($from, $to);
    $selects = collect(DB::getQueryLog())->filter(fn ($q) => str_starts_with($q['query'], 'select'))->count();
    DB::disableQueryLog();

    expect($n)->toBe(12)->and($selects)->toBeLessThanOrEqual(8);
});

it('runs from the artisan command', function () {
    $ad = Ad::factory()->create(['external_id' => '4242']);
    $o = placedOrder(['utm_content' => '4242', 'placed_at' => now()->subDays(3)]);

    $this->artisan('ads:attribute-orders', ['--days' => 35])->assertSuccessful();

    expect($o->fresh()->ad_id)->toBe($ad->id);
});

it('schedules attribution hourly at minute 40 Cairo time', function () {
    $event = collect(app(Illuminate\Console\Scheduling\Schedule::class)->events())
        ->first(fn ($e) => str_contains($e->command, 'ads:attribute-orders'));

    expect($event)->not->toBeNull()
        ->and($event->expression)->toBe('40 * * * *')
        ->and($event->timezone)->toBe('Africa/Cairo')
        ->and($event->command)->toContain('--days=35');
});

it('lets utm evidence replace an inbox attribution without force but keeps an unchanged inbox one', function () {
    $inboxAd = Ad::factory()->create(['external_id' => '111']);
    $utmAd = Ad::factory()->create(['external_id' => '222']);
    $c = Customer::factory()->create();
    Conversation::factory()->create(['customer_id' => $c->id, 'ad_id' => '111', 'ad_attributed_at' => '2026-09-09 10:00']);
    $upgrade = placedOrder(['customer_id' => $c->id, 'utm_content' => '222', 'ad_id' => $inboxAd->id, 'ad_attribution' => 'inbox']);
    $stay = placedOrder(['customer_id' => $c->id, 'ad_id' => $inboxAd->id, 'ad_attribution' => 'inbox']);

    [$from, $to] = attributionRange();

    expect(app(OrderAttribution::class)->run($from, $to))->toBe(1)
        ->and($upgrade->fresh()->ad_id)->toBe($utmAd->id)->and($upgrade->fresh()->ad_attribution)->toBe('utm_ad')
        ->and($stay->fresh()->ad_id)->toBe($inboxAd->id)->and($stay->fresh()->ad_attribution)->toBe('inbox');
});

it('clears the attribution of cancelled orders in range', function () {
    $ad = Ad::factory()->create(['external_id' => '333']);
    $o = placedOrder(['utm_content' => '333', 'status' => OrderStatus::Cancelled, 'ad_id' => $ad->id, 'ad_campaign_id' => $ad->ad_campaign_id, 'ad_attribution' => 'utm_ad']);

    [$from, $to] = attributionRange();
    app(OrderAttribution::class)->run($from, $to);

    expect($o->fresh()->ad_id)->toBeNull()->and($o->fresh()->ad_campaign_id)->toBeNull()->and($o->fresh()->ad_attribution)->toBeNull();
});
