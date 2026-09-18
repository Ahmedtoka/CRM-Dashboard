<?php

use App\Enums\{MessageDirection, Platform, SenderType, UserRole};
use App\Models\{ChannelAccount, Conversation, Customer, CustomerIdentity, Fulfillment, Message, Order, Product, ProductVariant, Shipment, User};
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    $this->admin = User::factory()->create(['role' => UserRole::Admin]);
    $this->wa = ChannelAccount::factory()->create(['platform' => Platform::WhatsApp]);
    $this->fb = ChannelAccount::factory()->create(['platform' => Platform::Facebook]);
});

it('finds customers by egyptian phone formats and name', function () {
    $mona = Customer::factory()->create(['name' => 'منى أحمد', 'phone' => '01001234567']);
    Conversation::factory()->for($mona)->for($this->wa, 'channelAccount')->create();

    foreach (['01001234567', '+201001234567', '201001234567', '1001234567', '٠١٠٠١٢٣٤٥٦٧', 'منى'] as $q) {
        $this->actingAs($this->admin)->getJson('/search?q='.urlencode($q).'&types[]=customers')->assertOk()
            ->assertJsonPath('customers.0.id', $mona->id)->assertJsonPath('customers.0.href', "/customers/{$mona->id}");
    }
});

it('finds orders by crm id, shopify name, tracking number and fulfillment tracking number', function () {
    $order = Order::factory()->create(['shopify_order_name' => '#1001', 'platform' => Platform::WhatsApp]);
    Shipment::factory()->create(['order_id' => $order->id, 'tracking_number' => 'BST-777']);
    Fulfillment::factory()->create(['order_id' => $order->id, 'tracking_number' => 'FLF-999']);

    foreach (['#1001', 'BST-777', 'FLF-999', (string) $order->id] as $q) {
        $this->actingAs($this->admin)->getJson('/search?q='.urlencode($q).'&types[]=orders')->assertJsonPath('orders.0.id', $order->id);
    }
});

it('finds conversations by message text within 180 days with a snippet', function () {
    $conv = Conversation::factory()->for($this->wa, 'channelAccount')->create();
    Message::factory()->create(['conversation_id' => $conv->id, 'direction' => MessageDirection::In, 'sender_type' => SenderType::Customer, 'body' => 'عايزة الفستان الستان الأحمر مقاس M']);
    $old = Conversation::factory()->for($this->wa, 'channelAccount')->create();
    Message::factory()->create(['conversation_id' => $old->id, 'body' => 'الفستان الستان قديم', 'created_at' => now()->subDays(200)]);

    $this->actingAs($this->admin)->getJson('/search?q='.urlencode('الستان').'&types[]=conversations')
        ->assertJsonCount(1, 'conversations')->assertJsonPath('conversations.0.id', $conv->id)
        ->assertJsonPath('conversations.0.href', "/inbox?c={$conv->id}")
        ->assertJsonPath('conversations.0.subtitle', fn ($s) => str_contains($s, 'الستان'));
});

it('excludes system messages from conversation search results', function () {
    $conv = Conversation::factory()->for($this->wa, 'channelAccount')->create();
    Message::factory()->create(['conversation_id' => $conv->id, 'sender_type' => SenderType::System, 'body' => 'تم تحويل المحادثة للموظف - فستان']);

    $this->actingAs($this->admin)->getJson('/search?q='.urlencode('فستان').'&types[]=conversations')
        ->assertJsonCount(0, 'conversations');
});

it('respects moderator platform scoping', function () {
    $mod = User::factory()->create(['role' => UserRole::Moderator]);
    $mod->userPlatforms()->create(['platform' => Platform::Facebook]);
    $conv = Conversation::factory()->for($this->wa, 'channelAccount')->create();
    Message::factory()->create(['conversation_id' => $conv->id, 'body' => 'فستان سواريه']);
    Order::factory()->create(['order_number' => 'WA-55', 'platform' => Platform::WhatsApp]);

    $this->actingAs($mod)->getJson('/search?q='.urlencode('سواريه'))->assertJsonCount(0, 'conversations');
    $this->actingAs($mod)->getJson('/search?q=WA-55')->assertJsonCount(0, 'orders');
});

it('finds products by title or sku and validates the query', function () {
    $p = Product::factory()->create(['title' => 'بلوزة شيفون']);
    ProductVariant::factory()->for($p)->create(['sku' => 'BL-900', 'price' => 450]);

    $this->actingAs($this->admin)->getJson('/search?q=BL-900&types[]=products')->assertJsonPath('products.0.id', $p->id)->assertJsonPath('products.0.href', null);
    $this->actingAs($this->admin)->getJson('/search?q=x')->assertStatus(422);
});

it('returns 422 instead of crashing when q is an array', function () {
    $this->actingAs($this->admin)->getJson('/search?q[]=1&q[]=2')->assertStatus(422);
    $this->actingAs($this->admin)->getJson('/api/v1/search?q[]=1&q[]=2')->assertStatus(422);
});

it('escapes LIKE wildcards so a literal %% matches nothing instead of everything', function () {
    Customer::factory()->create(['name' => 'أي حاجة تانية خالص']);

    $this->actingAs($this->admin)->getJson('/search?q='.urlencode('%%').'&types[]=customers')
        ->assertOk()->assertJsonCount(0, 'customers');
});

it('treats a single digit as an exact order-id lookup only, never a wildcard scan in any group', function () {
    $order = Order::factory()->create(['platform' => Platform::WhatsApp]); // fresh schema: id === 1

    DB::enableQueryLog();
    $this->actingAs($this->admin)->getJson('/search?q=1')
        ->assertOk()
        ->assertJsonPath('orders.0.id', $order->id)
        ->assertJsonCount(0, 'customers')->assertJsonCount(0, 'conversations')->assertJsonCount(0, 'products');

    $queries = collect(DB::getQueryLog())->pluck('query');
    // No group ran a wildcard scan (LIKE) and neither the products nor
    // messages tables were touched at all — only an exact, indexed lookup
    // on `orders` (plus its customer eager-load for the subtitle).
    expect($queries->contains(fn ($sql) => str_contains(strtolower($sql), 'like')))->toBeFalse();
    expect($queries->contains(fn ($sql) => str_contains($sql, 'from "products"')))->toBeFalse();
    expect($queries->contains(fn ($sql) => str_contains($sql, 'from "messages"')))->toBeFalse();
});

it('finds Arabic letter-form variants for customer names and product titles', function () {
    $customer = Customer::factory()->create(['name' => 'أحمد علي']);
    $this->actingAs($this->admin)->getJson('/search?q='.urlencode('احمد').'&types[]=customers')
        ->assertOk()->assertJsonPath('customers.0.id', $customer->id);

    $product = Product::factory()->create(['title' => 'زي مدرسة']);
    ProductVariant::factory()->for($product)->create();
    $this->actingAs($this->admin)->getJson('/search?q='.urlencode('مدرسه').'&types[]=products')
        ->assertOk()->assertJsonPath('products.0.id', $product->id);
});

describe('scoping reuses the codebase authorities', function () {
    beforeEach(function () {
        $this->mod = User::factory()->create(['role' => UserRole::Moderator]);
        $this->mod->userPlatforms()->create(['platform' => Platform::Facebook]);

        // Customer scoping is identity-based (ModeratorScope::customers): an identity
        // on the moderator's own platform is visible, one only on another platform isn't.
        $this->ownCustomer = Customer::factory()->create(['name' => 'زبونة فيسبوك']);
        CustomerIdentity::factory()->for($this->ownCustomer)->create(['platform' => Platform::Facebook]);

        $this->otherCustomer = Customer::factory()->create(['name' => 'زبونة واتساب']);
        CustomerIdentity::factory()->for($this->otherCustomer)->create(['platform' => Platform::WhatsApp]);

        // Order scoping (ModeratorScope::orders): own platform, OR created by the moderator
        // regardless of platform.
        $this->ownPlatformOrder = Order::factory()->create(['order_number' => 'FB-1', 'platform' => Platform::Facebook]);
        $this->ownCreatedOrder = Order::factory()->create(['order_number' => 'FB-2', 'platform' => Platform::WhatsApp, 'created_by_id' => $this->mod->id]);
        $this->otherOrder = Order::factory()->create(['order_number' => 'FB-3', 'platform' => Platform::WhatsApp]);

        // Conversation scoping (ConversationQuery::visibleTo): own platform only.
        $this->ownConversation = Conversation::factory()->for($this->fb, 'channelAccount')->create();
        Message::factory()->create(['conversation_id' => $this->ownConversation->id, 'body' => 'استفسار توصيل']);
        $this->otherConversation = Conversation::factory()->for($this->wa, 'channelAccount')->create();
        Message::factory()->create(['conversation_id' => $this->otherConversation->id, 'body' => 'استفسار توصيل']);
    });

    it('lets a moderator see only their own-platform customers, orders and conversations', function () {
        $this->actingAs($this->mod)->getJson('/search?q='.urlencode('زبونة').'&types[]=customers')
            ->assertJsonCount(1, 'customers')->assertJsonPath('customers.0.id', $this->ownCustomer->id);

        $this->actingAs($this->mod)->getJson('/search?q=FB-1&types[]=orders')->assertJsonPath('orders.0.id', $this->ownPlatformOrder->id);
        $this->actingAs($this->mod)->getJson('/search?q=FB-2&types[]=orders')->assertJsonPath('orders.0.id', $this->ownCreatedOrder->id);
        $this->actingAs($this->mod)->getJson('/search?q=FB-3&types[]=orders')->assertJsonCount(0, 'orders');

        $this->actingAs($this->mod)->getJson('/search?q='.urlencode('توصيل').'&types[]=conversations')
            ->assertJsonCount(1, 'conversations')->assertJsonPath('conversations.0.id', $this->ownConversation->id);
    });

    it("doesn't let a moderator see another platform's customer even by exact name", function () {
        $this->actingAs($this->mod)->getJson('/search?q='.urlencode('زبونة واتساب').'&types[]=customers')
            ->assertJsonCount(0, 'customers');
    });

    it('lets a supervisor see everything regardless of platform', function () {
        $supervisor = User::factory()->create(['role' => UserRole::Supervisor]);

        $this->actingAs($supervisor)->getJson('/search?q='.urlencode('زبونة').'&types[]=customers')->assertJsonCount(2, 'customers');
        $this->actingAs($supervisor)->getJson('/search?q=FB-3&types[]=orders')->assertJsonPath('orders.0.id', $this->otherOrder->id);
        $this->actingAs($supervisor)->getJson('/search?q='.urlencode('توصيل').'&types[]=conversations')->assertJsonCount(2, 'conversations');
    });

    it('applies the same scoping to /api/v1/search with a sanctum token', function () {
        $token = $this->mod->createToken('test')->plainTextToken;

        $this->withToken($token)->getJson('/api/v1/search?q='.urlencode('زبونة').'&types[]=customers')
            ->assertOk()->assertJsonCount(1, 'customers')->assertJsonPath('customers.0.id', $this->ownCustomer->id);
        $this->withToken($token)->getJson('/api/v1/search?q=FB-3&types[]=orders')->assertJsonCount(0, 'orders');
        $this->withToken($token)->getJson('/api/v1/search?q='.urlencode('توصيل').'&types[]=conversations')
            ->assertJsonCount(1, 'conversations')->assertJsonPath('conversations.0.id', $this->ownConversation->id);
    });
});

it('bounds message search by a messages.id lower bound and a 90-day Arabic window (final fix wave I3)', function () {
    $conv = Conversation::factory()->for($this->wa, 'channelAccount')->create();
    $old = Message::factory()->create(['conversation_id' => $conv->id, 'body' => 'فستان قطيفة قديم', 'created_at' => now()->subDays(100)]);
    Message::factory()->create(['conversation_id' => $conv->id, 'body' => 'velvet dress old', 'created_at' => now()->subDays(100)]);

    // An empty window short-circuits: no message scan runs at all.
    DB::enableQueryLog();
    $this->actingAs($this->admin)->getJson('/search?q='.urlencode('قطيفة').'&types[]=conversations')->assertOk()->assertJsonCount(0, 'conversations');
    expect(collect(DB::getQueryLog())->pluck('query')->contains(fn ($sql) => str_contains($sql, 'from "messages"') && str_contains(strtolower($sql), 'like')))->toBeFalse();
    DB::flushQueryLog();

    // A recent, unrelated message makes the window non-empty so the bounded scan really runs.
    Message::factory()->create(['conversation_id' => $conv->id, 'body' => 'unrelated recent', 'created_at' => now()->subDays(10)]);

    // 100 days old: outside the 90-day Arabic window...
    DB::enableQueryLog();
    $this->actingAs($this->admin)->getJson('/search?q='.urlencode('قطيفة').'&types[]=conversations')->assertOk()->assertJsonCount(0, 'conversations');
    $messageQueries = collect(DB::getQueryLog())->pluck('query')->filter(fn ($sql) => str_contains($sql, 'from "messages"') && str_contains(strtolower($sql), 'like'));
    expect($messageQueries)->not->toBeEmpty()
        ->and($messageQueries->every(fn ($sql) => preg_match('/"messages"\."id" >= \?/', $sql) === 1))->toBeTrue();
    DB::flushQueryLog();

    // ...but still inside the 180-day window for a non-Arabic term.
    $this->actingAs($this->admin)->getJson('/search?q=velvet&types[]=conversations')->assertOk()->assertJsonPath('conversations.0.id', $conv->id);

    // The lower bound is cached per window (5 minutes) — a second identical search reads it from cache.
    expect(Cache::has('search.min_msg_id.90'))->toBeTrue();
    DB::flushQueryLog();
    $this->actingAs($this->admin)->getJson('/search?q='.urlencode('قطيفة').'&types[]=conversations')->assertOk();
    expect(collect(DB::getQueryLog())->pluck('query')->contains(fn ($sql) => str_contains($sql, 'min("id")')))->toBeFalse();

    config(['crm.search.arabic_window_days' => 120]);
    $this->actingAs($this->admin)->getJson('/search?q='.urlencode('قطيفة').'&types[]=conversations')->assertJsonPath('conversations.0.id', $old->conversation_id);
});

it('stays within a small query budget even with real matches in every scanned group', function () {
    Customer::factory()->count(3)->create(['name' => 'فستان أحمد']);

    $conv1 = Conversation::factory()->for($this->wa, 'channelAccount')->create();
    Message::factory()->create(['conversation_id' => $conv1->id, 'body' => 'عايزة فستان مقاس M']);
    $conv2 = Conversation::factory()->for($this->fb, 'channelAccount')->create();
    Message::factory()->create(['conversation_id' => $conv2->id, 'body' => 'فيه فستان تاني؟']);

    Product::factory()->count(3)->create(['title' => 'فستان سواريه'])->each(fn ($p) => ProductVariant::factory()->for($p)->create());

    DB::enableQueryLog();
    $this->actingAs($this->admin)->getJson('/search?q='.urlencode('فستان'))->assertOk();
    expect(count(DB::getQueryLog()))->toBeLessThanOrEqual(14);
});
