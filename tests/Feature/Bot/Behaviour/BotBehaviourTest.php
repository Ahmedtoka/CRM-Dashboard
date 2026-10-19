<?php

use App\Bot\BotEngine;
use App\Bot\Episodes\ConversationEpisode;
use App\Bot\Flow\Jobs\RunBotTurn;
use App\Bot\Flows\FlowScripts;
use App\Bot\Flows\FlowState;
use App\Bot\Jobs\BotHoldNotice;
use App\Bot\Router\TurnMetrics;
use App\Channels\Data\InboundMessageData;
use App\Enums\Handler;
use App\Enums\Platform;
use App\Enums\SenderType;
use App\Inbox\InboxIngestor;
use App\Inbox\OutboundService;
use App\Models\BotRule;
use App\Models\BotRun;
use App\Models\BotSetting;
use App\Models\ChannelAccount;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\SupportCase;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

// Staff complaints, round b1 (2026-10-19): every scenario runs through the real inbound pipeline
// (InboxIngestor → ReplyScheduler → RunBotTurn → BotEngine) for BOTH routers, every model call faked.

dataset('routers', ['legacy', 'hybrid']);

beforeEach(function () {
    Event::fake();
    Http::preventStrayRequests();
    config([
        'crm.drivers.ai' => 'claude', 'crm.anthropic.key' => 'test-key', 'crm.bot.agent.enabled' => true,
        'crm.bot.router.legacy_fallback' => false, 'crm.bot.hold_notice.enabled' => false,
    ]);
    ChannelAccount::factory()->create(['platform' => Platform::Facebook, 'external_id' => 'PAGE1']);
    BotSetting::current()->update(['enabled' => true, 'ai_enabled' => true, 'working_hours' => null]);
    BotRule::query()->update(['is_active' => false]);
    $this->ai = (object) ['log' => [], 'agent' => []];
});

/** Every Messages API call is faked: planner calls get a one-task plan, agent calls the next queued reply. */
function bbFake(string $router, array $agentReplies): void
{
    config(['crm.bot.router.mode' => $router]);
    test()->ai->agent = $agentReplies;

    Http::fake(['api.anthropic.com/*' => function (Request $request) {
        $body = $request->data();

        if (isset($body['output_config']['format'])) {
            test()->ai->log[] = ['kind' => 'planner', 'body' => $body];

            return Http::response(['stop_reason' => 'end_turn', 'model' => 'claude-haiku-5-5', 'usage' => ['input_tokens' => 10, 'output_tokens' => 10], 'content' => [
                ['type' => 'text', 'text' => json_encode(['tasks' => [['kind' => 'other', 'order_refs' => [], 'items' => [], 'governorate' => null, 'place' => null, 'product_query' => null, 'question' => null, 'confidence' => 0.9]], 'sentiment' => 'neutral', 'wants_human' => false, 'continues_previous' => false, 'policy_edge' => false, 'clarify' => null])],
            ]]);
        }

        test()->ai->log[] = ['kind' => 'agent', 'body' => $body];
        $next = array_shift(test()->ai->agent) ?? 'تحت أمرك يا فندم';

        return Http::response(['stop_reason' => 'end_turn', 'model' => $body['model'] ?? 'claude-sonnet-5-5', 'usage' => ['input_tokens' => 10, 'output_tokens' => 10], 'content' => [['type' => 'text', 'text' => $next]]]);
    }]);
}

function bbSay(string $text, ?string $payload = null): Conversation
{
    app(InboxIngestor::class)->ingestMessage(new InboundMessageData(
        Platform::Facebook, 'PAGE1', 'PSID-BB', 'Mona', 'bb-'.uniqid('', true), $text, CarbonImmutable::now(), payload: $payload,
    ));

    return Conversation::firstOrFail();
}

/** @return list<string> */
function bbBot(?int $afterId = null): array
{
    return Message::where('sender_type', SenderType::Bot->value)->when($afterId, fn ($q) => $q->where('id', '>', $afterId))
        ->orderBy('id')->pluck('body')->map(fn ($b) => (string) $b)->all();
}

/** The text of every message the last agent call sent the model (history + CONTEXT + her words). */
function bbLastAgentPrompt(): string
{
    $calls = array_values(array_filter(test()->ai->log, fn ($c) => $c['kind'] === 'agent'));
    $body = end($calls)['body'] ?? null;

    if ($body === null) {
        return '';
    }

    return collect($body['messages'])->map(fn ($m) => is_array($m['content'])
        ? collect($m['content'])->pluck('text')->implode("\n")
        : (string) $m['content'])->implode("\n---\n");
}

/** The conversation turns the last agent call sent (the CONTEXT block left out). */
function bbLastAgentHistory(): string
{
    $calls = array_values(array_filter(test()->ai->log, fn ($c) => $c['kind'] === 'agent'));
    $body = end($calls)['body'] ?? null;

    return $body === null ? '' : collect($body['messages'])->map(fn ($m) => is_array($m['content'])
        ? collect($m['content'])->pluck('text')->reject(fn ($t) => str_starts_with((string) $t, 'CONTEXT'))->implode('
')
        : (string) $m['content'])->implode('
---
');
}

function bbAgentCalls(): int
{
    return count(array_filter(test()->ai->log, fn ($c) => $c['kind'] === 'agent'));
}

/** A chat a moderator handled (a return case recorded) and then closed with «خلصت» (ConversationCycle → resetToBot). */
function bbStaffHandledThenClosed(): Conversation
{
    $c = bbSay('عايزة ارجع الفستان من اوردر 209129 لو سمحتي');
    $staff = User::factory()->create(['role' => 'supervisor']);
    $c->refresh()->forceFill(['handler' => Handler::Human, 'needs_human' => true, 'handover_topic' => 'مرتجع'])->save();
    app(OutboundService::class)->sendHuman($c, $staff, 'تمام يا فندم، طلب المرتجع للفستان اتسجل والمندوب هيعدي عليكي');
    SupportCase::create([
        'conversation_id' => $c->id, 'customer_id' => $c->customer_id, 'platform' => $c->platform->value,
        'type' => 'return', 'status' => 'new', 'priority' => 'medium', 'order_number' => '#209129', 'data' => [],
    ]);
    app(BotEngine::class)->resetToBot($c->refresh());

    return $c->refresh();
}

// ---------------------------------------------------------------------------------------------
// Problem 1 — a new message after a close: one rule for both routers.
// ---------------------------------------------------------------------------------------------

it('continues the same topic with a short summary when she writes again inside the reopen window', function (string $router) {
    bbFake($router, ['المندوب هيعدي عليكي خلال يومين يا فندم']);
    $c = bbStaffHandledThenClosed();
    $before = (int) Message::max('id');

    $this->travel(10)->minutes();
    bbSay('طب هو هيجي امتى؟');

    $said = bbBot($before);
    expect($c->refresh()->handler)->toBe(Handler::Bot)
        ->and($said[0] ?? '')->toContain('بنكمل')->toContain('مرتجع')
        // The model sees the old topic: it is the same conversation going on.
        ->and(bbLastAgentPrompt())->toContain('طلب المرتجع للفستان اتسجل')->toContain('continues');
})->with('routers');

it('starts fresh after the reopen window, but still knows her recent cases and orders', function (string $router) {
    bbFake($router, ['مواعيد الفروع من 11 الصبح لـ 11 بالليل يا فندم']);
    $c = bbStaffHandledThenClosed();
    $before = (int) Message::max('id');

    $this->travel(3)->hours();
    bbSay('هو انتو فاتحين النهارده لحد امتى؟');

    $prompt = bbLastAgentPrompt();
    expect(bbBot($before))->not->toBeEmpty()
        ->and(implode("\n", bbBot($before)))->not->toContain('بنكمل')
        // The closed topic is not replayed as if it were still going on…
        ->and(bbLastAgentHistory())->not->toContain('طلب المرتجع للفستان اتسجل')
        // …but she is not forgotten: the recent request is in the CONTEXT.
        ->and($prompt)->toContain('#209129')->toContain('new conversation');
})->with('routers');

it('starts fresh after she closed it herself («لا تمام شكرا»), even inside the team reopen window', function (string $router) {
    bbFake($router, ['المندوب هيعدي عليكي خلال يومين يا فندم', 'الشحن بيوصل خلال يومين لتلات أيام يا فندم']);
    $c = bbStaffHandledThenClosed();
    $this->travel(2)->minutes();
    bbSay('طب هو هيجي امتى؟');
    $this->travel(1)->minutes();
    bbSay('لا تمام شكرا');
    $before = (int) Message::max('id');

    // Past the 10-minute grace after her own «شكرا» (a follow-up inside it carries on quietly).
    $this->travel(15)->minutes();
    bbSay('الشحن للجيزة بكام؟');

    expect(implode("\n", bbBot($before)))->not->toContain('بنكمل')
        ->and(bbLastAgentHistory())->not->toContain('طلب المرتجع للفستان اتسجل')
        ->and(bbLastAgentPrompt())->toContain('new conversation');
})->with('routers');

// ---------------------------------------------------------------------------------------------
// Problem 3 — «رجّع القطعة دي» on an old picture card, after that request was closed.
// ---------------------------------------------------------------------------------------------

/** Her order 209129 (two pieces with photos), the return flow up to its picture cards. */
function bbReturnPictures(): array
{
    $order = Order::factory()->create(['order_number' => '209129', 'shopify_order_name' => '#209129', 'shipping_phone' => '+201001234567']);
    $order->update(['shipment_status' => 'delivered', 'delivered_at' => now()->subDays(3)]);
    $dress = OrderItem::factory()->for($order)->create(['title' => 'فستان ستان', 'qty' => 1, 'price' => 850, 'discount' => 0, 'image_url' => 'https://cdn.shopify.com/s/files/a.jpg']);
    $scarf = OrderItem::factory()->for($order)->create(['title' => 'طرحة شيفون', 'qty' => 1, 'price' => 150, 'discount' => 0, 'image_url' => 'https://cdn.shopify.com/s/files/b.jpg']);

    bbSay('المرتجع والاستبدال', 'flow:return_exchange');
    bbSay('209129');
    bbSay('4567');
    $c = bbSay('ارجع');

    expect(FlowState::flow($c->refresh())['step'] ?? null)->toBe('return_items')
        ->and(Message::where('sender_type', 'bot')->whereNotNull('cards')->exists())->toBeTrue();

    return [$c, $dress, $scarf];
}

it('restarts the return for that piece when she taps an old card after «لا تمام» closed the request', function (string $router) {
    bbFake($router, []);
    [$c, $dress] = bbReturnPictures();

    bbSay('لا تمام');                                   // nothing picked: the request ends
    expect(FlowState::flow($c->refresh()))->toBeNull();
    $before = (int) Message::max('id');
    $calls = bbAgentCalls();

    $this->travel(2)->minutes();
    bbSay('رجّع القطعة دي', 'step:return_exchange:return_items:item:'.$dress->id);

    $flow = FlowState::flow($c->refresh());
    expect(implode("\n", bbBot($before)))->toContain('هنبدأ طلب جديد للقطعة دي')->not->toContain('ممكن رقم الأوردر')
        ->and($flow['key'] ?? null)->toBe('return_exchange')
        ->and(array_column((array) ($flow['data']['selected_items'] ?? []), 'line_item_id'))->toBe([$dress->id])
        ->and(bbAgentCalls())->toBe($calls);             // no model guessing what «رجّع القطعة دي» means
})->with('routers');

it('restarts the return for that piece when the team closed the chat in between', function (string $router) {
    bbFake($router, []);
    [$c, $dress] = bbReturnPictures();
    bbSay('رجّع القطعة دي', 'step:return_exchange:return_items:item:'.$dress->id);
    bbSay('لا تمام');                                   // «تحبي تضيفي قطعة تانية؟» → that's all → the reason question
    app(BotEngine::class)->returnToBot($c->refresh(), User::factory()->create(['role' => 'supervisor']));
    $before = (int) Message::max('id');

    $this->travel(3)->hours();
    bbSay('رجّع القطعة دي', 'step:return_exchange:return_items:item:'.$dress->id);

    $flow = FlowState::flow($c->refresh());
    expect(implode("\n", bbBot($before)))->toContain('هنبدأ طلب جديد للقطعة دي')
        ->and($flow['key'] ?? null)->toBe('return_exchange')
        ->and(array_column((array) ($flow['data']['selected_items'] ?? []), 'line_item_id'))->toBe([$dress->id]);
})->with('routers');

it('says the piece is already recorded instead of opening a second request for it', function (string $router) {
    bbFake($router, []);
    [$c, $dress] = bbReturnPictures();
    SupportCase::create([
        'conversation_id' => $c->id, 'customer_id' => $c->customer_id, 'platform' => $c->platform->value, 'type' => 'return', 'status' => 'new', 'priority' => 'medium',
        'order_id' => $dress->order_id, 'order_number' => '#209129', 'data' => ['selected_items' => [['line_item_id' => $dress->id, 'title' => 'فستان ستان', 'qty' => 1]]],
    ]);
    bbSay('لا تمام');
    $before = (int) Message::max('id');

    bbSay('رجّع القطعة دي', 'step:return_exchange:return_items:item:'.$dress->id);

    expect(implode("\n", bbBot($before)))->toContain('متسجلة خلاص في طلب #')
        ->and(FlowState::flow($c->refresh()))->toBeNull();
})->with('routers');

it('re-offers the menu with a short line for any other button of a request that is over', function (string $router) {
    bbFake($router, []);
    [$c] = bbReturnPictures();
    bbSay('لا تمام');
    $before = (int) Message::max('id');
    $calls = bbAgentCalls();

    bbSay('أرجع كله', 'step:return_exchange:return_items:all');

    expect(implode("\n", bbBot($before)))->toContain('الزرار ده من طلب قديم خلص')
        ->and(bbAgentCalls())->toBe($calls);
})->with('routers');

it('never trusts a piece button the bot did not send in this chat (no order shown, no request opened)', function (string $router) {
    bbFake($router, []);
    $other = Order::factory()->create(['order_number' => '777001']);
    $piece = OrderItem::factory()->for($other)->create(['title' => 'عباية', 'qty' => 1, 'price' => 900]);
    $c = bbSay('اهلا');
    $before = (int) Message::max('id');

    bbSay('رجّع القطعة دي', 'step:return_exchange:return_items:item:'.$piece->id);

    expect(implode("\n", bbBot($before)))->toContain('الزرار ده من طلب قديم خلص')->not->toContain('777001')->not->toContain('عباية')
        ->and(FlowState::flow($c->refresh())['key'] ?? null)->toBe('main_menu');
})->with('routers');

// ---------------------------------------------------------------------------------------------
// Problem 2 — several messages about one topic: one answer, not one per message.
// ---------------------------------------------------------------------------------------------

/** Like bbFake, but her next message lands while the FIRST agent call is still thinking. */
function bbFakeWithMessageDuringTurn(string $router, string $lateText, array $agentReplies): void
{
    config(['crm.bot.router.mode' => $router]);
    test()->ai->agent = $agentReplies;
    test()->ai->late = $lateText;

    Http::fake(['api.anthropic.com/*' => function (Request $request) {
        $body = $request->data();

        if (! isset($body['output_config']['format']) && (test()->ai->late ?? null) !== null) {
            $c = Conversation::firstOrFail();
            $c->messages()->create(['platform' => $c->platform, 'direction' => 'in', 'sender_type' => 'customer', 'body' => test()->ai->late, 'status' => 'received', 'external_id' => 'late-'.uniqid()]);
            test()->ai->late = null;
        }

        if (isset($body['output_config']['format'])) {
            test()->ai->log[] = ['kind' => 'planner', 'body' => $body];

            return Http::response(['stop_reason' => 'end_turn', 'model' => 'claude-haiku-5-5', 'usage' => ['input_tokens' => 10, 'output_tokens' => 10], 'content' => [
                ['type' => 'text', 'text' => json_encode(['tasks' => [['kind' => 'other', 'order_refs' => [], 'items' => [], 'governorate' => null, 'place' => null, 'product_query' => null, 'question' => null, 'confidence' => 0.9]], 'sentiment' => 'neutral', 'wants_human' => false, 'continues_previous' => false, 'policy_edge' => false, 'clarify' => null])],
            ]]);
        }

        test()->ai->log[] = ['kind' => 'agent', 'body' => $body];
        $next = array_shift(test()->ai->agent) ?? 'تحت أمرك يا فندم';

        return Http::response(['stop_reason' => 'end_turn', 'model' => $body['model'] ?? 'claude-sonnet-5-5', 'usage' => ['input_tokens' => 10, 'output_tokens' => 10], 'content' => [['type' => 'text', 'text' => $next]]]);
    }]);
}

it('answers a message that arrived while the bot was still thinking together with the first one, once', function (string $router) {
    bbFakeWithMessageDuringTurn($router, 'ومقاس L متاح؟', ['الفستان الأسود متاح يا فندم', 'الفستان الأسود متاح يا فندم والمقاس ده موجود']);

    $c = bbSay('الفستان الاسود متاح؟');
    // The late message's own (delayed) turn wakes up after its burst wait, as the queue runs it.
    $this->travel(15)->seconds();
    (new RunBotTurn($c->id))->handle();

    $replies = bbBot();
    expect($replies)->toBe(['الفستان الأسود متاح يا فندم والمقاس ده موجود'])
        ->and(BotRun::where('decision', 'superseded')->count())->toBe(1)
        // The answer that went out read BOTH messages.
        ->and(bbLastAgentHistory())->toContain('الفستان الاسود متاح؟')->toContain('ومقاس L متاح؟');
})->with('routers');

it('still answers once when she sends two messages inside the burst wait', function (string $router) {
    bbFake($router, ['متاح يا فندم بالمقاسين']);
    BotSetting::current()->update(['burst_wait_seconds' => 3]);
    Queue::fake();

    bbSay('الفستان الاسود متاح؟');
    $this->travel(1)->seconds();
    $c = bbSay('ومقاس L؟');
    Queue::assertPushed(RunBotTurn::class, 2);

    // Both queued turns wake up: the first finds the due time moved, the second answers the burst.
    $this->travel(5)->seconds();
    (new RunBotTurn($c->id))->handle();
    (new RunBotTurn($c->id))->handle();

    expect(bbBot())->toBe(['متاح يا فندم بالمقاسين'])
        ->and(bbAgentCalls())->toBe(1);
})->with('routers');

it('sends the answer as it is once she has been waiting past the supersede limit (no endless waiting)', function (string $router) {
    config(['crm.bot.supersede_max_seconds' => 0]);
    bbFakeWithMessageDuringTurn($router, 'ومقاس L متاح؟', ['الفستان الأسود متاح يا فندم', 'والمقاس ده موجود كمان']);

    $c = bbSay('الفستان الاسود متاح؟');
    $this->travel(15)->seconds();
    (new RunBotTurn($c->id))->handle();

    expect(bbBot())->toBe(['الفستان الأسود متاح يا فندم', 'والمقاس ده موجود كمان'])
        ->and(BotRun::where('decision', 'superseded')->count())->toBe(0);
})->with('routers');

// ---------------------------------------------------------------------------------------------
// Problem 4 — handing over too much / too little, repeating itself.
// ---------------------------------------------------------------------------------------------

it('does not hand a returning customer over for the turns of a chat that was already closed', function (string $router) {
    BotSetting::current()->update(['max_bot_turns' => 3]);
    bbFake($router, ['متاح يا فندم بكل المقاسات', 'الخامة كتان طبيعي يا فندم', 'بيتغسل على البارد يا فندم', 'الاسدال متاح بالاسود والكحلي يا فندم']);

    bbSay('الفستان متاح؟');
    $this->travel(1)->minutes();
    bbSay('خامته ايه؟');
    $this->travel(1)->minutes();
    bbSay('بيتغسل ازاي؟');
    $this->travel(1)->minutes();
    bbSay('شكرا');                                   // her own close
    $this->travel(40)->minutes();
    $c = bbSay('عندكم اسدال اسود؟');                 // a new conversation, inside the 120-minute turn window

    expect($c->refresh()->handler)->toBe(Handler::Bot)
        ->and(bbBot())->toContain('الاسدال متاح بالاسود والكحلي يا فندم');
})->with('routers');

it('never spends the turn limit on answers that needed no model (the owner rules and FAQ)', function (string $router) {
    BotSetting::current()->update(['max_bot_turns' => 2]);
    bbFake($router, []);
    BotRule::query()->update(['is_active' => false]);
    BotRule::factory()->create(['name' => 'دفع', 'keywords' => ['انستاباي'], 'match_type' => 'any_keyword', 'action' => 'reply', 'public_replies' => ['أيوه بنقبل انستاباي يا فندم'], 'scope' => 'both', 'platforms' => []]);

    foreach (range(1, 4) as $i) {
        $c = bbSay('بتقبلوا انستاباي؟');
        $this->travel(1)->minutes();
    }

    expect($c->refresh()->handler)->toBe(Handler::Bot);
})->with('routers');

it('offers a person instead of sending her the same long answer again', function (string $router) {
    $same = 'المرتجع بيتم خلال 14 يوم من الاستلام والقطعة بحالتها الأصلية يا فندم';
    bbFake($router, [$same, $same]);

    bbSay('ازاي ارجع الفستان؟');
    $this->travel(1)->minutes();
    $c = bbSay('يعني اعمل ايه عشان ارجعه؟');

    $said = bbBot();
    expect(array_count_values($said)[$same] ?? 0)->toBe(1)
        ->and(end($said))->toContain('تحبي أوصّلك بحد منهم')
        ->and(FlowState::confirm($c->refresh()))->toBe('handover_offer');
})->with('routers');

it('hands her to a person when the bot is unsure twice in a row, instead of «خليني أتأكد» again (hybrid)', function () {
    // Every model draft invents a promise, so tier 1 and the tier-2 retry both fail the guards.
    bbFake('hybrid', ['وليكي هدية مع الأوردر', 'وكمان هدية تانية', 'وهدية تالتة', 'وهدية رابعة']);

    bbSay('عايزة اعرف عن العروض');
    $this->travel(1)->minutes();
    $c = bbSay('طب في عروض النهارده؟');

    $fallback = FlowScripts::all()['bot_safe_fallback']['body'];
    expect(array_count_values(bbBot())[$fallback] ?? 0)->toBe(1)
        ->and($c->refresh()->handler)->not->toBe(Handler::Bot)
        ->and(implode(' ', bbBot()))->not->toContain('هدية');
});

// ---------------------------------------------------------------------------------------------
// Coordinator live test (2026-10-19): a full request typed while the main menu is up.
// ---------------------------------------------------------------------------------------------

function bbOrder1313(): array
{
    $order = Order::factory()->create(['order_number' => '1313', 'shopify_order_name' => '#1313', 'shipping_phone' => '+201001234567']);
    $order->update(['shipment_status' => 'delivered', 'delivered_at' => now()->subDays(3)]);
    $skirt = OrderItem::factory()->for($order)->create(['title' => 'جيبة بليسيه', 'qty' => 1, 'price' => 500, 'discount' => 0]);
    $suit = OrderItem::factory()->for($order)->create(['title' => 'طقم رياضي', 'qty' => 1, 'price' => 900, 'discount' => 0]);

    return [$order, $skirt, $suit];
}

it('fills the return flow from her words when she types the whole request at the main menu', function (string $router) {
    bbFake($router, []);
    [, $skirt, $suit] = bbOrder1313();
    bbSay('السلام عليكم');                                   // the main menu is up
    expect(FlowState::flow(Conversation::first())['key'] ?? null)->toBe('main_menu');
    $before = (int) Message::max('id');

    bbSay('عايزة ارجع الجيبة البليسيه وابدل الطقم الرياضي بمقاس اكبر من اوردر 1313');
    $said = implode("\n", bbBot($before));
    expect($said)->not->toContain('ممكن رقم الأوردر')->toContain('آخر ٤ أرقام');

    $c = bbSay('4567');
    $flow = FlowState::flow($c->refresh());
    $rows = collect((array) ($flow['data']['selected_items'] ?? []))->keyBy('line_item_id');
    expect($flow['data']['request_kind'] ?? null)->toBe('mixed')
        ->and(implode("\n", bbBot($before)))->not->toContain('ترجعي ولا تبدلي')
        ->and($rows[$skirt->id]['action'] ?? null)->toBe('return')
        ->and($rows[$suit->id]['action'] ?? null)->toBe('exchange')
        ->and($rows[$suit->id]['exchange_to'] ?? '')->toContain('اكبر');
})->with('routers');

it('keeps her words when she taps «المرتجع والاستبدال» and then types the whole request', function (string $router) {
    bbFake($router, []);
    [, $skirt, $suit] = bbOrder1313();
    bbSay('المرتجع والاستبدال', 'flow:return_exchange');
    $before = (int) Message::max('id');

    bbSay('عايزة ارجع الجيبة البليسيه وابدل الطقم الرياضي بمقاس اكبر من اوردر 1313');
    $c = bbSay('4567');

    $flow = FlowState::flow($c->refresh());
    $rows = collect((array) ($flow['data']['selected_items'] ?? []))->keyBy('line_item_id');
    expect($flow['data']['request_kind'] ?? null)->toBe('mixed')
        ->and(implode("\n", bbBot($before)))->not->toContain('ترجعي ولا تبدلي')
        ->and($rows[$skirt->id]['action'] ?? null)->toBe('return')
        ->and($rows[$suit->id]['action'] ?? null)->toBe('exchange');
})->with('routers');

it('takes the order number she already typed as the second proof after her mobile (A4)', function (string $router) {
    bbFake($router, ['تمام يا فندم، هشوفلك الأوردر']);
    $order = bbOrder1313()[0];
    $order->update(['shipping_phone' => '+201112223334']);   // not the chat's own mobile: A4 asks a second proof
    bbSay('اوردر 1313 لسه موصلش');                              // the number, said before any flow
    bbSay('المرتجع والاستبدال', 'flow:return_exchange');
    $before = (int) Message::max('id');

    $c = bbSay('01112223334');

    expect(implode("\n", bbBot($before)))->not->toContain('رقم الأوردر')
        ->and(FlowState::flow($c->refresh())['data']['order_verified'] ?? null)->toBeTrue();
})->with('routers');

// ---------------------------------------------------------------------------------------------
// Code review of round b1 (C1, C2, I1–I6).
// ---------------------------------------------------------------------------------------------

it('applies the close rule once: the next turns are just the conversation going on (C1)', function (string $router) {
    bbFake($router, ['المندوب هيعدي عليكي خلال يومين يا فندم', 'مقاسك كام يا فندم؟', 'تمام، مقاس L متاح يا فندم']);
    bbStaffHandledThenClosed();
    $this->travel(3)->hours();
    bbSay('عندكم الفستان الكتان؟');                  // fresh start
    $before = (int) Message::max('id');
    $this->travel(1)->minutes();
    bbSay('عايزاه');
    $this->travel(1)->minutes();
    bbSay('L');

    expect(implode("\n", bbBot($before)))->not->toContain('بنكمل')
        // The current chat is still read: her «عايزاه» and the question before «L».
        ->and(bbLastAgentHistory())->toContain('عايزاه')->toContain('عندكم الفستان الكتان؟');
})->with('routers');

it('never sends «بنكمل» twice inside the reopen window (C1)', function (string $router) {
    bbFake($router, ['المندوب هيعدي عليكي خلال يومين يا فندم', 'هيكلمك قبلها يا فندم', 'أيوه يا فندم']);
    bbStaffHandledThenClosed();
    $before = (int) Message::max('id');
    $this->travel(10)->minutes();
    bbSay('طب هو هيجي امتى؟');
    $this->travel(1)->minutes();
    bbSay('وهيكلمني قبلها؟');

    expect(collect(bbBot($before))->filter(fn ($t) => str_contains($t, 'بنكمل'))->count())->toBe(1);
})->with('routers');

it('keeps her first message when the first turn of a new chat is superseded after «ثانية واحدة» (C2)', function (string $router) {
    bbFakeWithMessageDuringTurn($router, 'ومقاس L متاح؟', ['الفستان الأسود متاح يا فندم', 'الفستان الأسود متاح يا فندم والمقاس ده موجود']);
    // The hold notice went out while the model was thinking: it is stored like any bot message.
    test()->ai->hold = true;
    Http::fake(['api.anthropic.com/*' => function (Request $request) {
        if (test()->ai->hold ?? false) {
            test()->ai->hold = false;
            $c = Conversation::firstOrFail();
            $m = $c->messages()->create(['platform' => $c->platform, 'direction' => 'out', 'sender_type' => 'bot', 'body' => 'ثانية واحدة يا فندم', 'status' => 'sent']);
            Cache::put(BotHoldNotice::sentKey((string) app(TurnMetrics::class)->turn), $m->id, 60);
            $c->messages()->create(['platform' => $c->platform, 'direction' => 'in', 'sender_type' => 'customer', 'body' => 'ومقاس L متاح؟', 'status' => 'received', 'external_id' => 'late-'.uniqid()]);
        }
        $body = $request->data();
        if (isset($body['output_config']['format'])) {
            return Http::response(['stop_reason' => 'end_turn', 'model' => 'claude-haiku-5-5', 'usage' => ['input_tokens' => 1, 'output_tokens' => 1], 'content' => [['type' => 'text', 'text' => json_encode(['tasks' => [['kind' => 'other', 'order_refs' => [], 'items' => [], 'governorate' => null, 'place' => null, 'product_query' => null, 'question' => null, 'confidence' => 0.9]], 'sentiment' => 'neutral', 'wants_human' => false, 'continues_previous' => false, 'policy_edge' => false, 'clarify' => null])]]]);
        }
        test()->ai->log[] = ['kind' => 'agent', 'body' => $body];

        return Http::response(['stop_reason' => 'end_turn', 'model' => $body['model'] ?? 'x', 'usage' => ['input_tokens' => 1, 'output_tokens' => 1], 'content' => [['type' => 'text', 'text' => array_shift(test()->ai->agent) ?? 'تحت أمرك يا فندم']]]);
    }]);

    $c = bbSay('الفستان الاسود متاح؟');
    $this->travel(15)->seconds();
    (new RunBotTurn($c->id))->handle();

    expect(bbLastAgentHistory())->toContain('الفستان الاسود متاح؟')->toContain('ومقاس L متاح؟')
        ->and(bbBot())->toHaveCount(2)->and(bbBot()[0])->toBe('ثانية واحدة يا فندم')
        ->and(BotRun::where('decision', 'superseded')->count())->toBe(1);
})->with('routers');

it('names the request the piece is already in (I1)', function (string $router) {
    bbFake($router, []);
    [$c, $dress] = bbReturnPictures();
    $case = SupportCase::create([
        'conversation_id' => $c->id, 'customer_id' => $c->customer_id, 'platform' => $c->platform->value, 'type' => 'return', 'status' => 'new', 'priority' => 'medium',
        'order_id' => $dress->order_id, 'order_number' => '#209129', 'data' => ['selected_items' => [['line_item_id' => $dress->id, 'title' => 'فستان ستان', 'qty' => 1]]],
    ]);
    bbSay('لا تمام');
    $before = (int) Message::max('id');

    bbSay('رجّع القطعة دي', 'step:return_exchange:return_items:item:'.$dress->id);

    expect(implode("\n", bbBot($before)))->toContain('في طلب #'.$case->id.'،');
})->with('routers');

it('finds the open request for the piece days later, or one the team opened without pieces (I2)', function (string $router) {
    bbFake($router, []);
    [$c, $dress] = bbReturnPictures();
    $case = SupportCase::create([
        'conversation_id' => $c->id, 'customer_id' => $c->customer_id, 'platform' => $c->platform->value, 'type' => 'return', 'status' => 'new', 'priority' => 'medium',
        'order_id' => $dress->order_id, 'order_number' => '#209129', 'data' => [],
    ]);
    bbSay('لا تمام');
    $this->travel(3)->days();
    $before = (int) Message::max('id');

    bbSay('رجّع القطعة دي', 'step:return_exchange:return_items:item:'.$dress->id);

    expect(implode("\n", bbBot($before)))->toContain('#'.$case->id)
        ->and(FlowState::flow($c->refresh())['key'] ?? null)->not->toBe('return_exchange');
})->with('routers');

it('carries on quietly when she asks a follow-up right after her own «شكرا» (I3)', function (string $router) {
    bbFake($router, ['الفستان الكتان متاح يا فندم', 'أيوه مقاس L متاح يا فندم']);
    bbSay('عندكم الفستان الكتان؟');
    $this->travel(1)->minutes();
    bbSay('تمام شكرا');
    $before = (int) Message::max('id');
    $this->travel(2)->minutes();
    bbSay('طب ومقاس L متاح؟');

    expect(implode("\n", bbBot($before)))->not->toContain('بنكمل')
        ->and(bbLastAgentHistory())->toContain('عندكم الفستان الكتان؟');
})->with('routers');

it('sends no welcome-back line after a plain «رجوع للبوت» or right after the bot recorded her request (I4)', function (string $router) {
    bbFake($router, ['المندوب هيعدي عليكي خلال يومين يا فندم']);
    $c = bbSay('عايزة ارجع الفستان من اوردر 209129 لو سمحتي');
    $staff = User::factory()->create(['role' => 'supervisor']);
    $c->refresh()->forceFill(['handler' => Handler::Human, 'needs_human' => true])->save();
    app(OutboundService::class)->sendHuman($c, $staff, 'تمام يا فندم، البوت هيكمل معاكي');
    app(BotEngine::class)->returnToBot($c->refresh(), $staff);
    $before = (int) Message::max('id');

    $this->travel(1)->minutes();
    bbSay('طب هو هيجي امتى؟');

    expect(implode("\n", bbBot($before)))->not->toContain('بنكمل')
        ->and(bbLastAgentHistory())->toContain('البوت هيكمل معاكي');
})->with('routers');

it('does not hand her over for being unsure again after she said «لأ» to the offer (I6)', function () {
    bbFake('hybrid', ['وليكي هدية', 'وهدية تانية', 'وهدية تالتة', 'وهدية رابعة']);
    bbSay('عايزة اعرف عن العروض');
    $this->travel(1)->minutes();
    bbSay('لأ', 'no');
    $this->travel(1)->minutes();
    $c = bbSay('طب في عروض النهارده؟');

    expect($c->refresh()->handler)->toBe(Handler::Bot);
});

it('sends tier 1 a shorter history (the last 8 messages) to keep it fast (coordinator live test)', function () {
    bbFake('hybrid', array_fill(0, 8, 'تحت أمرك يا فندم'));
    BotSetting::current()->update(['max_bot_turns' => 20]);
    foreach (range(1, 7) as $i) {
        bbSay("سؤال رقم {$i} عن الفستان؟");
        $this->travel(1)->minutes();
    }
    bbSay('وآخر سؤال عن المقاسات؟');

    $calls = array_values(array_filter(test()->ai->log, fn ($c) => $c['kind'] === 'agent'));
    $last = end($calls)['body'];

    expect($last['model'])->toBe('claude-haiku-5-5')
        ->and(bbLastAgentHistory())->not->toContain('سؤال رقم 1 عن')->toContain('سؤال رقم 7 عن');
});

it('answers a question at the order step without fixing her request kind from it (re-review)', function (string $router) {
    bbFake($router, ['أيوه ينفع تبدلي المقاس خلال 14 يوم يا فندم']);
    bbOrder1313();
    bbSay('المرتجع والاستبدال', 'flow:return_exchange');
    $c = bbSay('لو عايزة ابدل المقاس ينفع؟');

    expect(FlowState::flow($c->refresh())['data']['request_kind'] ?? null)->toBeNull();
})->with('routers');

// Owner 2026-10-19: once she rated the closed chat it is over — the next message is a new conversation.
it('starts fresh right after she rated the closed chat, even inside the reopen window', function (string $router) {
    bbFake($router, ['أهلاً بيكي يا فندم، أقدر أساعد حضرتك إزاي؟']);
    $c = bbStaffHandledThenClosed();
    ConversationEpisode::seal($c->refresh());
    $before = (int) Message::max('id');

    $this->travel(1)->minutes();
    bbSay('طيب انا محدش حللي حاجة');

    expect(($c->refresh()->bot_state['episode'] ?? [])['mode'] ?? null)->toBe('fresh')
        ->and(implode("\n", bbBot($before)))->not->toContain('بنكمل')
        // The closed chat is no longer the conversation (its tail may sit in CONTEXT as memory).
        ->and(bbLastAgentHistory())->not->toContain('طلب المرتجع للفستان اتسجل');
})->with('routers');
