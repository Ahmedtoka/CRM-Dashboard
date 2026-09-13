<?php

use App\Commerce\{OrderService, FakeCommerceProvider};
use App\Commerce\Jobs\SubmitOrderToProvider;
use App\Enums\{OrderStatus, Platform, UserRole};
use App\Events\UserNotified;
use App\Models\{Conversation, ChannelAccount, Customer, Product, ProductVariant, ShippingZone, User, Order};
use Illuminate\Support\Facades\{Event, Queue};
use Illuminate\Support\Str;

beforeEach(function () {
    Event::fake();
    FakeCommerceProvider::reset();
    $this->conv = Conversation::factory()->for(Customer::factory())->for(ChannelAccount::factory()->state(['platform' => Platform::Instagram]), 'channelAccount')->create(['platform' => Platform::Instagram]);
    $this->variant = ProductVariant::factory()->for(Product::factory())->create(['price' => 500, 'shopify_id' => '111', 'inventory_quantity' => 5]);
    $this->sup = User::factory()->create(['role' => UserRole::Supervisor, 'name' => 'Mona Ali', 'email' => 'mona@crm.test']);
    $this->data = fn (array $o = []) => array_replace_recursive([
        'idempotency_key' => (string) Str::uuid(), 'type' => 'cod',
        'items' => [['variant_id' => $this->variant->id, 'qty' => 2]],
        'shipping' => ['name' => 'Nour', 'phone' => '01001234567', 'province_code' => 'C', 'city' => 'Nasr City', 'address1' => '12 شارع النصر'],
        'discount' => null, 'note' => 'تغليف هدية',
    ], $o);
});

it('saves locally as submitting and queues the job', function () {
    Queue::fake();
    $order = app(OrderService::class)->create($this->conv, $this->sup, ($this->data)());
    expect($order->status)->toBe(OrderStatus::Submitting)->and($order->idempotency_key)->not->toBeNull();
    Queue::assertPushedOn('commerce', SubmitOrderToProvider::class);
});

it('is idempotent on the same key', function () {
    Queue::fake();
    $d = ($this->data)();
    $a = app(OrderService::class)->create($this->conv, $this->sup, $d);
    $b = app(OrderService::class)->create($this->conv, $this->sup, $d);
    expect($b->id)->toBe($a->id)->and(Order::count())->toBe(1);
    Queue::assertPushed(SubmitOrderToProvider::class, 1);
});

it('submits cod with attribution and confirms', function () {
    $order = app(OrderService::class)->create($this->conv, $this->sup, ($this->data)()); // sync queue
    $p = FakeCommerceProvider::$payloads[0];
    expect($order->fresh()->status)->toBe(OrderStatus::Confirmed)
        ->and($p->tags)->toContain('social-crm', 'platform:instagram', 'mod:mona-ali')
        ->and($p->noteAttributes)->toHaveKeys(['crm_order_id', 'crm_conversation_id', 'crm_user_id'])
        ->and(FakeCommerceProvider::$customers)->toHaveCount(1);
});

it('fails with an arabic reason on shopify user errors without retrying', function () {
    FakeCommerceProvider::failNext('user_errors', 'Not enough inventory', lineIndex: 0);
    $order = app(OrderService::class)->create($this->conv, $this->sup, ($this->data)());
    expect($order->fresh()->status)->toBe(OrderStatus::Failed)->and($order->fresh()->last_error)->toContain($this->variant->product->title);
});

it('retries a failed order through an atomic claim', function () {
    FakeCommerceProvider::failNext('user_errors', 'Not enough inventory', lineIndex: 0);
    $order = app(OrderService::class)->create($this->conv, $this->sup, ($this->data)());
    app(OrderService::class)->retry($order->fresh(), $this->sup);
    expect($order->fresh()->status)->toBe(OrderStatus::Confirmed);
});

it('refuses cancelling fulfilled orders and moderators', function () {
    $order = Order::factory()->create(['status' => OrderStatus::Confirmed, 'fulfillment_status' => 'fulfilled']);
    expect(fn () => app(OrderService::class)->cancel($order, $this->sup))->toThrow(DomainException::class);
});

it('blocks creation when order creation is disabled', function () {
    \App\Shopify\Connection\ShopifyIntegration::create(['shop_domain' => 'd.myshopify.com', 'access_token' => 't', 'api_secret' => 's', 'status' => 'connected', 'settings' => ['order_creation_enabled' => false]]);
    expect(fn () => app(OrderService::class)->create($this->conv, $this->sup, ($this->data)()))->toThrow(\Illuminate\Validation\ValidationException::class);
});

// --- Additional coverage beyond the brief ---

it('writes the exact arabic user-error message with the variant title', function () {
    $this->variant->update(['title' => 'M']);
    $this->variant->product->update(['title' => 'فستان سهرة']);
    FakeCommerceProvider::failNext('user_errors', 'Not enough inventory', lineIndex: 0);

    $order = app(OrderService::class)->create($this->conv, $this->sup, ($this->data)());

    expect($order->fresh()->last_error)->toBe('«فستان سهرة — M»: الكمية غير متوفرة')
        ->and($order->fresh()->submit_attempts)->toBe(1);
});

it('prefixes unmapped user errors with Shopify', function () {
    FakeCommerceProvider::failNext('user_errors', 'Phone is invalid');

    $order = app(OrderService::class)->create($this->conv, $this->sup, ($this->data)());

    expect($order->fresh()->last_error)->toBe('Shopify: Phone is invalid');
});

it('marks the order failed as disconnected on auth errors', function () {
    FakeCommerceProvider::failNext('auth', 'Shopify authentication failed');

    $order = app(OrderService::class)->create($this->conv, $this->sup, ($this->data)());

    expect($order->fresh()->status)->toBe(OrderStatus::Failed)
        ->and($order->fresh()->last_error)->toBe('Shopify غير متصل');
});

it('fails after transport errors and notifies supervisors', function () {
    FakeCommerceProvider::failNext('transport', 'Connection timed out');

    $order = app(OrderService::class)->create($this->conv, $this->sup, ($this->data)());

    expect($order->fresh()->status)->toBe(OrderStatus::Failed)
        ->and($order->fresh()->last_error)->not->toBeNull();
    Event::assertDispatched(UserNotified::class, fn (UserNotified $e) => $e->userId === $this->sup->id && $e->type === 'order.submit_failed');
});

it('never strands an order in submitting when the job cannot send it or crashes', function () {
    Queue::fake();
    $unlinked = ProductVariant::factory()->for(Product::factory()->state(['title' => 'طرحة']))->create(['price' => 100, 'shopify_id' => null]);

    $a = app(OrderService::class)->create($this->conv, $this->sup, ($this->data)(['items' => [['variant_id' => $unlinked->id, 'qty' => 1]]]));
    (new SubmitOrderToProvider($a->id))->handle(app(OrderService::class));

    $b = app(OrderService::class)->create($this->conv, $this->sup, ($this->data)());
    FakeCommerceProvider::failNext('boom', 'unexpected crash');
    (new SubmitOrderToProvider($b->id))->handle(app(OrderService::class));

    expect($a->fresh()->status)->toBe(OrderStatus::Failed)
        ->and($a->fresh()->last_error)->toBe('«طرحة»: المنتج مش مربوط بـ Shopify')
        ->and($b->fresh()->status)->toBe(OrderStatus::Failed)
        ->and($b->fresh()->last_error)->toContain('unexpected crash');
});

it('creates a payment link with the invoice url and a chat line', function () {
    $order = app(OrderService::class)->create($this->conv, $this->sup, ($this->data)(['type' => 'payment_link']));

    $fresh = $order->fresh();
    $line = $this->conv->messages()->latest('id')->first()->body;

    expect($fresh->status)->toBe(OrderStatus::AwaitingPayment)
        ->and($fresh->invoice_url)->toStartWith('https://')
        ->and($line)->toStartWith('🔗 رابط دفع لطلب ')
        ->and($line)->toEndWith('— 1060.00 ج.م');
});

it('refuses an idempotency key replayed by another user', function () {
    Queue::fake();
    $d = ($this->data)();
    app(OrderService::class)->create($this->conv, $this->sup, $d);
    $other = User::factory()->create(['role' => UserRole::Supervisor]);

    expect(fn () => app(OrderService::class)->create($this->conv, $other, $d))->toThrow(DomainException::class, 'idempotency_conflict');
});

it('prices shipping from the chosen zone rate and applies a percent discount', function () {
    $z = ShippingZone::factory()->create();
    $z->regions()->create(['country_code' => 'EG', 'province_code' => 'C', 'province_name' => 'Cairo']);
    $z->rates()->create(['title' => 'عادي', 'price' => 60]);
    $fast = $z->rates()->create(['title' => 'مستعجل', 'price' => 100]);

    $order = app(OrderService::class)->create($this->conv, $this->sup, ($this->data)([
        'shipping' => ['rate_id' => $fast->id],
        'discount' => ['type' => 'percent', 'value' => 10, 'reason' => 'عميلة دائمة'],
    ]))->fresh();

    $p = FakeCommerceProvider::$payloads[0];

    expect((float) $order->shipping_fee)->toBe(100.0)
        ->and($order->shipping_title)->toBe('مستعجل')
        ->and((float) $order->discount)->toBe(100.0)
        ->and((float) $order->total)->toBe(1000.0)
        ->and($order->discount_reason)->toBe('عميلة دائمة')
        ->and($p->shippingLine)->toBe(['title' => 'مستعجل', 'price' => '100.00'])
        ->and($p->discount)->toMatchArray(['type' => 'percent', 'value' => '10.00']);
});

it('rejects a rate that does not serve the province and a discount without reason', function () {
    $z = ShippingZone::factory()->create();
    $z->regions()->create(['country_code' => 'EG', 'province_code' => 'GZ', 'province_name' => 'Giza']);
    $giza = $z->rates()->create(['title' => 'عادي', 'price' => 60]);

    expect(fn () => app(OrderService::class)->create($this->conv, $this->sup, ($this->data)(['shipping' => ['rate_id' => $giza->id]])))
        ->toThrow(\Illuminate\Validation\ValidationException::class)
        ->and(fn () => app(OrderService::class)->create($this->conv, $this->sup, ($this->data)(['discount' => ['type' => 'fixed', 'value' => 50]])))
        ->toThrow(\Illuminate\Validation\ValidationException::class);
});

it('cancels an unpaid payment link by deleting the draft and passes restock for cod', function () {
    $link = app(OrderService::class)->create($this->conv, $this->sup, ($this->data)(['type' => 'payment_link']));
    $cod = app(OrderService::class)->create($this->conv, $this->sup, ($this->data)());

    app(OrderService::class)->cancel($link->fresh(), $this->sup);
    app(OrderService::class)->cancel($cod->fresh(), $this->sup, restock: false);

    expect(FakeCommerceProvider::$cancelCalls)->toBe([
        ['order_id' => $link->id, 'mode' => 'draft', 'restock' => true],
        ['order_id' => $cod->id, 'mode' => 'order', 'restock' => false],
    ]);
});

it('cancels on the provider when a local cancel lands while the submission is in flight', function () {
    // The supervisor cancels while Shopify is still creating the order (nothing to cancel there yet).
    FakeCommerceProvider::$beforeCreate = fn (Order $o) => app(OrderService::class)->cancel(Order::find($o->id), $this->sup);

    $order = app(OrderService::class)->create($this->conv, $this->sup, ($this->data)());

    expect($order->fresh()->status)->toBe(OrderStatus::Cancelled)
        ->and($order->fresh()->shopify_order_id)->not->toBeNull()
        ->and(collect(FakeCommerceProvider::$cancelCalls)->pluck('order_id')->all())->toBe([$order->id]);
});

it('forbids moderators from cancelling', function () {
    $mod = User::factory()->create(['role' => UserRole::Moderator]);
    $order = Order::factory()->create(['status' => OrderStatus::Confirmed, 'created_by_id' => $mod->id]);

    expect(fn () => app(OrderService::class)->cancel($order, $mod))->toThrow(\Illuminate\Auth\Access\AuthorizationException::class);
});

it('accepts the legacy city_id payload without an idempotency key', function () {
    $city = \App\Models\City::factory()->create(['shipping_fee' => 45]);

    $order = app(OrderService::class)->create($this->conv, $this->sup, [
        'type' => 'cod', 'items' => [['variant_id' => $this->variant->id, 'qty' => 1]],
        'shipping' => ['name' => 'Nour', 'phone' => '01001234567', 'city_id' => $city->id, 'address' => 'شارع النصر'],
        'discount' => 20,
    ])->fresh();

    expect(Str::isUuid($order->idempotency_key))->toBeTrue()
        ->and((float) $order->shipping_fee)->toBe(45.0)
        ->and((float) $order->total)->toBe(525.0)
        ->and($order->status)->toBe(OrderStatus::Confirmed);
});

it('returns 200 for a replay, 409 for a foreign replay, and 201 for a new order over http', function () {
    $d = ($this->data)();
    $url = "/inbox/conversations/{$this->conv->id}/orders";

    $this->actingAs($this->sup)->postJson($url, $d)->assertCreated()->assertJsonPath('data.status', 'confirmed');
    $this->actingAs($this->sup)->postJson($url, $d)->assertOk();

    $other = User::factory()->create(['role' => UserRole::Supervisor]);
    $this->actingAs($other)->postJson($url, $d)->assertStatus(409)->assertJsonStructure(['message']);

    $noReason = array_merge(($this->data)(), ['discount' => 30, 'discount_type' => 'fixed']);
    $this->actingAs($this->sup)->postJson($url, $noReason)->assertStatus(422)->assertJsonValidationErrors('discount_reason');

    $withReason = array_merge(($this->data)(), ['discount' => 10, 'discount_type' => 'percent', 'discount_reason' => 'عميلة دائمة']);
    $this->actingAs($this->sup)->postJson($url, $withReason)->assertCreated()
        ->assertJsonPath('data.discount', 100)
        ->assertJsonPath('data.shipping_fee', 60) // no zones stored: default fee
        ->assertJsonPath('data.total', 960);
});

it('guards cancel over http: moderators 403, fulfilled 422, restock flag honoured', function () {
    $mod = User::factory()->create(['role' => UserRole::Moderator]);
    $mod->userPlatforms()->create(['platform' => Platform::Instagram]);
    $fulfilled = Order::factory()->create(['status' => OrderStatus::Confirmed, 'fulfillment_status' => 'partial', 'platform' => Platform::Instagram]);
    $open = Order::factory()->create(['status' => OrderStatus::Confirmed, 'platform' => Platform::Instagram, 'shopify_order_id' => '555']);

    $this->actingAs($mod)->postJson("/orders/{$open->id}/cancel")->assertForbidden();
    $this->actingAs($this->sup)->postJson("/orders/{$fulfilled->id}/cancel")->assertStatus(422)->assertJsonStructure(['message']);
    $this->actingAs($this->sup)->postJson("/orders/{$open->id}/cancel", ['restock' => false])->assertOk()->assertJsonPath('data.status', 'cancelled');

    expect(FakeCommerceProvider::$cancelCalls)->toBe([['order_id' => $open->id, 'mode' => 'order', 'restock' => false]]);
});
