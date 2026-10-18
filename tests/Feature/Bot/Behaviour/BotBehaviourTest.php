<?php

use App\Bot\BotEngine;
use App\Bot\Flows\FlowEngine;
use App\Bot\Flows\FlowState;
use App\Channels\Data\InboundMessageData;
use App\Enums\Handler;
use App\Enums\Platform;
use App\Enums\SenderType;
use App\Inbox\InboxIngestor;
use App\Inbox\OutboundService;
use App\Models\BotRule;
use App\Models\BotSetting;
use App\Models\ChannelAccount;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\SupportCase;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;

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
        ? collect($m['content'])->pluck('text')->reject(fn ($t) => str_starts_with((string) $t, 'CONTEXT'))->implode("
")
        : (string) $m['content'])->implode("
---
");
}

function bbAgentCalls(): int
{
    return count(array_filter(test()->ai->log, fn ($c) => $c['kind'] === 'agent'));
}

/** A chat a moderator handled (a return case recorded) and then closed back to the bot. */
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
    app(BotEngine::class)->returnToBot($c->refresh(), $staff);

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

it('starts fresh right away after she closed it herself («لا تمام شكرا»), even inside the window', function (string $router) {
    bbFake($router, ['المندوب هيعدي عليكي خلال يومين يا فندم', 'الشحن بيوصل خلال يومين لتلات أيام يا فندم']);
    $c = bbStaffHandledThenClosed();
    $this->travel(2)->minutes();
    bbSay('طب هو هيجي امتى؟');
    $this->travel(1)->minutes();
    bbSay('لا تمام شكرا');
    $before = (int) Message::max('id');

    $this->travel(5)->minutes();
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
    $order = \App\Models\Order::factory()->create(['order_number' => '209129', 'shopify_order_name' => '#209129', 'shipping_phone' => '+201001234567']);
    $order->update(['shipment_status' => 'delivered', 'delivered_at' => now()->subDays(3)]);
    $dress = \App\Models\OrderItem::factory()->for($order)->create(['title' => 'فستان ستان', 'qty' => 1, 'price' => 850, 'discount' => 0, 'image_url' => 'https://cdn.shopify.com/s/files/a.jpg']);
    $scarf = \App\Models\OrderItem::factory()->for($order)->create(['title' => 'طرحة شيفون', 'qty' => 1, 'price' => 150, 'discount' => 0, 'image_url' => 'https://cdn.shopify.com/s/files/b.jpg']);

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
    $other = \App\Models\Order::factory()->create(['order_number' => '777001']);
    $piece = \App\Models\OrderItem::factory()->for($other)->create(['title' => 'عباية', 'qty' => 1, 'price' => 900]);
    $c = bbSay('اهلا');
    $before = (int) Message::max('id');

    bbSay('رجّع القطعة دي', 'step:return_exchange:return_items:item:'.$piece->id);

    expect(implode("\n", bbBot($before)))->toContain('الزرار ده من طلب قديم خلص')->not->toContain('777001')->not->toContain('عباية')
        ->and(FlowState::flow($c->refresh())['key'] ?? null)->toBe('main_menu');
})->with('routers');
