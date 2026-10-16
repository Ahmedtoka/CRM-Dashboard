<?php

use App\Bot\Flow\ReplyScheduler;
use App\Channels\Adapters\FakeChannelAdapter;
use App\Channels\Data\InboundMessageData;
use App\Confirmations\ConfirmationPayload;
use App\Confirmations\ConfirmationSettings;
use App\Confirmations\Contracts\ConfirmationSender;
use App\Confirmations\Events\OrderConfirmationAnswered;
use App\Confirmations\Events\OrderConfirmationEditNote;
use App\Confirmations\WhatsApp\ConfirmationReplyHandler;
use App\Enums\ConversationStatus;
use App\Enums\Handler;
use App\Enums\MessageDirection;
use App\Enums\OrderConfirmationStatus;
use App\Enums\Platform;
use App\Enums\SenderType;
use App\Inbox\InboxIngestor;
use App\Models\BotSetting;
use App\Models\ChannelAccount;
use App\Models\Conversation;
use App\Models\Customer;
use App\Models\Message;
use App\Models\Order;
use App\Models\OrderConfirmation;
use App\Queue\QueueService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;

// Slice W2: the customer's answer to the confirmation template, handled before the bot.

beforeEach(function () {
    FakeChannelAdapter::reset();
    Event::fake([OrderConfirmationAnswered::class, OrderConfirmationEditNote::class]);
    BotSetting::current()->update(['enabled' => true]);
    app(ConfirmationSettings::class)->save(['enabled' => true]);

    $this->scheduler = Mockery::mock(ReplyScheduler::class)->shouldIgnoreMissing();
    app()->instance(ReplyScheduler::class, $this->scheduler);

    $this->account = ChannelAccount::factory()->create(['platform' => Platform::WhatsApp, 'external_id' => 'WA-PHONE-1']);
    $this->order = Order::factory()->for(Customer::factory()->create(['name' => 'ليلى']))->create(['shopify_order_name' => '#1234']);
    $this->confirmation = OrderConfirmation::factory()->create([
        'order_id' => $this->order->id, 'channel_account_id' => $this->account->id, 'phone_e164' => '+201112223334',
    ]);
    app(ConfirmationSender::class)->send($this->confirmation, 'confirm');
    $this->confirmation->refresh();
    $this->sequence = 0;
});

function customerSays(string $body, ?string $payload = null, string $from = '201112223334'): ?Message
{
    test()->sequence++;

    return app(InboxIngestor::class)->ingestMessage(new InboundMessageData(
        Platform::WhatsApp, 'WA-PHONE-1', $from, 'Laila', 'wamid.in-'.test()->sequence.'-'.$from, $body,
        CarbonImmutable::now(), customerPhone: $from, payload: $payload,
    ));
}

function botReplies(Conversation $c): array
{
    return $c->messages()->where('direction', MessageDirection::Out->value)->where('sender_type', SenderType::Bot->value)
        ->where('is_template', false)->orderBy('id')->pluck('body')->all();
}

it('confirms on the confirm button, answers, and keeps the bot out', function () {
    $this->scheduler->shouldNotReceive('schedule');

    customerSays('تأكيد الأوردر', ConfirmationPayload::make($this->confirmation->id, 'confirm'));

    $row = $this->confirmation->fresh();
    expect($row->status)->toBe(OrderConfirmationStatus::Confirmed)
        ->and($row->answer)->toBe('confirm')
        ->and($row->answered_at)->not->toBeNull()
        ->and(botReplies($row->conversation))->toBe([ConfirmationReplyHandler::REPLY_CONFIRMED]);

    Event::assertDispatched(OrderConfirmationAnswered::class, fn ($e) => $e->answer === 'confirm' && $e->confirmation->id === $row->id);
});

it('records a cancel request with the cancel reply', function () {
    $this->scheduler->shouldNotReceive('schedule');

    customerSays('إلغاء', ConfirmationPayload::make($this->confirmation->id, 'cancel'));

    $row = $this->confirmation->fresh();
    expect($row->status)->toBe(OrderConfirmationStatus::CancelRequested)
        ->and(botReplies($row->conversation))->toBe(['تمام، بلغنا الفريق بطلب الإلغاء وهيتواصلوا معاكي']);
    Event::assertDispatched(OrderConfirmationAnswered::class, fn ($e) => $e->answer === 'cancel');
});

it('asks for the edit, then keeps the next text as the edit note', function () {
    $this->scheduler->shouldNotReceive('schedule');

    customerSays('تعديل', ConfirmationPayload::make($this->confirmation->id, 'edit'));

    $row = $this->confirmation->fresh();
    expect($row->status)->toBe(OrderConfirmationStatus::EditRequested)
        ->and($row->edit_note_requested_at)->not->toBeNull()
        ->and(botReplies($row->conversation))->toBe(['ابعتيلنا التعديل أو العنوان الجديد في رسالة']);
    Event::assertDispatched(OrderConfirmationAnswered::class, fn ($e) => $e->answer === 'edit');

    customerSays("العنوان الجديد: مدينة نصر\nعمارة 5");

    $row = $this->confirmation->fresh();
    expect($row->edit_note)->toBe("العنوان الجديد: مدينة نصر\nعمارة 5")
        ->and(botReplies($row->conversation))->toBe([ConfirmationReplyHandler::REPLY_EDIT, ConfirmationReplyHandler::REPLY_EDIT_THANKS]);
    Event::assertDispatched(OrderConfirmationEditNote::class, fn ($e) => $e->confirmation->id === $row->id);
});

it('leaves text after the edit window to the bot', function () {
    customerSays('تعديل', ConfirmationPayload::make($this->confirmation->id, 'edit'));
    $this->travel(ConfirmationReplyHandler::EDIT_WINDOW_HOURS * 60 + 1)->minutes();

    $this->scheduler->shouldReceive('schedule')->once();
    customerSays('عايزة أغير المقاس');

    expect($this->confirmation->fresh()->edit_note)->toBeNull();
    Event::assertNotDispatched(OrderConfirmationEditNote::class);
});

it('only takes the first text as the edit note', function () {
    customerSays('تعديل', ConfirmationPayload::make($this->confirmation->id, 'edit'));
    customerSays('المقاس L بدل M');

    $this->scheduler->shouldReceive('schedule')->once();
    customerSays('وكمان عندي سؤال');

    expect($this->confirmation->fresh()->edit_note)->toBe('المقاس L بدل M');
});

it('answers the same tap again politely without a second event', function () {
    customerSays('تأكيد الأوردر', ConfirmationPayload::make($this->confirmation->id, 'confirm'));

    $this->scheduler->shouldNotReceive('schedule');
    customerSays('تأكيد الأوردر', ConfirmationPayload::make($this->confirmation->id, 'confirm'));

    $row = $this->confirmation->fresh();
    expect($row->status)->toBe(OrderConfirmationStatus::Confirmed)
        ->and(botReplies($row->conversation))->toBe([ConfirmationReplyHandler::REPLY_CONFIRMED, ConfirmationReplyHandler::REPLY_ALREADY]);
    Event::assertDispatchedTimes(OrderConfirmationAnswered::class, 1);
});

it('takes a later cancel or edit after another answer', function (OrderConfirmationStatus $from, string $action, OrderConfirmationStatus $to) {
    $this->confirmation->forceFill(['status' => $from, 'answer' => 'confirm', 'answered_at' => now()->subHour()])->save();
    $this->scheduler->shouldNotReceive('schedule');

    customerSays('زرار', ConfirmationPayload::make($this->confirmation->id, $action));

    $row = $this->confirmation->fresh();
    expect($row->status)->toBe($to)->and($row->answer)->toBe($action)
        ->and(botReplies($row->conversation))->not->toContain(ConfirmationReplyHandler::REPLY_ALREADY);
    Event::assertDispatched(OrderConfirmationAnswered::class, fn ($e) => $e->answer === $action);
})->with([
    'confirmed → cancel' => [OrderConfirmationStatus::Confirmed, 'cancel', OrderConfirmationStatus::CancelRequested],
    'confirmed → edit' => [OrderConfirmationStatus::Confirmed, 'edit', OrderConfirmationStatus::EditRequested],
    'edit requested → cancel' => [OrderConfirmationStatus::EditRequested, 'cancel', OrderConfirmationStatus::CancelRequested],
    'unreachable → cancel' => [OrderConfirmationStatus::Unreachable, 'cancel', OrderConfirmationStatus::CancelRequested],
    'unreachable → edit' => [OrderConfirmationStatus::Unreachable, 'edit', OrderConfirmationStatus::EditRequested],
    'failed → cancel' => [OrderConfirmationStatus::Failed, 'cancel', OrderConfirmationStatus::CancelRequested],
    'called confirmed → edit' => [OrderConfirmationStatus::CalledConfirmed, 'edit', OrderConfirmationStatus::EditRequested],
]);

it('answers a repeated answer politely', function (OrderConfirmationStatus $status, string $action) {
    $this->confirmation->forceFill(['status' => $status])->save();
    $this->scheduler->shouldNotReceive('schedule');

    customerSays('زرار', ConfirmationPayload::make($this->confirmation->id, $action));

    expect($this->confirmation->fresh()->status)->toBe($status)
        ->and(botReplies($this->confirmation->conversation))->toBe([ConfirmationReplyHandler::REPLY_ALREADY]);
    Event::assertNotDispatched(OrderConfirmationAnswered::class);
})->with([
    [OrderConfirmationStatus::CancelRequested, 'cancel'],
    [OrderConfirmationStatus::EditRequested, 'edit'],
    [OrderConfirmationStatus::CalledConfirmed, 'confirm'],
    [OrderConfirmationStatus::CalledCancelled, 'cancel'],
]);

it('hands any other late answer to staff untouched', function (OrderConfirmationStatus $status, string $action) {
    $this->confirmation->forceFill(['status' => $status])->save();
    $this->scheduler->shouldReceive('schedule')->once();

    customerSays('زرار', ConfirmationPayload::make($this->confirmation->id, $action));

    expect($this->confirmation->fresh()->status)->toBe($status)
        ->and(botReplies($this->confirmation->conversation))->toBe([]);
    Event::assertNotDispatched(OrderConfirmationAnswered::class);
})->with([
    'cancel requested → confirm' => [OrderConfirmationStatus::CancelRequested, 'confirm'],
    'cancel requested → edit' => [OrderConfirmationStatus::CancelRequested, 'edit'],
    'edit requested → confirm' => [OrderConfirmationStatus::EditRequested, 'confirm'],
    'called cancelled → confirm' => [OrderConfirmationStatus::CalledCancelled, 'confirm'],
    'skipped → confirm' => [OrderConfirmationStatus::Skipped, 'confirm'],
]);

it('still takes a tap after the no-response deadline', function () {
    $this->confirmation->forceFill(['status' => OrderConfirmationStatus::NoResponse])->save();

    customerSays('تأكيد الأوردر', ConfirmationPayload::make($this->confirmation->id, 'confirm'));

    expect($this->confirmation->fresh()->status)->toBe(OrderConfirmationStatus::Confirmed);
});

it('takes an exact typed answer right after the template', function (string $typed, OrderConfirmationStatus $status) {
    $this->scheduler->shouldNotReceive('schedule');

    customerSays($typed);

    expect($this->confirmation->fresh()->status)->toBe($status);
})->with([
    'تمام' => ['تمام', OrderConfirmationStatus::Confirmed],
    'أكد!' => ['أكد!', OrderConfirmationStatus::Confirmed],
    'اكد' => ['اكد', OrderConfirmationStatus::Confirmed],
    'موافق' => [' موافق ', OrderConfirmationStatus::Confirmed],
    'confirm' => ['Confirm', OrderConfirmationStatus::Confirmed],
    'الغي' => ['الغي', OrderConfirmationStatus::CancelRequested],
    'إلغاء' => ['إلغاء.', OrderConfirmationStatus::CancelRequested],
    'cancel' => ['cancel', OrderConfirmationStatus::CancelRequested],
]);

it('leaves any other typed text to the bot', function (string $typed) {
    $this->scheduler->shouldReceive('schedule')->once();

    customerSays($typed);

    expect($this->confirmation->fresh()->status)->toBe(OrderConfirmationStatus::Sent);
    Event::assertNotDispatched(OrderConfirmationAnswered::class);
})->with(['تمام بس عايزة أغير العنوان', 'ممكن الغي قطعة؟', 'لا', 'شكرا']);

it('ignores a typed answer once someone wrote after the template', function () {
    $conversation = $this->confirmation->conversation;
    Message::factory()->for($conversation, 'conversation')->create(['direction' => MessageDirection::Out, 'sender_type' => SenderType::User, 'body' => 'أهلاً، محتاجة حاجة؟']);

    $this->scheduler->shouldReceive('schedule')->once();
    customerSays('تمام');

    expect($this->confirmation->fresh()->status)->toBe(OrderConfirmationStatus::Sent);
});

it('ignores and logs a payload for another customer\'s confirmation', function () {
    $other = OrderConfirmation::factory()->sent()->create(['phone_e164' => '+201000000000', 'channel_account_id' => $this->account->id]);
    Log::spy();
    $this->scheduler->shouldNotReceive('schedule');

    customerSays('إلغاء', ConfirmationPayload::make($other->id, 'cancel'));

    expect($other->fresh()->status)->toBe(OrderConfirmationStatus::Sent)
        ->and(botReplies($this->confirmation->conversation))->toBe([]);
    Event::assertNotDispatched(OrderConfirmationAnswered::class);
    Log::shouldHaveReceived('warning')->withArgs(fn ($msg) => $msg === 'order_confirmation.foreign_payload')->once();
});

it('accepts a tap from the confirmation\'s number on a fresh thread', function () {
    // The row has no conversation yet (e.g. sent before the thread link was stored).
    $this->confirmation->forceFill(['conversation_id' => null, 'customer_id' => null])->save();

    customerSays('تأكيد الأوردر', ConfirmationPayload::make($this->confirmation->id, 'confirm'));

    expect($this->confirmation->fresh()->status)->toBe(OrderConfirmationStatus::Confirmed);
});

it('leaves other button payloads and other channels alone', function () {
    $this->scheduler->shouldReceive('schedule')->once();
    customerSays('المقاسات', 'flow:sizes');

    $handler = app(ConfirmationReplyHandler::class);
    $messenger = Conversation::factory()->for(ChannelAccount::factory()->create(['platform' => Platform::Facebook]), 'channelAccount')->create();
    $tap = Message::factory()->for($messenger, 'conversation')->create([
        'direction' => MessageDirection::In, 'sender_type' => SenderType::Customer, 'body' => 'تأكيد',
        'payload' => ConfirmationPayload::make($this->confirmation->id, 'confirm'),
    ]);

    expect($handler->handle($tap, $messenger))->toBeFalse()
        ->and($this->confirmation->fresh()->status)->toBe(OrderConfirmationStatus::Sent);
});

describe('inbox side effects of a handled answer', function () {
    it('leaves no unread count for staff', function () {
        customerSays('تأكيد الأوردر', ConfirmationPayload::make($this->confirmation->id, 'confirm'));
        customerSays('تأكيد الأوردر', ConfirmationPayload::make($this->confirmation->id, 'confirm'));

        expect($this->confirmation->fresh()->conversation->unread_count)->toBe(0);
    });

    it('does not reopen a resolved thread', function () {
        $conversation = $this->confirmation->conversation;
        $conversation->forceFill(['status' => ConversationStatus::Resolved, 'resolved_at' => now()->subMinute()])->save();

        customerSays('إلغاء', ConfirmationPayload::make($this->confirmation->id, 'cancel'));

        $conversation->refresh();
        expect($this->confirmation->fresh()->status)->toBe(OrderConfirmationStatus::CancelRequested)
            ->and($conversation->status)->toBe(ConversationStatus::Resolved)
            ->and($conversation->resolved_at)->not->toBeNull()
            ->and(Conversation::count())->toBe(1)
            ->and($conversation->messages()->where('direction', MessageDirection::In->value)->count())->toBe(1);
    });

    it('never reaches the handover queue, even on a human-handled chat', function () {
        $this->confirmation->conversation->forceFill(['handler' => Handler::Human])->save();
        $queue = Mockery::mock(QueueService::class)->shouldIgnoreMissing();
        $queue->shouldNotReceive('settles');
        app()->instance(QueueService::class, $queue);

        customerSays('تأكيد الأوردر', ConfirmationPayload::make($this->confirmation->id, 'confirm'));

        expect($this->confirmation->fresh()->status)->toBe(OrderConfirmationStatus::Confirmed)
            ->and($this->confirmation->conversation->fresh()->unread_count)->toBe(0);
    });

    it('still counts a message it does not take as unread', function () {
        customerSays('عندي سؤال عن المقاسات');

        expect($this->confirmation->fresh()->conversation->unread_count)->toBe(1);
    });

    it('hands a late tap it does not take to staff as unread, reopening the thread', function () {
        $conversation = $this->confirmation->conversation;
        $this->confirmation->forceFill(['status' => OrderConfirmationStatus::CancelRequested])->save();
        $conversation->forceFill(['status' => ConversationStatus::Resolved])->save();

        customerSays('تأكيد الأوردر', ConfirmationPayload::make($this->confirmation->id, 'confirm'));

        $conversation->refresh();
        expect($conversation->status)->toBe(ConversationStatus::Open)->and($conversation->unread_count)->toBe(1);
    });
});
