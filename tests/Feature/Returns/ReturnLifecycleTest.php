<?php

use App\Cases\Returns\ReturnLifecycle;
use App\Cases\Returns\ReturnScripts;
use App\Cases\Returns\ReturnTransitionException;
use App\Cases\Returns\SendReturnUpdate;
use App\Enums\Platform;
use App\Enums\UserRole;
use App\Models\BotKnowledgeEntry;
use App\Models\ChannelAccount;
use App\Models\Conversation;
use App\Models\ConversationNote;
use App\Models\Customer;
use App\Models\Fulfillment;
use App\Models\Message;
use App\Models\Order;
use App\Models\SupportCase;
use App\Models\SupportCaseEvent;
use App\Models\User;
use Illuminate\Support\Facades\Queue;

// Returns and exchanges end to end (round 2026-10-24, x4): the lifecycle on SupportCase, in order, with the
// customer told at each step by the editable ReturnScripts texts.

beforeEach(function () {
    $this->travelTo('2026-10-24 10:00:00');
    $this->moderator = User::factory()->create(['role' => UserRole::Supervisor]);
});

function returnCase(string $type = 'return', array $data = []): SupportCase
{
    $account = ChannelAccount::factory()->create(['platform' => Platform::WhatsApp, 'driver' => 'fake', 'status' => 'connected']);
    $customer = Customer::factory()->create();
    $conversation = Conversation::factory()->create([
        'platform' => Platform::WhatsApp, 'channel_account_id' => $account->id, 'customer_id' => $customer->id, 'handler' => 'bot',
        // She wrote a minute ago: the reply window is open.
        'last_customer_message_at' => now()->subMinute(),
    ]);
    $order = Order::factory()->create(['customer_id' => $customer->id, 'conversation_id' => $conversation->id, 'shopify_order_name' => '#1500']);

    return SupportCase::factory()->create([
        'conversation_id' => $conversation->id,
        'customer_id' => $customer->id,
        'platform' => 'whatsapp',
        'type' => $type,
        'order_id' => $order->id,
        'order_number' => '1500',
        'data' => array_merge([
            'reason' => 'size', 'reason_title' => 'المقاس مش مناسب',
            'selected_items' => [['title' => 'فستان شيفون', 'qty' => 1, 'price' => 450]],
        ], $data),
    ]);
}

function advance(SupportCase $case, string $stage, array $input = []): SupportCase
{
    return app(ReturnLifecycle::class)->advance($case, $stage, test()->moderator, $input);
}

/** Runs the customer notification for one step now (the real queue runs it after the commit). */
function runReturnUpdate(SupportCaseEvent $event): void
{
    app()->call([new SendReturnUpdate($event->id), 'handle']);
    $event->refresh();
}

it('starts at requested with the first steps offered', function () {
    $case = returnCase();

    expect(ReturnLifecycle::stageOf($case))->toBe('requested')
        ->and(ReturnLifecycle::nextStages($case))->toBe(['pickup_scheduled', 'cancelled'])
        ->and(ReturnLifecycle::isExchange($case))->toBeFalse();
});

it('walks a return to the end: pickup, cash refund, received, closed', function () {
    Queue::fake([SendReturnUpdate::class]);
    $case = returnCase();

    $case = advance($case, 'pickup_scheduled', ['pickup_date' => '2026-10-26']);
    expect($case->return_stage)->toBe('pickup_scheduled')
        ->and($case->pickup_date->toDateString())->toBe('2026-10-26')
        ->and($case->status)->toBe('in_progress')
        ->and(ReturnLifecycle::nextStages($case))->toBe(['pickup_scheduled', 'picked_up', 'cancelled']);

    $case = advance($case, 'picked_up', ['refund_amount' => 450]);
    expect((float) $case->refund_amount)->toBe(450.0)
        ->and(ReturnLifecycle::nextStages($case))->toBe(['received']);

    $case = advance($case, 'received');
    // A plain return closes once the piece is back; no exchange step is offered.
    expect(ReturnLifecycle::nextStages($case))->toBe(['closed']);

    $case = advance($case, 'closed');
    expect($case->return_stage)->toBe('closed')
        ->and($case->status)->toBe('resolved')
        ->and($case->resolved_at)->not->toBeNull()
        ->and(ReturnLifecycle::nextStages($case))->toBe([]);

    expect(SupportCaseEvent::where('support_case_id', $case->id)->pluck('stage')->all())
        ->toBe(['pickup_scheduled', 'picked_up', 'received', 'closed']);
    // One system note per step in the thread.
    expect(ConversationNote::where('conversation_id', $case->conversation_id)->count())->toBe(4);
    // The customer is told about the pickup day and the cash refund; received/closed are internal.
    Queue::assertPushed(SendReturnUpdate::class, 2);
});

it('walks an exchange: no refund needed at pickup, the new order is linked when it ships', function () {
    Queue::fake([SendReturnUpdate::class]);
    $case = returnCase('exchange', ['exchange_product' => ['title' => 'فستان شيفون أسود']]);
    expect(ReturnLifecycle::isExchange($case))->toBeTrue();

    $case = advance($case, 'pickup_scheduled', ['pickup_date' => '2026-10-25']);
    $case = advance($case, 'picked_up');
    expect($case->refund_amount)->toBeNull();

    $case = advance($case, 'received');
    expect(ReturnLifecycle::nextStages($case))->toBe(['exchange_shipped']);

    // The replacement order with its carrier link.
    $new = Order::factory()->create(['customer_id' => $case->customer_id, 'shopify_order_name' => '#1600']);
    Fulfillment::factory()->create(['order_id' => $new->id, 'tracking_url' => 'https://bosta.co/t/1']);

    expect(fn () => advance($case, 'exchange_shipped'))->toThrow(ReturnTransitionException::class);
    expect(fn () => advance($case, 'exchange_shipped', ['exchange_order_id' => $case->order_id]))->toThrow(ReturnTransitionException::class);

    $case = advance($case, 'exchange_shipped', ['exchange_order_id' => $new->id]);
    expect($case->exchange_order_id)->toBe($new->id)
        ->and(ReturnLifecycle::nextStages($case))->toBe(['closed']);

    $event = SupportCaseEvent::where('support_case_id', $case->id)->where('stage', 'exchange_shipped')->first();
    expect($event->meta['exchange_order_number'])->toBe('#1600')
        ->and($event->meta['script_vars']['order_number'])->toBe('1600')
        ->and($event->meta['script_vars']['tracking'])->toContain('https://bosta.co/t/1');

    $case = advance($case, 'closed');
    expect($case->status)->toBe('resolved');
    Queue::assertPushed(SendReturnUpdate::class, 3);
});

it('refuses steps out of order and steps for other case types', function () {
    $case = returnCase();

    expect(fn () => advance($case, 'picked_up', ['refund_amount' => 100]))->toThrow(ReturnTransitionException::class, 'Cannot move');
    expect(fn () => advance($case, 'received'))->toThrow(ReturnTransitionException::class);
    expect(fn () => advance($case, 'closed'))->toThrow(ReturnTransitionException::class);
    expect(fn () => advance($case, 'requested'))->toThrow(ReturnTransitionException::class);
    expect(fn () => advance($case, 'exchange_shipped', ['exchange_order_id' => 1]))->toThrow(ReturnTransitionException::class);

    $complaint = SupportCase::factory()->create(['conversation_id' => $case->conversation_id, 'type' => 'complaint']);
    expect(fn () => advance($complaint, 'pickup_scheduled', ['pickup_date' => '2026-10-26']))->toThrow(ReturnTransitionException::class, 'not_a_return');
    expect(ReturnLifecycle::nextStages($complaint))->toBe([]);
});

it('validates each step\'s facts', function () {
    $case = returnCase();

    try {
        advance($case, 'pickup_scheduled');
        $this->fail('no date accepted');
    } catch (ReturnTransitionException $e) {
        expect($e->reason)->toBe('pickup_date_required');
    }

    try {
        advance($case, 'pickup_scheduled', ['pickup_date' => '2026-10-20']);
        $this->fail('past date accepted');
    } catch (ReturnTransitionException $e) {
        expect($e->reason)->toBe('pickup_date_past');
    }

    // Today (Cairo) is fine.
    $case = advance($case, 'pickup_scheduled', ['pickup_date' => '2026-10-24']);

    try {
        advance($case, 'picked_up');
        $this->fail('a return picked up without the cash amount');
    } catch (ReturnTransitionException $e) {
        expect($e->reason)->toBe('refund_amount_required');
    }

    try {
        advance($case, 'picked_up', ['refund_amount' => -5]);
        $this->fail('negative amount accepted');
    } catch (ReturnTransitionException $e) {
        expect($e->reason)->toBe('refund_amount_invalid');
    }

    try {
        advance($case, 'cancelled');
        $this->fail('cancelled without a reason');
    } catch (ReturnTransitionException $e) {
        expect($e->reason)->toBe('reason_required');
    }
});

it('can be rescheduled and cancelled before the pickup; a cancel closes the case without a solution', function () {
    Queue::fake([SendReturnUpdate::class]);
    $case = advance(returnCase(), 'pickup_scheduled', ['pickup_date' => '2026-10-25']);
    $case = advance($case, 'pickup_scheduled', ['pickup_date' => '2026-10-27']);
    expect($case->pickup_date->toDateString())->toBe('2026-10-27');

    $case = advance($case, 'cancelled', ['reason' => 'العميلة غيرت رأيها']);
    expect($case->return_stage)->toBe('cancelled')
        ->and($case->status)->toBe('closed')
        ->and($case->closed_at)->not->toBeNull()
        ->and(ReturnLifecycle::nextStages($case))->toBe([]);
    Queue::assertPushed(SendReturnUpdate::class, 3);
});

it('tells the customer in Egyptian Arabic with the editable script, and records that she was told', function () {
    $case = advance(returnCase(), 'pickup_scheduled', ['pickup_date' => '2026-10-26']);
    $event = SupportCaseEvent::where('support_case_id', $case->id)->first();
    runReturnUpdate($event);

    expect($event->customer_notified_at)->not->toBeNull()->and($event->notify_error)->toBeNull();

    $message = Message::where('conversation_id', $case->conversation_id)->latest('id')->first();
    expect($message->body)->toContain('الاثنين 26 أكتوبر')
        ->and($message->body)->toContain('المندوب هيتواصل مع حضرتك')
        ->and($message->body)->not->toMatch('/\p{Extended_Pictographic}/u');

    // Once per event: a second run sends nothing more.
    runReturnUpdate($event);
    expect(Message::where('conversation_id', $case->conversation_id)->count())->toBe(1);

    $case = advance($case, 'picked_up', ['refund_amount' => 450]);
    runReturnUpdate(SupportCaseEvent::where('support_case_id', $case->id)->where('stage', 'picked_up')->first());
    $message = Message::where('conversation_id', $case->conversation_id)->latest('id')->first();
    expect($message->body)->toContain('450 جنيه كاش');
});

it('records a closed reply window instead of failing, so the moderator writes herself', function () {
    $case = returnCase();
    // Her last message was two days ago: WhatsApp's window is closed for the bot.
    Conversation::whereKey($case->conversation_id)->update(['last_customer_message_at' => now()->subDays(2)]);
    $case = advance($case, 'pickup_scheduled', ['pickup_date' => '2026-10-26']);

    $event = SupportCaseEvent::where('support_case_id', $case->id)->first();
    runReturnUpdate($event);

    expect($event->notify_error)->toBe('window_closed')->and($event->customer_notified_at)->toBeNull()
        ->and(Message::where('conversation_id', $case->conversation_id)->count())->toBe(0);
});

it('sends nothing when the owner switched the script off, and records why', function () {
    BotKnowledgeEntry::updateOrCreate(['key' => 'script.return_pickup_scheduled'], ['title' => 'x', 'body' => 'x', 'is_active' => false]);
    $case = advance(returnCase(), 'pickup_scheduled', ['pickup_date' => '2026-10-26']);

    $event = SupportCaseEvent::where('support_case_id', $case->id)->first();
    runReturnUpdate($event);
    expect($event->notify_error)->toBe('script_off')->and($event->customer_notified_at)->toBeNull()
        ->and(Message::where('conversation_id', $case->conversation_id)->count())->toBe(0);
});

it('keeps every return script emoji-free and free of time promises', function () {
    foreach (ReturnScripts::all() as $key => $script) {
        expect($script['body'])->not->toMatch('/\p{Extended_Pictographic}/u', $key)
            ->and($script['body'])->not->toMatch('/خلال (ساعة|ساعتين|يوم|يومين|\d+ (أيام|ساعات|دقايق))/u', $key);
    }
});

it('presents the lifecycle for the UI', function () {
    Queue::fake([SendReturnUpdate::class]);
    $case = advance(returnCase(), 'pickup_scheduled', ['pickup_date' => '2026-10-26']);
    $view = ReturnLifecycle::present($case->fresh());

    expect($view['stage'])->toBe('pickup_scheduled')
        ->and($view['is_exchange'])->toBeFalse()
        ->and($view['pickup_date'])->toBe('2026-10-26')
        ->and($view['next'])->toBe(['pickup_scheduled', 'picked_up', 'cancelled'])
        ->and($view['timeline'][0]['stage'])->toBe('requested')
        ->and($view['timeline'][1]['stage'])->toBe('pickup_scheduled')
        ->and($view['timeline'][1]['by'])->toBe($this->moderator->name)
        ->and($view['timeline'][1]['meta']['pickup_date'])->toBe('2026-10-26')
        ->and($view['timeline'][1]['notified'])->toBeFalse();

    expect(ReturnLifecycle::present(SupportCase::factory()->create(['conversation_id' => $case->conversation_id])))->toBeNull();
});
