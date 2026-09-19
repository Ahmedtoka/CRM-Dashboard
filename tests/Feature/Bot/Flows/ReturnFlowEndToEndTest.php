<?php

use App\Bot\Flow\ReplyScheduler;
use App\Bot\Flows\FakeFlowAnswerInterpreter;
use App\Bot\Flows\FlowAnswerInterpreter;
use App\Bot\Flows\FlowEngine;
use App\Bot\Flows\FlowResult;
use App\Bot\Flows\FlowState;
use App\Channels\Data\InboundMessageData;
use App\Enums\Handler;
use App\Enums\Platform;
use App\Enums\SenderType;
use App\Enums\ShipmentStatus;
use App\Inbox\InboxIngestor;
use App\Models\BotSetting;
use App\Models\ChannelAccount;
use App\Models\Conversation;
use App\Models\ConversationNote;
use App\Models\Message;
use App\Models\MessageAttachment;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Shipment;
use App\Models\SupportCase;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    Event::fake();
    Http::preventStrayRequests();
    config(['crm.drivers.ai' => 'fake']);
    ChannelAccount::factory()->create(['platform' => Platform::Facebook, 'external_id' => 'PAGE1']);
    BotSetting::current()->update(['enabled' => false, 'working_hours' => null]);
    app()->bind(FlowAnswerInterpreter::class, FakeFlowAnswerInterpreter::class);
});

function rfSay(string $text, ?string $payload = null): FlowResult
{
    static $n = 0;
    $n++;
    app(InboxIngestor::class)->ingestMessage(new InboundMessageData(
        Platform::Facebook, 'PAGE1', 'PSID-RF', 'Mona', 'rf'.$n.'-'.uniqid(), $text, CarbonImmutable::now(), payload: $payload,
    ));
    $c = Conversation::firstOrFail();

    return app(FlowEngine::class)->handle($c, app(ReplyScheduler::class)->burst($c));
}

function rfPhoto(): FlowResult
{
    $c = Conversation::firstOrFail();
    $m = Message::factory()->for($c, 'conversation')->create(['direction' => 'in', 'sender_type' => 'customer', 'body' => null]);
    MessageAttachment::factory()->create(['message_id' => $m->id]);

    return app(FlowEngine::class)->handle($c->fresh(), app(ReplyScheduler::class)->burst($c->fresh()));
}

function rfLastBot(): Message
{
    return Message::where('sender_type', SenderType::Bot->value)->latest('id')->firstOrFail();
}

it('records a return/exchange case at the end of the whole return flow', function () {
    $order = Order::factory()->create(['order_number' => '5566', 'shipping_phone' => '+201001234567']);
    OrderItem::factory()->for($order)->create(['title' => 'فستان ليلى', 'qty' => 1, 'price' => 850, 'discount' => 0]);
    Shipment::factory()->for($order)->create(['status' => ShipmentStatus::Delivered]);

    // flow:return_exchange → policy script + order prompt
    app(InboxIngestor::class)->ingestMessage(new InboundMessageData(
        Platform::Facebook, 'PAGE1', 'PSID-RF', 'Mona', 'rf-start', 'اهلا', CarbonImmutable::now(),
    ));
    $c = Conversation::firstOrFail();
    expect(app(FlowEngine::class)->runPayload($c, 'flow:return_exchange'))->toBeTrue();
    expect(FlowState::flow($c->fresh())['step'])->toBe('order')
        ->and(rfLastBot()->body)->toContain('رقم الأوردر');

    rfSay('5566');
    expect(FlowState::flow($c->fresh())['step'])->toBe('order')
        ->and(rfLastBot()->body)->toContain('آخر ٤ أرقام');

    rfSay('٤٥٦٧');
    expect(FlowState::flow($c->fresh())['step'])->toBe('order_items');

    rfSay('1');
    expect(FlowState::flow($c->fresh())['step'])->toBe('reason')
        ->and(rfLastBot()->buttons)->not->toBeEmpty();

    rfSay('بايظ / فيه عيب', 'step:return_exchange:reason:defective');
    expect(FlowState::flow($c->fresh())['step'])->toBe('request');

    rfSay('استبدال');
    expect(FlowState::flow($c->fresh())['step'])->toBe('product_photo')
        ->and(rfLastBot()->body)->toBe('ممكن صورة واضحة للمنتج؟ 📸');

    rfPhoto();
    expect(FlowState::flow($c->fresh())['step'])->toBe('defect_photo')
        ->and(rfLastBot()->body)->toBe('وممكن صورة توضح العيب اللي في المنتج؟ 📸');

    rfPhoto();
    $summary = rfLastBot();
    expect(FlowState::flow($c->fresh())['step'])->toBe('summary')
        ->and($summary->buttons)->toHaveCount(2);

    rfSay('تمام، سجل', 'step:return_exchange:summary:confirm');

    $case = SupportCase::sole();
    $c = $c->fresh();
    expect($case->type)->toBe('return_exchange')
        ->and($case->status)->toBe('new')
        ->and($case->priority)->toBe('high')
        ->and($case->conversation_id)->toBe($c->id)
        ->and($case->customer_id)->toBe($c->customer_id)
        ->and($case->order_id)->toBe($order->id)
        ->and($case->order_number)->toBe('#5566')
        ->and($case->data['reason'])->toBe('defective')
        ->and($case->data['request'])->toBe('exchange')
        ->and($case->data['selected_items'])->toHaveCount(1)
        ->and($case->data['order_verified'])->toBeTrue()
        ->and($case->photo_attachment_ids)->toHaveCount(2)
        ->and(ConversationNote::where('conversation_id', $c->id)->where('body', 'like', '%📋 حالة #%')->exists())->toBeTrue()
        ->and(rfLastBot()->body)->toContain("تم تسجيل طلب حضرتك برقم #{$case->id}")
        ->and($c->handler)->toBe(Handler::Bot)
        ->and(FlowState::flow($c))->toBeNull();
});
