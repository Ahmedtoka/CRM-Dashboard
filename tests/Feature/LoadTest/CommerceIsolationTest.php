<?php

use App\Bot\Flow\Orders\OrderLookup;
use App\Cases\CaseRecorder;
use App\Commerce\FakeCommerceProvider;
use App\Commerce\OrderService;
use App\Enums\OrderStatus;
use App\Enums\Platform;
use App\Enums\UserRole;
use App\Http\Resources\OrderResource;
use App\Models\BotSetting;
use App\Models\City;
use App\Models\Conversation;
use App\Models\LoadTestRun;
use App\Models\Order;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\User;
use App\Simulator\LoadTest\Scenarios;
use App\Simulator\Simulator;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;

/*
 * Review round 1 (2026-10-07): with the LIVE commerce driver configured, an order taken in a
 * load-test chat never reaches Shopify — no order, draft, payment link, customer or stock — and
 * is invisible to reports, sync and real lookups.
 */
beforeEach(function () {
    Carbon::setTestNow(Carbon::parse('2026-10-07 12:00', 'Africa/Cairo'));
    config(['crm.load_test' => true, 'crm.drivers.commerce' => 'live', 'crm.drivers.channels' => 'live']);
    Http::preventStrayRequests();
    Http::fake();
    FakeCommerceProvider::reset();
    BotSetting::current()->update(['enabled' => false]);
    $this->run = LoadTestRun::activeOrStart();
    $this->variant = ProductVariant::factory()->for(Product::factory())->create(['price' => 500, 'shopify_id' => '111']);
    $this->city = City::factory()->create(['shipping_fee' => 60]);
    $this->agent = User::factory()->create(['role' => UserRole::Admin, 'name' => 'Mona Ali']);
});

afterEach(function () {
    Http::assertNothingSent();
    expect(FakeCommerceProvider::$payloads)->toBe([])->and(FakeCommerceProvider::$customers)->toBe([]);
});

function ciChat(string $scenario = 'track_order'): Conversation
{
    $run = LoadTestRun::active();
    $tag = ['run' => $run->id, 'scenario' => $scenario, 'name' => 'منى', 'customer_key' => 'ci-'.$scenario, 'order_number' => Scenarios::needsOrder($scenario) ? Scenarios::fakeOrderNumber() : null];

    return app(Simulator::class)->customerMessage(Platform::Facebook, 'ci-'.$scenario, 'منى', Scenarios::render(Scenarios::get($scenario)['opener'], $tag['order_number']), loadTest: $tag)->conversation->fresh();
}

function ciOrder(array $o = []): array
{
    return array_merge(['type' => 'cod', 'items' => [['variant_id' => test()->variant->id, 'qty' => 1, 'price' => 1]],
        'shipping' => ['name' => 'منى', 'phone' => '01001234567', 'city_id' => test()->city->id, 'address' => 'عنوان تيست'], 'discount' => 0], $o);
}

it('takes a COD order and a payment link in a test chat without touching Shopify', function () {
    $c = ciChat('price');

    $cod = app(OrderService::class)->create($c, $this->agent, ciOrder());
    $link = app(OrderService::class)->create($c, $this->agent, ciOrder(['type' => 'payment_link', 'idempotency_key' => 'k2']));

    expect($cod->fresh()->is_load_test)->toBeTrue()
        ->and($cod->fresh()->status)->toBe(OrderStatus::Confirmed)
        ->and($cod->fresh()->shopify_order_id)->toStartWith('loadtest-')
        ->and((int) ltrim((string) $cod->fresh()->order_number, '#'))->toBeGreaterThan(Scenarios::ORDER_NUMBER_BASE)
        ->and($link->fresh()->invoice_url)->toContain('.invalid/')
        ->and($c->customer->fresh()->shopify_customer_id)->toBeNull(); // a loadtest- store id is never written on a customer

    // Paid, then cancelled: still nothing outside.
    app(OrderService::class)->markPaid($link->fresh());
    app(OrderService::class)->cancel($cod->fresh(), $this->agent);
    expect($cod->fresh()->status)->toBe(OrderStatus::Cancelled);
});

it('keeps test orders out of every query that does not ask for them, but in the chat and drawer', function () {
    $c = ciChat('price');
    $order = app(OrderService::class)->create($c, $this->agent, ciOrder());
    $real = Order::factory()->create();

    expect(Order::query()->pluck('id')->all())->toBe([$real->id])
        ->and(Order::withLoadTest()->count())->toBe(2)
        ->and($c->orders()->pluck('id')->all())->toBe([$order->id])
        ->and($c->customer->orders()->pluck('id')->all())->toBe([$order->id]);

    $row = (new OrderResource($order->fresh()))->resolve(request());
    expect($row['is_load_test'])->toBeTrue()->and($row['shopify_admin_url'])->toBeNull()
        ->and((new Order)->resolveRouteBinding($order->id)?->id)->toBe($order->id);
    $this->actingAs($this->agent)->postJson("/orders/{$order->id}/refresh")->assertStatus(409);
});

it('gives the bot of a test chat its fake order, and a real chat never sees it', function () {
    $c = ciChat('track_order');
    $number = $c->meta['load_test']['order_number'];
    $fake = Order::withLoadTest()->where('order_number', $number)->sole();
    $realChat = Conversation::factory()->create();

    $found = app(OrderLookup::class)->find($c, ['order_ref' => $number]);
    $fromReal = app(OrderLookup::class)->find($realChat, ['order_ref' => $number]);

    expect($fake->is_load_test)->toBeTrue()
        ->and($fake->customer_id)->toBe($c->customer_id)
        ->and($c->customer->orders()->pluck('id')->all())->toBe([$fake->id])
        ->and((int) $number)->toBeGreaterThan(Scenarios::ORDER_NUMBER_BASE)
        ->and(Scenarios::get('track_order')['opener'])->toContain(Scenarios::ORDER)
        ->and($c->messages()->where('direction', 'in')->value('body'))->toContain($number)
        ->and($found['status'])->toBe('found')
        ->and($fromReal['status'])->toBe('not_found');
});

it('never mentions a real order number in a scenario line', function () {
    foreach (Scenarios::all() as $s) {
        foreach ([$s['opener'], ...$s['followups'], $s['thanks']] as $line) {
            expect(preg_match('/\d{4,}/u', $line))->toBe(0);
        }
    }
});

it('routes an order of a test-channel chat WITHOUT its load-test tag to the load-test store', function () {
    $c = app(Simulator::class)->customerMessage(Platform::WhatsApp, 'ci-untagged', 'سلمى', 'عايزة أطلب')->conversation->fresh();

    expect($c->meta)->toBeNull()->and($c->isLoadTest())->toBeTrue();

    $order = app(OrderService::class)->create($c, $this->agent, ciOrder());

    expect($order->fresh()->is_load_test)->toBeTrue()
        ->and($order->fresh()->shopify_order_id)->toStartWith('loadtest-');
});

it('keeps the test order on the case of a test chat', function () {
    $c = ciChat('track_order');
    $order = Order::withLoadTest()->where('order_number', $c->meta['load_test']['order_number'])->sole();

    $case = app(CaseRecorder::class)->record($c, 'complaint', ['order_id' => $order->id]);

    expect($case->order_id)->toBe($order->id)
        ->and($case->fresh()->order?->id)->toBe($order->id);
});
