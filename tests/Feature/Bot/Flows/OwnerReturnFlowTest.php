<?php

use App\Bot\Flow\ReplyScheduler;
use App\Bot\Flows\FakeFlowAnswerInterpreter;
use App\Bot\Flows\FlowAnswerInterpreter;
use App\Bot\Flows\FlowDefinition;
use App\Bot\Flows\FlowEngine;
use App\Bot\Flows\FlowPrompter;
use App\Bot\Flows\FlowResult;
use App\Bot\Flows\FlowState;
use App\Bot\Flows\FlowStepCatalog;
use App\Bot\Flows\ReturnFlowUpgrade;
use App\Bot\Flows\Returns\ExchangeProducts;
use App\Bot\Flows\Returns\RemoteProductLookup;
use App\Bot\Flows\Returns\ShopifyRemoteProductLookup;
use App\Bot\Flows\Sandbox\FlowSandbox;
use App\Bot\Flows\Steps\OrderItemsStep;
use App\Bot\Flows\Steps\OrderStep;
use App\Bot\Flows\Steps\ProductLinkStep;
use App\Cases\CaseSummary;
use App\Channels\Data\InboundMessageData;
use App\Enums\Handler;
use App\Enums\Platform;
use App\Enums\SenderType;
use App\Enums\UserRole;
use App\Http\Resources\SupportCaseResource;
use App\Inbox\InboxIngestor;
use App\Models\BotFlow;
use App\Models\BotSetting;
use App\Models\ChannelAccount;
use App\Models\Conversation;
use App\Models\ConversationNote;
use App\Models\Fulfillment;
use App\Models\Message;
use App\Models\MessageAttachment;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\SupportCase;
use App\Models\User;
use App\Shopify\Client\ShopifyTransport;
use App\Shopify\Connection\ShopifyIntegration;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;

// The owner's return/exchange flow of 2026-09-19 (ReturnFlowUpgrade::definition()), end to end.

beforeEach(function () {
    Event::fake();
    Http::preventStrayRequests();
    config(['crm.drivers.ai' => 'fake']);
    ChannelAccount::factory()->create(['platform' => Platform::Facebook, 'external_id' => 'PAGE1']);
    BotSetting::current()->update(['enabled' => false, 'working_hours' => null]);
    app()->bind(FlowAnswerInterpreter::class, FakeFlowAnswerInterpreter::class);
    // No store lookups unless a test says so.
    app()->instance(RemoteProductLookup::class, new class implements RemoteProductLookup
    {
        public function byHandle(string $handle): ?Product
        {
            return null;
        }
    });
    $this->travelTo(CarbonImmutable::parse('2026-09-19 12:00', 'Africa/Cairo'));
});

function orfSay(string $text, ?string $payload = null): Conversation
{
    static $n = 0;
    $n++;
    app(InboxIngestor::class)->ingestMessage(new InboundMessageData(
        Platform::Facebook, 'PAGE1', 'PSID-ORF', 'Mona', 'orf'.$n.'-'.uniqid(), $text, CarbonImmutable::now(), payload: $payload,
    ));

    return Conversation::firstOrFail();
}

function orfTurn(string $text, ?string $payload = null): FlowResult
{
    $c = orfSay($text, $payload);

    return app(FlowEngine::class)->handle($c, app(ReplyScheduler::class)->burst($c));
}

function orfTap(string $step, string $value, string $title = 'x'): FlowResult
{
    return orfTurn($title, "step:return_exchange:{$step}:{$value}");
}

function orfPhoto(): MessageAttachment
{
    $c = Conversation::firstOrFail();
    $m = Message::factory()->for($c, 'conversation')->create(['direction' => 'in', 'sender_type' => 'customer', 'body' => null]);
    $a = MessageAttachment::factory()->create(['message_id' => $m->id]);
    app(FlowEngine::class)->handle($c->fresh(), app(ReplyScheduler::class)->burst($c->fresh()));

    return $a;
}

function orfFlow(): ?array
{
    return FlowState::flow(Conversation::firstOrFail());
}

function orfBot(): Message
{
    return Message::where('sender_type', SenderType::Bot->value)->latest('id')->firstOrFail();
}

/** @return list<string> */
function orfBodies(): array
{
    return Message::where('sender_type', SenderType::Bot->value)->orderBy('id')->pluck('body')->map(fn ($b) => (string) $b)->all();
}

/** @return list<string> the titles of the last bot message's buttons */
function orfButtons(): array
{
    return array_column((array) orfBot()->buttons, 'title');
}

/**
 * Order #1047 of سارة أحمد (mobile ending 4567), delivered on 12 September (7 days ago).
 *
 * @param  list<array<string, mixed>>  $items
 */
function orfOrder(array $items = [], string $delivered = '2026-09-12 13:00'): Order
{
    $order = Order::factory()->create([
        'order_number' => '1047',
        'shopify_order_name' => '#1047',
        'shipping_name' => 'سارة أحمد',
        'shipping_phone' => '+201001234567',
        'shipping_address' => '12 شارع التحرير',
        'placed_at' => CarbonImmutable::parse('2026-09-08 10:00', 'Africa/Cairo'),
    ]);

    Fulfillment::factory()->for($order)->create([
        'shipment_status' => 'delivered',
        'shopify_created_at' => CarbonImmutable::parse($delivered, 'Africa/Cairo')->subDays(2),
        'delivered_at' => CarbonImmutable::parse($delivered, 'Africa/Cairo'),
    ]);

    foreach ($items ?: [['title' => 'فستان ليلى', 'variant_title' => 'أسود / M', 'price' => 850]] as $item) {
        OrderItem::factory()->for($order)->create($item + ['discount' => 0, 'qty' => 1, 'price' => 500]);
    }

    return $order->fresh('items');
}

/** From the menu button to the greeting: order number, then the last 4 digits. */
function orfToGreeting(): void
{
    $c = orfSay('اهلا');
    expect(app(FlowEngine::class)->runPayload($c, 'flow:return_exchange'))->toBeTrue();
    orfTurn('1047');
    orfTurn('4567');
}

/** The synced catalog product "عباية كتان" (handle abaya-linen) with two sizes. */
function orfProduct(): Product
{
    $product = Product::factory()->create(['title' => 'عباية كتان', 'handle' => 'abaya-linen', 'image_url' => 'https://cdn.example/abaya.jpg']);
    ProductVariant::factory()->for($product)->create(['shopify_id' => '4001', 'title' => 'بيج / S', 'price' => 1200, 'image_url' => 'https://cdn.example/abaya-s.jpg']);
    ProductVariant::factory()->for($product)->create(['shopify_id' => '4002', 'title' => 'بيج / L', 'price' => 1250, 'image_url' => null]);

    return $product;
}

// ---- 1-3: menu, ownership, greeting -----------------------------------------------------------

it('starts from the main-menu button with the policy and the order question', function () {
    $c = orfSay('اهلا');
    app(FlowEngine::class)->start($c, 'main_menu');
    expect(orfButtons())->toContain('المرتجع والاستبدال')->toContain('كلم موظف');

    orfTurn('المرتجع والاستبدال', 'flow:return_exchange');

    expect(orfFlow()['step'])->toBe('order')
        ->and(orfBot()->body)->toBe('ممكن رقم الأوردر أو رقم الموبايل اللي اتعمل بيه الأوردر؟ 🌸')
        ->and(implode("\n", orfBodies()))->toContain('14 يوم');
});

it('greets her by the order customer first name once she proved the order, and not before', function () {
    orfOrder();
    $c = orfSay('اهلا');
    app(FlowEngine::class)->runPayload($c, 'flow:return_exchange');

    orfTurn('1047');
    expect(orfBot()->body)->toBe(OrderStep::VERIFY_TEXT);

    foreach (orfBodies() as $body) {
        expect($body)->not->toContain('سارة')->not->toContain('#1047')->not->toContain('فستان');
    }

    orfTurn('4567');

    expect(orfFlow()['step'])->toBe('kind')
        ->and(orfFlow()['data']['customer_first_name'])->toBe('سارة')
        ->and(orfFlow()['data']['order_window'])->toBe('open')
        ->and(orfBot()->body)->toBe('أهلاً يا سارة 🌸 لقيت أوردر #1047 — تحبي ترجعي ولا تبدلي؟')
        ->and(orfButtons())->toBe(['إرجاع', 'استبدال', 'القائمة الرئيسية']);
});

it('still hands over after 2 wrong digits, revealing nothing', function () {
    orfOrder();
    $c = orfSay('اهلا');
    app(FlowEngine::class)->runPayload($c, 'flow:return_exchange');
    orfTurn('1047');
    orfTurn('1111');
    orfTurn('2222');

    expect(orfFlow())->toBeNull()
        ->and(Conversation::first()->handler)->toBe(Handler::Human)
        // The refusal, then the handover's working-hours reply (flow 7; no hours set here).
        ->and(array_slice(orfBodies(), -2))->toBe([OrderStep::VERIFY_FAILED_TEXT, 'تمام ✅ حولتك لحد من الفريق، هيرد عليكي في أقرب وقت 🌸'])
        ->and(implode("\n", orfBodies()))->not->toContain('سارة')->not->toContain('أهلاً');
});

it('says the policy and offers a person when the order is past the 14 days', function () {
    orfOrder([], '2026-09-01 13:00');
    orfToGreeting();

    expect(orfFlow()['step'])->toBe('late')
        ->and(orfFlow()['data']['order_window'])->toBe('closed')
        ->and(orfBot()->body)->toBe(ReturnFlowUpgrade::LATE_TEXT)
        ->and(orfButtons())->toBe(['كلم موظف', 'القائمة الرئيسية'])
        ->and(implode("\n", orfBodies()))->not->toContain('ترجعي ولا تبدلي');

    orfTap('late', 'agent', 'كلم موظف');

    expect(orfFlow())->toBeNull()
        ->and(Conversation::first()->handler)->toBe(Handler::Human)
        ->and(SupportCase::count())->toBe(0);
});

it('asks return-or-exchange without the greeting when the order is not found', function () {
    orfSay('اهلا');
    app(FlowEngine::class)->runPayload(Conversation::firstOrFail(), 'flow:return_exchange');
    orfTurn('9999');
    orfTurn('9999');

    expect(orfFlow()['step'])->toBe('kind_unknown')
        ->and(orfBot()->body)->toBe('تحبي ترجعي ولا تبدلي؟ 🌸');

    orfTurn('عايزة ارجع');
    expect(orfFlow()['step'])->toBe('return_items')
        ->and(orfBot()->body)->toBe(OrderItemsStep::FALLBACK_TEXT);

    orfTurn('الفستان الاسود');
    orfTap('return_reason', 'size');
    orfPhoto();

    $case = SupportCase::sole();
    expect($case->type)->toBe('return')
        ->and($case->order_id)->toBeNull()
        ->and($case->data['order_ref_text'])->toBe('9999')
        // No order number: the case number stands in for it.
        ->and(orfBot()->body)->toBe(str_replace('{order_number}', (string) $case->id, ReturnFlowUpgrade::RETURN_DONE_TEXT));
});

// ---- 4: the return branch -----------------------------------------------------------------------

it('records a return: item, reason, photo — no summary — and answers with the order number', function () {
    $order = orfOrder();
    orfToGreeting();

    orfTap('kind', 'return', 'إرجاع');
    expect(orfFlow()['step'])->toBe('return_items')
        ->and(orfFlow()['data']['request_kind'])->toBe('return')
        ->and(orfBot()->body)->toBe("1. فستان ليلى — أسود / M × 1 — 850 ج.م\n\nاختاري القطعة اللي عايزة ترجعيها 👇");

    orfTap('return_items', 'item:'.$order->items[0]->id, 'فستان ليلى');
    expect(orfFlow()['step'])->toBe('return_reason')
        ->and(orfBot()->body)->toBe('إيه سبب المرتجع؟')
        ->and(orfButtons())->toBe(['بايظ / فيه عيب', 'غلط في الأوردر', 'قطعة ناقصة', 'المقاس مش مظبوط', 'مش عاجبني', 'القائمة الرئيسية']);

    orfTap('return_reason', 'defective', 'بايظ / فيه عيب');
    expect(orfFlow()['step'])->toBe('return_photo')
        ->and(orfBot()->body)->toBe('ابعتيلي صورة للقطعة 📸');

    $photo = orfPhoto();

    $case = SupportCase::sole();
    $c = Conversation::firstOrFail();
    expect($case->type)->toBe('return')
        ->and($case->typeLabel())->toBe('مرتجع')
        ->and($case->priority)->toBe('high')
        ->and($case->order_id)->toBe($order->id)
        ->and($case->order_number)->toBe('#1047')
        ->and($case->data['request_kind'])->toBe('return')
        ->and($case->data['reason'])->toBe('defective')
        ->and($case->data['selected_items'][0]['title'])->toBe('فستان ليلى')
        ->and($case->data['product_photo'])->toBe([$photo->id])
        ->and($case->photo_attachment_ids)->toBe([$photo->id])
        ->and($case->data)->not->toHaveKey('items_pending')
        ->and(orfBot()->body)->toBe('تمام ✅ تم تقديم طلب المرتجع بنجاح، ورقم طلبك هو نفس رقم الأوردر #1047. هنتواصل معاكي أول ما المندوب يتحرك لاستلام المرتجع 🌸')
        ->and(implode("\n", orfBodies()))->not->toContain('ملخص')
        ->and(FlowState::flow($c))->toBeNull()
        ->and($c->handler)->toBe(Handler::Bot);

    $text = CaseSummary::text($case);
    expect($text)->toContain('مرتجع')->toContain('الطلب: مرتجع')->toContain('السبب: بايظ / فيه عيب')
        ->toContain('فستان ليلى — أسود / M × 1 — 850 ج.م')->toContain('صورة القطعة ✅')
        ->and(ConversationNote::where('conversation_id', $c->id)->where('body', $text)->exists())->toBeTrue();
});

it('asks for the photo once more when she writes instead, then records the return without it', function () {
    $order = orfOrder();
    orfToGreeting();
    orfTap('kind', 'return');
    orfTap('return_items', 'item:'.$order->items[0]->id);
    orfTap('return_reason', 'size');

    orfTurn('مش معايا صورة');
    expect(orfFlow()['step'])->toBe('return_photo')
        ->and(orfBot()->body)->toBe('ابعتيلي صورة للقطعة 📸')
        ->and(SupportCase::count())->toBe(0);

    orfTurn('مش هقدر اصور');
    $case = SupportCase::sole();
    expect($case->data['product_photo_missing'])->toBeTrue()
        ->and($case->photo_attachment_ids)->toBe([])
        ->and(CaseSummary::text($case))->toContain('صورة القطعة — (مبعتتش صورة)');
});

it('refuses a discounted piece for a return and switches the request to an exchange when she wants', function () {
    $order = orfOrder([
        ['title' => 'فستان ليلى', 'price' => 850, 'discount' => 100],
        ['title' => 'عباية سادة', 'price' => 900],
    ]);
    orfToGreeting();
    orfTap('kind', 'return');

    orfTap('return_items', 'item:'.$order->items[0]->id);

    $bodies = orfBodies();
    expect($bodies[count($bodies) - 2])->toBe('«فستان ليلى» عليها خصم، فمينفعش ترجع بس ممكن تتبدل 🌸')
        ->and(orfBot()->body)->toBe(OrderItemsStep::SWITCH_QUESTION)
        ->and(orfButtons())->toBe([OrderItemsStep::SWITCH_BUTTON, OrderItemsStep::OTHER_BUTTON, OrderItemsStep::DONE_BUTTON])
        ->and(orfFlow()['data']['selected_items'])->toBe([]);

    orfTurn('أيوه');

    $flow = orfFlow();
    expect($flow['data']['request_kind'])->toBe('exchange')
        ->and($flow['data']['request_kind_title'])->toBe('استبدال')
        ->and($flow['data']['selected_items'][0]['title'])->toBe('فستان ليلى')
        ->and($flow['data']['selected_items'][0]['exchange_only'])->toBeTrue()
        ->and(implode("\n", orfBodies()))->toContain(OrderItemsStep::SWITCHED_TEXT)
        ->and(orfBot()->body)->toContain(OrderItemsStep::MORE_QUESTION);

    orfTap('return_items', 'done', 'لأ كده تمام');
    expect(orfFlow()['step'])->toBe('exchange_reason')
        ->and(orfBot()->body)->toBe('إيه سبب الاستبدال؟');
});

it('lets her keep returning the other pieces after refusing a discounted one', function () {
    $order = orfOrder([
        ['title' => 'فستان ليلى', 'price' => 850, 'discount' => 100],
        ['title' => 'عباية سادة', 'price' => 900],
    ]);
    orfToGreeting();
    orfTap('kind', 'return');
    orfTap('return_items', 'item:'.$order->items[0]->id);
    orfTap('return_items', 'more', 'قطعة تانية');
    orfTap('return_items', 'item:'.$order->items[1]->id);

    expect(orfFlow()['step'])->toBe('return_reason')
        ->and(orfFlow()['data']['request_kind'])->toBe('return')
        ->and(array_column(orfFlow()['data']['selected_items'], 'title'))->toBe(['عباية سادة']);
});

// ---- 5: the exchange branch ---------------------------------------------------------------------

/** Greeting → استبدال → the item → reason "المقاس": now waiting for the link. */
function orfToLink(): Order
{
    $order = orfOrder();
    orfToGreeting();
    orfTap('kind', 'exchange', 'استبدال');
    expect(orfBot()->body)->toContain('اختاري القطعة اللي عايزة تبدليها 👇');
    orfTap('exchange_items', 'item:'.$order->items[0]->id);
    expect(orfBot()->body)->toBe('إيه سبب الاستبدال؟')
        ->and(orfButtons())->toBe(['المقاس', 'اللون', 'الموديل', 'فيه عيب', 'حاجة تانية', 'القائمة الرئيسية']);
    orfTap('exchange_reason', 'size', 'المقاس');
    expect(orfFlow()['step'])->toBe('exchange_product')
        ->and(orfBot()->body)->toBe('ابعتيلي لينك المنتج اللي عايزة تبدلي بيه من الموقع 🔗 (من levoilestores.com)');

    return $order;
}

it('records an exchange with the product found by its link in the synced catalog', function () {
    $product = orfProduct();
    $order = orfToLink();

    orfTurn('ده اللينك https://levoilestores.com/products/abaya-linen');

    $case = SupportCase::sole();
    $c = Conversation::firstOrFail();
    expect($case->type)->toBe('exchange')
        ->and($case->typeLabel())->toBe('استبدال')
        ->and($case->order_id)->toBe($order->id)
        ->and($case->data['request_kind'])->toBe('exchange')
        ->and($case->data['reason'])->toBe('size')
        ->and($case->data['reason_title'])->toBe('المقاس')
        ->and($case->data['selected_items'][0]['title'])->toBe('فستان ليلى')
        ->and($case->data['exchange_product'])->toEqual([
            'title' => 'عباية كتان', 'handle' => 'abaya-linen', 'url' => 'https://levoilestores.com/products/abaya-linen',
            'price' => 1200.0, 'image' => 'https://cdn.example/abaya.jpg', 'variant_title' => null, 'variant_id' => null,
            'product_id' => $product->id, 'source' => 'catalog',
        ])
        ->and(orfBot()->body)->toBe('تمام ✅ تم تسجيل طلب الاستبدال بـ «عباية كتان». هنتواصل معاكي لتأكيد الاستبدال والإرسال 🌸')
        ->and(FlowState::flow($c))->toBeNull();

    expect(ConversationNote::where('conversation_id', $c->id)->where('body', 'طلب استبدال: فستان ليلى (أسود / M) × 1 ← عباية كتان — 1,200 ج.م — https://levoilestores.com/products/abaya-linen')->exists())->toBeTrue()
        ->and(CaseSummary::text($case))->toContain('الطلب: استبدال')->toContain('السبب: المقاس')
        ->toContain('البديل: عباية كتان — 1,200 ج.م')->toContain('اللينك: https://levoilestores.com/products/abaya-linen');

    $json = (new SupportCaseResource($case))->toArray(Request::create('/'));
    expect($json['request_kind'])->toBe('exchange')
        ->and($json['reason'])->toBe('المقاس')
        ->and($json['exchange_product'])->toBe([
            'title' => 'عباية كتان', 'handle' => 'abaya-linen', 'url' => 'https://levoilestores.com/products/abaya-linen',
            'price' => 1200.0, 'image' => 'https://cdn.example/abaya.jpg', 'variant_title' => null,
        ])
        ->and($json['items'][0]['title'])->toBe('فستان ليلى');
});

it('reads a collection link with ?variant= and keeps that size and price', function () {
    orfProduct();
    orfToLink();

    orfTurn('levoilestores.com/collections/abayas/products/Abaya-Linen?variant=4002.');

    $p = SupportCase::sole()->data['exchange_product'];
    expect($p['title'])->toBe('عباية كتان')
        ->and($p['variant_title'])->toBe('بيج / L')
        ->and($p['variant_id'])->toBe('4002')
        ->and($p['price'])->toEqual(1250)
        ->and($p['image'])->toBe('https://cdn.example/abaya.jpg')
        ->and($p['url'])->toBe('levoilestores.com/collections/abayas/products/Abaya-Linen?variant=4002');

    expect(ConversationNote::where('body', 'like', 'طلب استبدال:%')->sole()->body)
        ->toBe('طلب استبدال: فستان ليلى (أسود / M) × 1 ← عباية كتان (بيج / L) — 1,250 ج.م — https://levoilestores.com/collections/abayas/products/Abaya-Linen?variant=4002');
});

it('asks again once for an unknown link, then keeps what she wrote and records the exchange', function () {
    orfToLink();

    orfTurn('https://levoilestores.com/products/not-a-product');
    expect(orfFlow()['step'])->toBe('exchange_product')
        ->and(orfBot()->body)->toBe(ProductLinkStep::RETRY_TEXT)
        ->and(SupportCase::count())->toBe(0);

    orfTurn('العباية البيج اللي في الصفحة الأولى');

    $case = SupportCase::sole();
    expect($case->type)->toBe('exchange')
        ->and($case->data)->not->toHaveKey('exchange_product')
        ->and($case->data['exchange_product_text'])->toBe("https://levoilestores.com/products/not-a-product\nالعباية البيج اللي في الصفحة الأولى")
        ->and(orfBot()->body)->toBe('تمام ✅ تم تسجيل طلب الاستبدال بـ «'.FlowPrompter::UNKNOWN_PRODUCT.'». هنتواصل معاكي لتأكيد الاستبدال والإرسال 🌸')
        ->and(CaseSummary::text($case))->toContain('البديل: مش متحدد — العميلة كتبت')
        ->and(ConversationNote::where('body', 'like', 'طلب استبدال:%')->sole()->body)->toStartWith('طلب استبدال: فستان ليلى (أسود / M) × 1 ← المنتج مش متحدد، العميلة كتبت:');
});

it('asks again for text without a link too', function () {
    orfToLink();

    orfTurn('عايزة العباية الكتان');
    expect(orfBot()->body)->toBe(ProductLinkStep::RETRY_TEXT);

    orfProduct();
    orfTurn('https://levoilestores.com/products/abaya-linen');
    expect(SupportCase::sole()->data['exchange_product']['title'])->toBe('عباية كتان')
        ->and(SupportCase::sole()->data)->not->toHaveKey('exchange_product_text');
});

it('accepts a screenshot instead of the link', function () {
    orfToLink();

    $shot = orfPhoto();

    $case = SupportCase::sole();
    expect($case->type)->toBe('exchange')
        ->and($case->data['exchange_product_photo'])->toBe([$shot->id])
        ->and($case->photo_attachment_ids)->toBe([$shot->id])
        ->and(orfBot()->body)->toContain('«'.FlowPrompter::UNKNOWN_PRODUCT.'»')
        ->and(CaseSummary::text($case))->toContain('البديل: العميلة بعتت صورة للمنتج')->toContain('صورة المنتج البديل ✅')
        ->and(ConversationNote::where('body', 'like', 'طلب استبدال:%')->sole()->body)->toContain('بعتت صورة للمنتج البديل');
});

it('falls back to the store for a product that is not synced yet', function () {
    app()->instance(RemoteProductLookup::class, new class implements RemoteProductLookup
    {
        public function byHandle(string $handle): ?Product
        {
            if ($handle !== 'new-abaya') {
                return null;
            }

            $p = Product::factory()->create(['title' => 'عباية جديدة', 'handle' => 'new-abaya']);
            ProductVariant::factory()->for($p)->create(['price' => 990]);

            return $p;
        }
    });
    orfToLink();

    orfTurn('https://levoilestores.com/products/new-abaya');

    $p = SupportCase::sole()->data['exchange_product'];
    expect($p['title'])->toBe('عباية جديدة')->and($p['price'])->toEqual(990)->and($p['source'])->toBe('shopify');
});

// ---- Shopify fallback and link parsing ----------------------------------------------------------

it('looks a product up by handle in Shopify and saves it to the catalog', function () {
    ShopifyIntegration::create(['shop_domain' => 'd.myshopify.com', 'access_token' => 't', 'api_secret' => 's', 'status' => 'connected']);
    $transport = new class implements ShopifyTransport
    {
        public array $bodies = [];

        public function post(string $url, array $headers, array $body): array
        {
            $this->bodies[] = $body;
            $found = ($body['variables']['handle'] ?? null) === 'silk-scarf';

            return ['status' => 200, 'json' => ['data' => ['productByIdentifier' => $found ? [
                'id' => 'gid://shopify/Product/77', 'title' => 'طرحة حرير', 'handle' => 'silk-scarf', 'status' => 'ACTIVE',
                'productType' => 'طرح', 'tags' => [], 'updatedAt' => '2026-09-18T10:00:00Z', 'featuredImage' => ['url' => 'https://cdn.example/scarf.jpg'],
                'variants' => ['nodes' => [['id' => 'gid://shopify/ProductVariant/88', 'title' => 'Default Title', 'price' => '350.00', 'inventoryItem' => ['id' => 'gid://shopify/InventoryItem/1', 'requiresShipping' => true]]]],
            ] : null]]];
        }
    };
    app()->instance(ShopifyTransport::class, $transport);

    $lookup = app(ShopifyRemoteProductLookup::class);
    $product = $lookup->byHandle('silk-scarf');

    expect($product)->not->toBeNull()
        ->and($product->title)->toBe('طرحة حرير')
        ->and($product->shopify_id)->toBe('77')
        ->and(Product::where('handle', 'silk-scarf')->count())->toBe(1)
        ->and($lookup->byHandle('nothing-here'))->toBeNull()
        ->and($transport->bodies[0]['query'])->toContain('productByIdentifier')
        ->and($transport->bodies[0]['variables'])->toBe(['handle' => 'silk-scarf']);

    // Not connected: no call at all.
    ShopifyIntegration::query()->update(['status' => 'error']);
    expect(app(ShopifyRemoteProductLookup::class)->byHandle('silk-scarf'))->toBeNull()
        ->and($transport->bodies)->toHaveCount(2);
});

it('parses product links in every shape she may send', function (string $text, ?array $expected) {
    expect(app(ExchangeProducts::class)->parse($text))->toBe($expected);
})->with([
    'plain' => ['https://levoilestores.com/products/abaya-linen', ['url' => 'https://levoilestores.com/products/abaya-linen', 'handle' => 'abaya-linen', 'variant_id' => null]],
    'no scheme, www' => ['www.levoilestores.com/products/abaya-linen', ['url' => 'www.levoilestores.com/products/abaya-linen', 'handle' => 'abaya-linen', 'variant_id' => null]],
    'collection + variant' => ['شوفي ده https://levoilestores.com/collections/new/products/Abaya-Linen?variant=123&utm=x', ['url' => 'https://levoilestores.com/collections/new/products/Abaya-Linen?variant=123&utm=x', 'handle' => 'abaya-linen', 'variant_id' => '123']],
    'arabic handle' => ['https://levoilestores.com/products/%D8%B9%D8%A8%D8%A7%D9%8A%D8%A9', ['url' => 'https://levoilestores.com/products/%D8%B9%D8%A8%D8%A7%D9%8A%D8%A9', 'handle' => 'عباية', 'variant_id' => null]],
    'not a product page' => ['https://levoilestores.com/collections/new', null],
    'no link' => ['عايزة العباية الكتان', null],
]);

// ---- designer ---------------------------------------------------------------------------------------

it('validates the product_link step type for the designer', function () {
    $def = fn (array $step) => ['start' => 'link', 'steps' => ['link' => $step]];

    expect(FlowDefinition::validate($def(['type' => 'product_link', 'field' => 'exchange_product', 'text' => 'ابعتي اللينك', 'next' => 'end'])))->toBe([])
        ->and(FlowDefinition::validate($def(['type' => 'product_link', 'next' => 'end'])))->toBe(["step 'link' of type 'product_link' requires a 'field'"])
        ->and(FlowDefinition::validate($def(['type' => 'product_link', 'field' => 'x'])))->toBe(["step 'link' next must be a non-empty string"])
        ->and(FlowDefinition::validate($def(['type' => 'record_case', 'case_type' => 'exchange', 'text' => 'تمام #{order_number}', 'next' => 'end'])))->toBe([])
        ->and(FlowDefinition::validate($def(['type' => 'record_case', 'case_type' => 'return', 'next' => 'end'])))->toBe([])
        ->and(FlowStepCatalog::all()['product_link'])->toBe([
            'label_ar' => 'لينك منتج للتبديل', 'icon' => 'Link', 'color' => 'amber', 'fields' => ['text', 'field'], 'options' => 'none', 'has_next' => true,
        ])
        ->and(FlowStepCatalog::all()['record_case']['fields'])->toBe(['case_type', 'text', 'script']);
});

it('serves the new type and case types to the flow designer', function () {
    $admin = User::factory()->create(['role' => UserRole::Admin]);
    $flow = BotFlow::where('key', 'return_exchange')->firstOrFail();

    $this->actingAs($admin)->get('/settings/bot-flows')
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('stepTypes.product_link.label_ar', 'لينك منتج للتبديل')->etc());

    $this->actingAs($admin)->getJson("/settings/bot-flows/{$flow->id}")
        ->assertOk()
        ->assertJsonPath('data.published.steps.exchange_product.type', 'product_link')
        ->assertJsonPath('data.draft', null)
        ->assertJsonPath('data.errors', [])
        ->assertJsonPath('data.warnings', []);
});

// ---- sandbox ------------------------------------------------------------------------------------------

function orfSandbox(?array $state, array $input): array
{
    return app(FlowSandbox::class)->run(BotFlow::where('key', 'return_exchange')->firstOrFail(), 'published', $state, $input, User::factory()->create(['role' => UserRole::Supervisor]));
}

it('walks both branches in the designer sandbox and saves nothing', function () {
    $order = orfOrder();
    orfProduct();
    $texts = fn (array $r) => implode("\n", array_column($r['messages'], 'text'));

    $start = orfSandbox(null, []);
    $r = orfSandbox($start['state'], ['text' => '1047']);
    $r = orfSandbox($r['state'], ['text' => '4567']);
    expect($r['current']['step_id'])->toBe('kind')
        ->and($texts($r))->toBe('أهلاً يا سارة 🌸 لقيت أوردر #1047 — تحبي ترجعي ولا تبدلي؟');
    $greeting = $r['state'];

    // إرجاع
    $r = orfSandbox($greeting, ['payload' => 'step:return_exchange:kind:return']);
    $r = orfSandbox($r['state'], ['payload' => 'step:return_exchange:return_items:item:'.$order->items[0]->id]);
    $r = orfSandbox($r['state'], ['payload' => 'step:return_exchange:return_reason:wrong_item']);
    expect($r['current']['step_id'])->toBe('return_photo');
    $r = orfSandbox($r['state'], ['photo' => true]);
    $case = collect($r['events'])->firstWhere('type', 'case');
    expect($case['label'])->toBe('هيتسجل حالة: مرتجع')
        ->and($case['data']['product_photo'])->toBe(['legacy'])
        ->and($texts($r))->toContain('رقم طلبك هو نفس رقم الأوردر #1047')
        ->and($r['current'])->toBeNull();

    // استبدال
    $r = orfSandbox($greeting, ['payload' => 'step:return_exchange:kind:exchange']);
    $r = orfSandbox($r['state'], ['payload' => 'step:return_exchange:exchange_items:item:'.$order->items[0]->id]);
    $r = orfSandbox($r['state'], ['payload' => 'step:return_exchange:exchange_reason:color']);
    expect($r['current']['step_id'])->toBe('exchange_product');
    $r = orfSandbox($r['state'], ['text' => 'https://levoilestores.com/products/abaya-linen?variant=4001']);
    $case = collect($r['events'])->firstWhere('type', 'case');
    expect($case['label'])->toBe('هيتسجل حالة: استبدال')
        ->and($case['data']['exchange_product']['variant_title'])->toBe('بيج / S')
        ->and($texts($r))->toBe('تمام ✅ تم تسجيل طلب الاستبدال بـ «عباية كتان». هنتواصل معاكي لتأكيد الاستبدال والإرسال 🌸');

    expect(SupportCase::count())->toBe(0)->and(Conversation::count())->toBe(0)->and(ConversationNote::count())->toBe(0);
});
