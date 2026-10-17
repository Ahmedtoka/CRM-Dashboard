<?php

use App\Bot\Flow\ReplyScheduler;
use App\Bot\Flows\FakeFlowAnswerInterpreter;
use App\Bot\Flows\FlowAnswerInterpreter;
use App\Bot\Flows\FlowEngine;
use App\Bot\Flows\FlowState;
use App\Bot\Flows\Returns\RemoteProductLookup;
use App\Bot\Flows\Returns\ReturnPrefill;
use App\Bot\Flows\Steps\OrderStep;
use App\Channels\Data\InboundMessageData;
use App\Enums\Platform;
use App\Enums\SenderType;
use App\Inbox\InboxIngestor;
use App\Models\BotSetting;
use App\Models\ChannelAccount;
use App\Models\Conversation;
use App\Models\Fulfillment;
use App\Models\Message;
use App\Models\MessageAttachment;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\SupportCase;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;

// Bot hybrid P1 (bot-hybrid.md §2, scenarios 21–28): a return/exchange started from her own
// words starts filled — no order number, no «ترجعي ولا تبدلي؟», the pieces she named picked.

beforeEach(function () {
    Event::fake();
    Http::preventStrayRequests();
    config(['crm.drivers.ai' => 'fake']);
    ChannelAccount::factory()->create(['platform' => Platform::Facebook, 'external_id' => 'PAGE1']);
    BotSetting::current()->update(['enabled' => false, 'working_hours' => null]);
    app()->bind(FlowAnswerInterpreter::class, FakeFlowAnswerInterpreter::class);
    app()->instance(RemoteProductLookup::class, new class implements RemoteProductLookup
    {
        public function byHandle(string $handle): ?Product
        {
            return null;
        }
    });
    $this->travelTo(CarbonImmutable::parse('2026-09-19 12:00', 'Africa/Cairo'));
});

function mrSay(string $text, ?string $payload = null): Conversation
{
    app(InboxIngestor::class)->ingestMessage(new InboundMessageData(
        Platform::Facebook, 'PAGE1', 'PSID-MR', 'Mona', 'mr-'.uniqid(), $text, CarbonImmutable::now(), payload: $payload,
    ));

    return Conversation::firstOrFail();
}

function mrTurn(string $text, ?string $payload = null): void
{
    $c = mrSay($text, $payload);
    app(FlowEngine::class)->handle($c, app(ReplyScheduler::class)->burst($c));
}

function mrTap(string $step, string $value): void
{
    mrTurn('x', "step:return_exchange:{$step}:{$value}");
}

function mrPhoto(): void
{
    $c = Conversation::firstOrFail();
    $m = Message::factory()->for($c, 'conversation')->create(['direction' => 'in', 'sender_type' => 'customer', 'body' => null]);
    MessageAttachment::factory()->create(['message_id' => $m->id]);
    app(FlowEngine::class)->handle($c->fresh(), app(ReplyScheduler::class)->burst($c->fresh()));
}

/** Starts the return flow the way the agent / orchestrator does: with what her message said. */
function mrStart(string $text): Conversation
{
    $c = mrSay($text);
    app(FlowEngine::class)->start($c, 'return_exchange', ReturnPrefill::flowData(ReturnPrefill::fromText($text)));

    return $c->refresh();
}

function mrFlow(): ?array
{
    return FlowState::flow(Conversation::firstOrFail());
}

/** @return list<string> */
function mrBodies(): array
{
    return Message::where('sender_type', SenderType::Bot->value)->orderBy('id')->pluck('body')->map(fn ($b) => (string) $b)->all();
}

function mrLast(): string
{
    return (string) Message::where('sender_type', SenderType::Bot->value)->latest('id')->value('body');
}

/** Order $number (mobile ending 4567), delivered 7 days ago, with these pieces. */
function mrOrder(string $number, array $items): Order
{
    $order = Order::factory()->create([
        'order_number' => $number, 'shopify_order_name' => '#'.$number, 'shipping_name' => 'سارة أحمد',
        'shipping_phone' => '+201001234567', 'placed_at' => CarbonImmutable::parse('2026-09-08 10:00', 'Africa/Cairo'),
    ]);
    Fulfillment::factory()->for($order)->create([
        'shipment_status' => 'delivered', 'shopify_created_at' => now()->subDays(9), 'delivered_at' => now()->subDays(7),
    ]);

    foreach ($items as $item) {
        OrderItem::factory()->for($order)->create($item + ['discount' => 0, 'qty' => 1, 'price' => 500, 'variant_title' => null]);
    }

    return $order->fresh('items');
}

/** @return list<array{title:string, action:?string, exchange_to:?string}> */
function mrPicked(): array
{
    return array_map(fn ($r) => ['title' => $r['title'], 'action' => $r['action'] ?? null, 'exchange_to' => $r['exchange_to'] ?? null], mrFlow()['data']['selected_items'] ?? []);
}

it('#21 picks both named pieces of the named order without asking the order or the kind again', function () {
    mrOrder('209129', [['title' => 'فستان ستان اسود'], ['title' => 'طرحة شيفون'], ['title' => 'بلوزة كتان']]);

    mrStart('عايزة ارجع الفستان الاسود والطرحة من اوردر 209129');

    // Ownership is still proven: the order she named is looked up at once, then the last 4 digits.
    expect(mrLast())->toBe(OrderStep::VERIFY_TEXT)
        ->and(implode("\n", mrBodies()))->not->toContain('ممكن رقم الأوردر');

    mrTurn('4567');

    expect(mrFlow()['step'])->toBe('return_items')
        ->and(mrPicked())->toBe([
            ['title' => 'فستان ستان اسود', 'action' => 'return', 'exchange_to' => null],
            ['title' => 'طرحة شيفون', 'action' => 'return', 'exchange_to' => null],
        ])
        ->and(mrLast())->toContain('تحبي تضيفي قطعة تانية')
        ->and(implode("\n", mrBodies()))->not->toContain('ترجعي ولا تبدلي');
});

it('#21 goes straight on when the named pieces are the whole order', function () {
    mrOrder('209129', [['title' => 'فستان ستان اسود'], ['title' => 'طرحة شيفون']]);

    mrStart('عايزة ارجع الفستان الاسود والطرحة من اوردر 209129');
    mrTurn('4567');

    expect(mrFlow()['step'])->toBe('return_reason')
        ->and(count(mrPicked()))->toBe(2);
});

it('#23 records a return and an exchange of one order as ONE request, each piece with its own action', function () {
    mrOrder('209129', [['title' => 'بلوزة كتان'], ['title' => 'جيبة بليسيه']]);

    mrStart('عايزة ارجع البلوزة وابدل الجيبة بمقاس اكبر اوردر 209129');
    mrTurn('4567');

    expect(mrPicked())->toBe([
        ['title' => 'بلوزة كتان', 'action' => 'return', 'exchange_to' => null],
        ['title' => 'جيبة بليسيه', 'action' => 'exchange', 'exchange_to' => 'مقاس اكبر'],
    ])->and(mrFlow()['step'])->toBe('mixed_reason');

    mrTap('mixed_reason', 'size');
    mrPhoto();
    expect(mrFlow()['step'])->toBe('mixed_exchange_product');

    mrTap('mixed_exchange_product', 'send');
    mrTurn('مش عارفة اللينك');
    mrTurn('مش معايا');

    $case = SupportCase::sole();
    expect($case->type)->toBe('return_exchange')
        ->and(array_column($case->data['selected_items'], 'action'))->toBe(['return', 'exchange'])
        ->and(mrFlow())->toBeNull();
});

it('#23 asks «ترجعيها ولا تبدليها؟» for a mixed piece she did not say', function () {
    mrOrder('209129', [['title' => 'بلوزة كتان'], ['title' => 'جيبة بليسيه'], ['title' => 'عباية سودا']]);

    mrStart('عايزة ارجع البلوزة وابدل الجيبة اوردر 209129');
    mrTurn('4567');
    // She adds the third piece by tapping it: its action is asked.
    mrTap('mixed_items', 'more');
    $abaya = Order::sole()->items->firstWhere('title', 'عباية سودا');
    mrTap('mixed_items', 'item:'.$abaya->id);

    expect(mrLast())->toContain('ترجعيها ولا تبدليها');

    mrTurn('ابدلها');

    expect(array_column(mrPicked(), 'action'))->toBe(['return', 'exchange', 'exchange']);
});

it('#24 reads «2 قطع» as a count and shows the list instead of picking piece 2', function () {
    mrOrder('209129', [['title' => 'بلوزة كتان'], ['title' => 'جيبة بليسيه'], ['title' => 'عباية سودا']]);

    mrStart('عايزة ارجع 2 قطع من الاوردر 209129');
    mrTurn('4567');

    expect(mrFlow()['step'])->toBe('return_items')
        ->and(mrFlow()['data']['selected_items'] ?? null)->toBeNull();
});

it('#25 «ارجع الكل» takes every returnable piece and refuses the accessory', function () {
    mrOrder('209300', [['title' => 'فستان ستان'], ['title' => 'اكسسوار بروش ذهبي']]);

    mrStart('ارجع الكل من اوردر 209300');
    mrTurn('4567');

    expect(array_column(mrPicked(), 'title'))->toBe(['فستان ستان'])
        ->and(implode("\n", mrBodies()))->toContain('من الإكسسوارات');
});

it('#27 asks the replacement for each exchanged piece in turn', function () {
    mrOrder('209129', [['title' => 'بلوزة كتان'], ['title' => 'جيبة بليسيه']]);
    $p = Product::factory()->create(['title' => 'عباية كتان', 'handle' => 'abaya-linen', 'status' => 'active']);
    $p->variants()->create(['shopify_id' => 'v9', 'title' => 'M', 'price' => 900, 'inventory_quantity' => 3]);

    mrStart('عايزة ابدل الجيبة والبلوزة بمقاس M الاتنين اوردر 209129');
    mrTurn('4567');
    mrTap('exchange_reason', 'size');

    expect(mrFlow()['step'])->toBe('exchange_product')
        ->and(mrLast())->toContain('جيبة بليسيه');

    mrTap('exchange_product', 'product:'.$p->id);

    expect(mrFlow()['step'])->toBe('exchange_product')
        ->and(mrLast())->toContain('بلوزة كتان');

    mrTap('exchange_product', 'product:'.$p->id);

    $case = SupportCase::sole();
    expect($case->type)->toBe('exchange')
        ->and(count($case->data['exchange_products']))->toBe(2);
});

it('asks the order question as usual when the order she named is not found', function () {
    mrStart('عايزة ارجع اوردر 555555');

    expect(mrFlow()['step'])->toBe('order')
        ->and(mrLast())->toBe('ممكن رقم الأوردر أو رقم الموبايل اللي اتعمل بيه الأوردر؟');
});

it('a typed answer naming both return and exchange at the kind question means both, not the first synonym (R4)', function () {
    mrOrder('209129', [['title' => 'بلوزة كتان'], ['title' => 'جيبة بليسيه']]);
    $c = mrSay('اهلا');
    app(FlowEngine::class)->runPayload($c, 'flow:return_exchange');
    mrTurn('209129');
    mrTurn('4567');
    expect(mrFlow()['step'])->toBe('kind');

    mrTurn('عايزة ارجع حاجة وابدل حاجة');

    expect(mrFlow()['step'])->toBe('mixed_items')
        ->and(mrFlow()['data']['request_kind'])->toBe('mixed');
});

it('still shows only «إرجاع» and «استبدال» at the kind question', function () {
    mrOrder('209129', [['title' => 'بلوزة كتان']]);
    $c = mrSay('اهلا');
    app(FlowEngine::class)->runPayload($c, 'flow:return_exchange');
    mrTurn('209129');
    mrTurn('4567');

    expect(array_column((array) Message::where('sender_type', SenderType::Bot->value)->latest('id')->first()->buttons, 'title'))
        ->toBe(['إرجاع', 'استبدال', 'القائمة الرئيسية']);
});
