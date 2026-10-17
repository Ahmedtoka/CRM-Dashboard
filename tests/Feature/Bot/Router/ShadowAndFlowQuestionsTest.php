<?php

use App\Bot\Agent\AgentOutcome;
use App\Bot\Agent\AgentTools;
use App\Bot\Flows\FlowEngine;
use App\Bot\Flows\FlowState;
use App\Bot\Router\ShadowPlanTurn;
use App\Channels\Data\InboundMessageData;
use App\Enums\Platform;
use App\Enums\SenderType;
use App\Inbox\InboxIngestor;
use App\Models\BotRule;
use App\Models\BotRun;
use App\Models\BotSetting;
use App\Models\ChannelAccount;
use App\Models\Conversation;
use App\Models\Customer;
use App\Models\Message;
use App\Models\Order;
use App\Models\OrderItem;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    Event::fake();
    Http::preventStrayRequests();
    config(['crm.drivers.ai' => 'fake']);
    ChannelAccount::factory()->create(['platform' => Platform::Facebook, 'external_id' => 'PAGE1']);
    BotSetting::current()->update(['enabled' => true, 'ai_enabled' => true, 'working_hours' => null]);
    BotRule::query()->update(['is_active' => false]);
});

function sfSay(string $text): Conversation
{
    app(InboxIngestor::class)->ingestMessage(new InboundMessageData(
        Platform::Facebook, 'PAGE1', 'PSID-SF', 'Mona', 'sf-'.uniqid(), $text, CarbonImmutable::now(),
    ));

    return Conversation::firstOrFail();
}

it('shadow mode: records what the planner would do, sends nothing, changes nothing', function () {
    config(['crm.bot.router.mode' => 'shadow']);
    $c = sfSay('عايزة ارجع اوردر 209129 واوردر 209200');
    $before = Message::where('sender_type', SenderType::Bot->value)->count();
    $state = $c->refresh()->bot_state;

    (new ShadowPlanTurn($c->id, Message::where('sender_type', SenderType::Customer->value)->pluck('id')->all()))->handle();

    $run = BotRun::where('engine', 'shadow')->sole();
    expect($run->decision)->toBe('shadow')
        ->and($run->intent)->toStartWith('return')
        ->and(json_decode($run->reply_text, true)['directed'])->toBe(2)
        ->and(Message::where('sender_type', SenderType::Bot->value)->count())->toBe($before)
        ->and($c->refresh()->bot_state)->toBe($state);
});

it('shadow mode queues the plan after the live reply, never on a sync queue', function () {
    config(['crm.bot.router.mode' => 'shadow']);
    Queue::fake();

    $c = Conversation::factory()->create();
    $m = Message::factory()->for($c, 'conversation')->create(['direction' => 'in', 'sender_type' => 'customer', 'body' => 'الشحن بكام']);
    ShadowPlanTurn::for($c, collect([$m]));

    Queue::assertPushedOn('outbound', ShadowPlanTurn::class);
});

it('keeps the tool list the same inside a flow; start_flow just says no there', function () {
    $tools = app(AgentTools::class);
    $outside = collect($tools->definitions(false))->pluck('name')->all();
    $inside = collect($tools->definitions(true))->pluck('name')->all();
    $outcome = new AgentOutcome;

    expect($inside)->toBe($outside)
        ->and($tools->run('start_flow', ['flow' => 'return_exchange'], Conversation::factory()->create(), $outcome))->toContain('Not now')
        ->and($outcome->flow)->toBeNull();
});

it('lists the pieces of an order only when the order is hers (get_order_items)', function () {
    $customer = Customer::factory()->create(['phone' => '01001234567']);
    $c = Conversation::factory()->create(['customer_id' => $customer->id]);
    $mine = Order::factory()->create(['order_number' => '209129', 'shopify_order_name' => '#209129', 'customer_id' => $customer->id, 'shipping_phone' => '+201001234567']);
    OrderItem::factory()->for($mine)->create(['title' => 'فستان ستان', 'variant_title' => 'أسود / M', 'qty' => 1, 'price' => 950]);
    $other = Order::factory()->create(['order_number' => '209200', 'shopify_order_name' => '#209200', 'shipping_phone' => '+201119999999']);
    OrderItem::factory()->for($other)->create(['title' => 'عباية', 'qty' => 1, 'price' => 700]);

    $tools = app(AgentTools::class);
    $tools->definitions(false);

    expect($tools->run('get_order_items', ['order_number' => '209129'], $c, new AgentOutcome))->toContain('فستان ستان')
        ->and($tools->run('get_order_items', ['order_number' => '209200'], $c, new AgentOutcome))->not->toContain('عباية');
});

it('hybrid mode: a question in the middle of a flow never runs the old turn runner (B8)', function () {
    config(['crm.bot.router.mode' => 'hybrid', 'crm.drivers.ai' => 'claude', 'crm.anthropic.key' => 'k', 'crm.bot.agent.enabled' => true]);
    Http::fake(['api.anthropic.com/*' => Http::response(['error' => 'down'], 500)]);
    $c = sfSay('اهلا');
    app(FlowEngine::class)->start($c, 'return_exchange');
    $sent = Http::recorded()->count();

    sfSay('هو الشحن بياخد قد ايه؟');

    // The step interpreter and one tier-1 attempt — no TurnRunner, no planner — and the flow question comes back.
    expect(Http::recorded()->count() - $sent)->toBe(2)
        ->and(collect(Http::recorded())->map(fn ($p) => $p[0]->data()['model'] ?? null)->unique()->values()->all())->toBe(['claude-haiku-5-5'])
        ->and(FlowState::flow($c->refresh())['key'] ?? null)->toBe('return_exchange');
});
