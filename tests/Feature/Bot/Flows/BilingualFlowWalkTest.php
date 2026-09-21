<?php

use App\Bot\Flow\ReplyScheduler;
use App\Bot\Flows\FakeFlowAnswerInterpreter;
use App\Bot\Flows\FlowAnswerInterpreter;
use App\Bot\Flows\FlowEngine;
use App\Bot\Flows\FlowResult;
use App\Bot\Flows\FlowState;
use App\Bot\Language\ConversationLanguage;
use App\Bot\Language\KeptNames;
use App\Channels\Cards\OutboundCards;
use App\Channels\Data\InboundMessageData;
use App\Enums\Platform;
use App\Enums\SenderType;
use App\Inbox\InboxIngestor;
use App\Models\BotSetting;
use App\Models\Branch;
use App\Models\ChannelAccount;
use App\Models\Conversation;
use App\Models\Fulfillment;
use App\Models\Message;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\SupportCase;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;

/**
 * Design 2026-09-21 §7: every flow walked end to end in Arabic AND in English.
 *
 * Each walk asserts the four things the design asks for:
 *   1. the bot never sends the same question twice, word for word;
 *   2. an English run holds no Arabic outside the names the design excepts
 *      (product names, branch names and their addresses);
 *   3. no button title is longer than Messenger's 20 characters;
 *   4. the request that comes out of the flow carries the right data.
 *
 * The English comes from the seeded translations
 * (database/seeders/data/bot_translations_en.php, loaded by its migration). Anything the
 * seed misses falls to the offline engine, which answers «en#…» — so `walkEnglish()`
 * failing on «en#» means a text the bot can say has no English yet, which is exactly
 * what this test is for.
 */
beforeEach(function () {
    Event::fake();
    Http::preventStrayRequests();
    config(['crm.drivers.ai' => 'fake']);
    ChannelAccount::factory()->create(['platform' => Platform::Facebook, 'external_id' => 'PAGE1']);
    BotSetting::current()->update(['enabled' => false, 'working_hours' => null]);
    app()->bind(FlowAnswerInterpreter::class, FakeFlowAnswerInterpreter::class);
    KeptNames::reset();
    $this->travelTo(CarbonImmutable::parse('2026-09-19 12:00', 'Africa/Cairo'));
});

/** Names the design lets through untranslated: her own name, the pieces she bought and the shops. */
const WALK_NAMES = ['سارة أحمد', 'سارة', 'فستان ليلى', 'أسود / M', 'طرحة شيفون', 'فرع المعادي', 'فرع مدينة نصر', '12 شارع التحرير', 'المعادي', 'مدينة نصر'];

function walkSay(string $text, ?string $payload = null, bool $photo = false): Conversation
{
    static $n = 0;
    $n++;

    app(InboxIngestor::class)->ingestMessage(new InboundMessageData(
        Platform::Facebook, 'PAGE1', 'PSID-WALK', 'Mona', 'walk'.$n.'-'.uniqid(), $text, CarbonImmutable::now(),
        payload: $payload,
        attachments: $photo ? [['type' => 'image', 'url' => 'https://cdn.example/p.jpg']] : [],
    ));

    return Conversation::firstOrFail();
}

/** One customer turn: her language is read from what she wrote, exactly as a real turn does. */
function walkTurn(string $text, ?string $payload = null, bool $photo = false): FlowResult
{
    $c = walkSay($text, $payload, $photo);
    $burst = app(ReplyScheduler::class)->burst($c);
    app(ConversationLanguage::class)->observe($c, $burst);
    KeptNames::reset();

    return app(FlowEngine::class)->handle($c->fresh(), $burst);
}

/** Taps the nth quick reply of the last bot message (a tap never changes her language). */
function walkTap(int $n): FlowResult
{
    $button = ((array) walkLast()->buttons)[$n - 1] ?? null;
    expect($button)->not->toBeNull('no button #'.$n.' on: '.walkLast()->body);

    return walkTurn((string) $button['title'], (string) $button['payload']);
}

function walkStart(string $flowKey, string $opener): Conversation
{
    $c = walkSay($opener);
    app(ConversationLanguage::class)->observe($c, collect([$c->messages()->latest('id')->first()]));
    app(FlowEngine::class)->start($c->fresh(), $flowKey);

    return Conversation::firstOrFail();
}

function walkFlow(): ?array
{
    return FlowState::flow(Conversation::firstOrFail());
}

/** @return Collection<int, Message> */
function walkBot(): Collection
{
    return Message::where('sender_type', SenderType::Bot->value)->orderBy('id')->get();
}

function walkLast(): Message
{
    return walkBot()->last();
}

/** @return list<string> */
function walkButtons(): array
{
    return array_column((array) walkLast()->buttons, 'title');
}

/** §7: the same question is never asked twice word for word, and buttons fit Messenger. */
function walkAssertHealthy(): void
{
    $bodies = [];

    foreach (walkBot() as $m) {
        $body = trim((string) $m->body);

        if ($body !== '') {
            expect($bodies)->not->toContain($body, 'the bot repeated a message word for word: '.$body);
            $bodies[] = $body;
        }

        foreach ((array) $m->buttons as $button) {
            expect(mb_strlen((string) $button['title']))->toBeLessThanOrEqual(
                OutboundCards::BUTTON_TITLE_MAX,
                'button title over 20 characters: '.$button['title'],
            );
        }
    }
}

/** §7: an English run says nothing in Arabic, and nothing is left untranslated. */
function walkAssertEnglish(bool $allowNames = false): void
{
    foreach (walkBot() as $m) {
        $texts = [(string) $m->body, ...array_column((array) $m->buttons, 'title')];

        foreach ($texts as $text) {
            $stripped = $allowNames ? str_replace(WALK_NAMES, '', $text) : $text;

            expect($stripped)->not->toMatch('/\p{Arabic}/u', 'Arabic in an English run: '.$text);
            expect($text)->not->toContain('en#', 'no English for this text yet: '.$text);
        }
    }
}

/** Order #1047 of سارة أحمد, mobile ending 4567, delivered a week ago, one piece. */
function walkOrder(string $delivered = '2026-09-12 13:00'): Order
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

    OrderItem::factory()->for($order)->create(['title' => 'فستان ليلى', 'variant_title' => 'أسود / M', 'price' => 500, 'discount' => 0, 'qty' => 1]);

    return $order->fresh('items');
}

function walkBranches(): void
{
    // Only these two, so the walk always taps a known area and reads known names.
    Branch::query()->delete();

    Branch::factory()->create(['name' => 'فرع المعادي', 'area_ar' => 'المعادي', 'area_key' => 'maadi', 'address' => '12 شارع التحرير', 'phone' => '01001234567', 'is_active' => true]);
    Branch::factory()->create(['name' => 'فرع مدينة نصر', 'area_ar' => 'مدينة نصر', 'area_key' => 'nasr_city', 'address' => '5 شارع عباس العقاد', 'phone' => '01001234568', 'is_active' => true]);
}

// ---- the main menu ----------------------------------------------------------------------------

it('shows the main menu in her language', function (string $opener, string $expected, bool $english) {
    walkStart('main_menu', $opener);

    expect(walkLast()->body)->toContain($expected)
        ->and(walkButtons())->toHaveCount(7);

    walkAssertHealthy();

    if ($english) {
        walkAssertEnglish();
    }
})->with([
    'arabic' => ['اهلا', 'اختاري من القائمة', false],
    'english' => ['Hello', 'Pick from the menu', true],
    // Franco is Arabic written in Latin letters: she gets the Arabic menu.
    'franco' => ['ezayek 3ayza asaal', 'اختاري من القائمة', false],
]);

// ---- return and exchange ----------------------------------------------------------------------

it('walks the return flow and records the return', function (string $opener, bool $english) {
    walkOrder();
    walkStart('return_exchange', $opener);

    walkTurn('1047');
    walkTurn('4567');
    expect(walkFlow()['step'])->toBe('kind');

    walkTurn('x', 'step:return_exchange:kind:return');
    walkTurn('x', 'step:return_exchange:return_items:item:'.OrderItem::first()->id);
    walkTurn('x', 'step:return_exchange:return_items:done');
    walkTurn('x', 'step:return_exchange:return_reason:size');
    walkTurn('', null, photo: true);

    $case = SupportCase::sole();
    expect($case->type)->toBe('return')
        ->and($case->data['reason'])->toBe('size')
        ->and($case->data['order_number'])->toBe('#1047')
        ->and(walkFlow())->toBeNull();

    walkAssertHealthy();

    if ($english) {
        walkAssertEnglish(allowNames: true);
    }
})->with(['arabic' => ['اهلا', false], 'english' => ['Hello', true]]);

it('walks the exchange branch of the return flow', function (string $opener, bool $english) {
    walkOrder();
    walkStart('return_exchange', $opener);

    walkTurn('1047');
    walkTurn('4567');
    walkTurn('x', 'step:return_exchange:kind:exchange');
    walkTurn('x', 'step:return_exchange:exchange_items:item:'.OrderItem::first()->id);
    walkTurn('x', 'step:return_exchange:exchange_items:done');
    walkTurn('x', 'step:return_exchange:exchange_reason:color');
    walkTurn('https://levoilestores.com/products/abaya-linen');
    walkTurn($english ? 'the beige linen abaya' : 'عباية كتان بيج');

    expect(SupportCase::sole()->type)->toBe('exchange');
    walkAssertHealthy();

    if ($english) {
        walkAssertEnglish(allowNames: true);
    }
})->with(['arabic' => ['اهلا', false], 'english' => ['Hello', true]]);

// ---- order tracking ---------------------------------------------------------------------------

it('walks order tracking to the status card and the thanks', function (string $opener, bool $english) {
    walkOrder();
    walkStart('order_tracking', $opener);

    walkTurn('1047');
    walkTurn('4567');
    expect(walkFlow()['step'])->toBe('status');

    walkTurn('x', 'step:order_tracking:status:thanks');
    expect(walkFlow())->toBeNull();

    walkAssertHealthy();

    if ($english) {
        walkAssertEnglish(allowNames: true);
    }
})->with(['arabic' => ['اهلا', false], 'english' => ['Hello', true]]);

// ---- cancel and edit --------------------------------------------------------------------------

it('walks cancel/edit and records the cancellation with her reason', function (string $opener, bool $english) {
    walkOrder();
    // Nothing has shipped yet: that is what makes an order still cancellable.
    Order::first()->fulfillments()->delete();
    walkStart('cancel_edit', $opener);

    walkTurn('1047');
    walkTurn('4567');
    walkTurn('x', 'step:cancel_edit:request:cancel');
    walkTurn($english ? 'I found it cheaper somewhere else' : 'لقيته أرخص في مكان تاني');

    $case = SupportCase::sole();
    expect($case->type)->toBe('cancel_edit')
        ->and($case->data['cancel_reason'])->not->toBeEmpty();

    walkAssertHealthy();

    if ($english) {
        walkAssertEnglish(allowNames: true);
    }
})->with(['arabic' => ['اهلا', false], 'english' => ['Hello', true]]);

// ---- complaint, all five types ------------------------------------------------------------------

it('walks every complaint type', function (string $type, string $opener, bool $english) {
    walkBranches();
    walkOrder();
    walkStart('complaint', $opener);

    expect(walkButtons())->toHaveCount(6);
    walkTurn('x', 'step:complaint:type:'.$type);

    if ($type === 'branch') {
        // Tapped, not typed: a branch name is Arabic in both languages, and typing it
        // would (rightly) tell the bot she switched to Arabic.
        walkTap(1);
        walkTap(1);
        walkTurn('x', 'step:complaint:visit_date:yesterday');
    }

    if (in_array($type, ['delivery', 'product'], true)) {
        walkTurn('1047');
        walkTurn('4567');
    }

    if (walkFlow()['step'] === 'contact') {
        walkTurn($english ? 'Mona Said 01012345678' : 'منى سعيد 01012345678');
    }

    walkTurn($english ? 'The piece arrived with a tear in it' : 'القطعة وصلت فيها قطع');

    $case = SupportCase::sole();
    expect($case->type)->toBe('complaint')
        ->and($case->data['complaint_type'])->toBe($type)
        ->and($case->data['description'])->not->toBeEmpty();

    walkAssertHealthy();

    if ($english) {
        walkAssertEnglish(allowNames: true);
    }
})->with(['branch', 'delivery', 'product', 'service', 'other'])
    ->with(['arabic' => ['اهلا', false], 'english' => ['Hello', true]]);

// ---- branches ------------------------------------------------------------------------------------

it('walks the branches flow to the cards', function (string $opener, bool $english) {
    walkBranches();
    walkStart('branches', $opener);

    // Tapped, not typed: an area name is Arabic in both languages (§4), and typing it
    // would rightly tell the bot she had switched to Arabic.
    walkTap(1);

    // The cards came first, then «تحبي حاجة تانية؟»: the branch is on the card message.
    expect(walkBot()->pluck('body')->implode('
'))->toContain('فرع المعادي')
        ->and(walkBot()->last(fn ($m) => filled($m->cards))?->cards['cards'][0]['title'])->toBe('فرع المعادي');
    walkAssertHealthy();

    if ($english) {
        // The branch names, their addresses and the area stay in Arabic (§4).
        walkAssertEnglish(allowNames: true);
    }
})->with(['arabic' => ['اهلا', false], 'english' => ['Hello', true]]);

// ---- the products menu ----------------------------------------------------------------------------

it('walks the products menu to a script answer', function (string $opener, bool $english) {
    walkStart('products', $opener);

    expect(walkButtons())->toHaveCount(5);
    walkTurn('x', 'script:delivery_time');

    walkAssertHealthy();

    if ($english) {
        walkAssertEnglish();
    }
})->with(['arabic' => ['اهلا', false], 'english' => ['Hello', true]]);

// ---- asking for a person ---------------------------------------------------------------------------

it('walks the handover: the topic question, then the transfer sentence', function (string $opener, bool $english) {
    walkStart('main_menu', $opener);

    walkTurn('x', 'handover');
    expect(walkLast()->body)->not->toBeEmpty();

    walkTurn($english ? 'My order is late' : 'الأوردر اتأخر');
    expect(Conversation::first()->handler->value)->toBe('human')
        ->and(Conversation::first()->handover_topic)->not->toBeNull();

    walkAssertHealthy();

    if ($english) {
        walkAssertEnglish();
    }
})->with(['arabic' => ['اهلا', false], 'english' => ['Hello', true]]);
