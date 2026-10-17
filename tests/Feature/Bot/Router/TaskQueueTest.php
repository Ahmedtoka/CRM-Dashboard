<?php

use App\Bot\Flow\ReplyScheduler;
use App\Bot\Flows\FakeFlowAnswerInterpreter;
use App\Bot\Flows\FlowAnswerInterpreter;
use App\Bot\Flows\FlowEngine;
use App\Bot\Flows\FlowState;
use App\Bot\Flows\Returns\RemoteProductLookup;
use App\Bot\Router\CaseLock;
use App\Bot\Router\TaskOrchestrator;
use App\Bot\Router\TaskQueue;
use App\Channels\Data\InboundMessageData;
use App\Enums\Platform;
use App\Enums\SenderType;
use App\Inbox\InboxIngestor;
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
use App\Models\SupportCase;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;

// Bot hybrid R5/R6 (scenarios 22, 26): two requests in one message run one after the other, a
// second return for another order is a new request, and a summary closes two or more.

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

function tqSay(string $text, ?string $payload = null): Conversation
{
    app(InboxIngestor::class)->ingestMessage(new InboundMessageData(
        Platform::Facebook, 'PAGE1', 'PSID-TQ', 'Mona', 'tq-'.uniqid(), $text, CarbonImmutable::now(), payload: $payload,
    ));

    return Conversation::firstOrFail();
}

/** One customer turn the way the router runs it: a pending yes/no first, then the flow. */
function tqTurn(string $text, ?string $payload = null): void
{
    $c = tqSay($text, $payload);
    $burst = app(ReplyScheduler::class)->burst($c);
    $engine = app(FlowEngine::class);

    if ($engine->isActive($c)) {
        $engine->handle($c, $burst);
    } elseif ($payload !== null || FlowState::confirm($c) !== null) {
        $engine->runPayload($c, $payload ?? 'yes');
    }
}

function tqPhoto(): void
{
    $c = Conversation::firstOrFail();
    $m = Message::factory()->for($c, 'conversation')->create(['direction' => 'in', 'sender_type' => 'customer', 'body' => null]);
    MessageAttachment::factory()->create(['message_id' => $m->id]);
    app(FlowEngine::class)->handle($c->fresh(), app(ReplyScheduler::class)->burst($c->fresh()));
}

function tqOrder(string $number, string $piece): Order
{
    $order = Order::factory()->create([
        'order_number' => $number, 'shopify_order_name' => '#'.$number, 'shipping_name' => 'سارة أحمد',
        'shipping_phone' => '+201001234567', 'placed_at' => now()->subDays(10),
    ]);
    Fulfillment::factory()->for($order)->create(['shipment_status' => 'delivered', 'shopify_created_at' => now()->subDays(9), 'delivered_at' => now()->subDays(7)]);
    OrderItem::factory()->for($order)->create(['title' => $piece, 'discount' => 0, 'qty' => 1, 'price' => 500, 'variant_title' => null]);

    return $order;
}

/** Return the one piece of the running order: verify, reason, photo → case. */
function tqFinishReturn(): void
{
    tqTurn('4567');
    $piece = OrderItem::where('order_id', FlowState::flow(Conversation::firstOrFail())['data']['order_id'] ?? 0)->value('id');
    tqTurn('x', 'step:return_exchange:return_items:item:'.$piece);
    tqTurn('x', 'step:return_exchange:return_reason:size');
    tqPhoto();
}

function tqLast(): string
{
    return (string) Message::where('sender_type', SenderType::Bot->value)->latest('id')->value('body');
}

it('#22 runs a return for each order she named, one after the other, then sums them up', function () {
    tqOrder('209129', 'فستان ستان');
    tqOrder('209200', 'عباية كتان');
    $c = tqSay('عايزة ارجع اوردر 209129 واوردر 209200');

    app(TaskOrchestrator::class)->start($c, TaskOrchestrator::flowTasks('return_exchange', 'عايزة ارجع اوردر 209129 واوردر 209200'));

    expect(array_column(TaskQueue::all($c->refresh()), 'label'))->toBe(['مرتجع أوردر #209129', 'مرتجع أوردر #209200'])
        ->and(FlowState::flow($c)['data']['request_kind'])->toBe('return');

    tqFinishReturn();

    expect(SupportCase::count())->toBe(1)
        ->and(tqLast())->toBe('تمام، نكمل في مرتجع أوردر #209200؟');

    tqTurn('أيوه', 'yes');
    tqFinishReturn();

    $cases = SupportCase::orderBy('id')->get();
    expect($cases->pluck('order_number')->all())->toBe(['#209129', '#209200'])
        ->and(tqLast())->toBe("ملخص طلبات حضرتك:\n1) مرتجع أوردر #209129 — طلب #{$cases[0]->id}\n2) مرتجع أوردر #209200 — طلب #{$cases[1]->id}")
        ->and(TaskQueue::all($c->refresh()))->toBe([]);
});

it('«بعدين» leaves the next request for later and still sums up', function () {
    tqOrder('209129', 'فستان ستان');
    tqOrder('209200', 'عباية كتان');
    $c = tqSay('عايزة ارجع اوردر 209129 واوردر 209200');
    app(TaskOrchestrator::class)->start($c, TaskOrchestrator::flowTasks('return_exchange', 'عايزة ارجع اوردر 209129 واوردر 209200'));
    tqFinishReturn();

    tqTurn('بعدين', 'no');

    expect(SupportCase::count())->toBe(1)
        ->and(tqLast())->toContain('2) مرتجع أوردر #209200 — لسه مكملناهوش')
        ->and(FlowState::flow($c->refresh()))->toBeNull();
});

it('#26 a second return for another order is a new request; the same order is «already recorded» (B6)', function () {
    tqOrder('209129', 'فستان ستان');
    $c = tqSay('عايزة ارجع اوردر 209129');
    app(TaskOrchestrator::class)->start($c, TaskOrchestrator::flowTasks('return_exchange', 'عايزة ارجع اوردر 209129'));
    tqFinishReturn();
    $first = SupportCase::sole();

    expect(CaseLock::forFlow($c, 'return_exchange', '209200'))->toBeNull()
        ->and(CaseLock::forFlow($c, 'return_exchange', '209129')?->id)->toBe($first->id)
        ->and(CaseLock::forFlow($c, 'return_exchange', null))->toBeNull();

    // The same order again through the orchestrator: told, not restarted.
    app(TaskOrchestrator::class)->start($c->refresh(), TaskOrchestrator::flowTasks('return_exchange', 'وكمان عايزة ارجع اوردر 209129'));

    expect(SupportCase::count())->toBe(1)
        ->and(FlowState::flow($c->refresh()))->toBeNull()
        ->and(tqLast())->toContain("متسجل خلاص برقم #{$first->id}");
});

it('never records the same pieces of the same order twice, even through the menu', function () {
    tqOrder('209129', 'فستان ستان');
    $c = tqSay('اهلا');
    app(FlowEngine::class)->runPayload($c, 'flow:return_exchange');
    tqTurn('209129');
    tqTurn('4567');
    tqTurn('ارجاع');
    tqTurn('فستان ستان');
    tqTurn('x', 'step:return_exchange:return_reason:size');
    tqPhoto();

    app(FlowEngine::class)->runPayload($c->refresh(), 'flow:return_exchange');
    tqTurn('209129');
    tqTurn('4567');
    tqTurn('ارجاع');
    tqTurn('فستان ستان');
    tqTurn('x', 'step:return_exchange:return_reason:size');
    tqPhoto();

    expect(SupportCase::count())->toBe(1)
        ->and(implode("\n", Message::where('sender_type', SenderType::Bot->value)->pluck('body')->all()))->toContain('متسجل خلاص');
});

it('sums up nothing for a single request (owner B7)', function () {
    expect(app(TaskOrchestrator::class)->summaryText([TaskQueue::task('return', 'مرتجع')]))->toBeNull();
});

it('tells the team about the requests still waiting when a flow hands her over', function () {
    $late = tqOrder('209129', 'فستان ستان');
    $late->fulfillments()->update(['delivered_at' => now()->subDays(20)]);
    tqOrder('209200', 'عباية كتان');
    $c = tqSay('عايزة ارجع اوردر 209129 واوردر 209200');
    app(TaskOrchestrator::class)->start($c, TaskOrchestrator::flowTasks('return_exchange', 'عايزة ارجع اوردر 209129 واوردر 209200'));
    tqTurn('4567');
    expect(FlowState::flow($c->refresh())['step'])->toBe('late');

    tqTurn('x', 'step:return_exchange:late:agent');

    expect(ConversationNote::where('conversation_id', $c->id)->pluck('body')->implode("\n"))
        ->toContain('مرتجع أوردر #209200 — لسه')
        ->and(TaskQueue::all($c->refresh()))->toBe([]);
});
