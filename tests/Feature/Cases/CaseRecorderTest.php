<?php

use App\Bot\Flow\ReplyScheduler;
use App\Bot\Flows\FakeFlowAnswerInterpreter;
use App\Bot\Flows\FlowAnswerInterpreter;
use App\Bot\Flows\FlowEngine;
use App\Bot\Flows\FlowState;
use App\Cases\CaseRecorder;
use App\Cases\CaseSummary;
use App\Channels\Data\InboundMessageData;
use App\Enums\Handler;
use App\Enums\Platform;
use App\Enums\SenderType;
use App\Enums\ShipmentStatus;
use App\Enums\UserRole;
use App\Events\ConversationUpdated;
use App\Inbox\InboxIngestor;
use App\Models\BotSetting;
use App\Models\ChannelAccount;
use App\Models\Conversation;
use App\Models\ConversationNote;
use App\Models\Message;
use App\Models\MessageAttachment;
use App\Models\Order;
use App\Models\Shipment;
use App\Models\SupportCase;
use App\Models\User;
use App\Models\UserNotification;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    Event::fake();
    Http::preventStrayRequests();
    config(['crm.drivers.ai' => 'fake']);
    ChannelAccount::factory()->create(['platform' => Platform::Facebook, 'external_id' => 'PAGE1']);
    BotSetting::current()->update(['enabled' => false, 'working_hours' => null]);
    app()->bind(FlowAnswerInterpreter::class, FakeFlowAnswerInterpreter::class);
});

function crConversation(): Conversation
{
    app(InboxIngestor::class)->ingestMessage(new InboundMessageData(
        Platform::Facebook, 'PAGE1', 'PSID-CR', 'Mona', 'cr-'.uniqid(), 'اهلا', CarbonImmutable::now(),
    ));

    return Conversation::firstOrFail();
}

it('records a branch complaint with high priority, a note, a supervisor notification and a broadcast', function () {
    $c = crConversation();
    $supervisor = User::factory()->create(['role' => UserRole::Supervisor]);
    $admin = User::factory()->create(['role' => UserRole::Admin]);
    $inactive = User::factory()->create(['role' => UserRole::Supervisor, 'is_active' => false]);
    $moderator = User::factory()->create(['role' => UserRole::Moderator]);

    $case = app(CaseRecorder::class)->record($c, 'complaint', [
        'complaint_type' => 'branch', 'complaint_type_title' => 'فرع', 'branch_id' => 3, 'branch_name' => 'فرع عباس العقاد',
        'visit_date' => 'امبارح', 'name' => 'منى', 'phone' => '01012345678', 'description' => 'البياعة كانت مش لطيفة',
    ]);

    expect($case->type)->toBe('complaint')
        ->and($case->priority)->toBe('high')
        ->and($case->status)->toBe('new')
        ->and($case->platform)->toBe('facebook')
        ->and($case->customer_id)->toBe($c->customer_id)
        ->and($case->summary)->toBe(CaseSummary::text($case))
        ->and($case->summary)->toContain("📝 الطلب\nالنوع: فرع\nالفرع: فرع عباس العقاد\nتاريخ الزيارة: امبارح");

    $note = ConversationNote::where('conversation_id', $c->id)->sole();
    expect($note->user_id)->toBeNull()
        ->and($note->body)->toBe($case->summary)
        ->and($note->body)->toStartWith("📋 حالة #{$case->id} — شكوى — أولوية عالية\n\n👤 العميل\nمنى · 01012345678\n");

    $notified = UserNotification::where('type', 'case.created')->pluck('user_id')->all();
    expect($notified)->toEqualCanonicalizing([$supervisor->id, $admin->id])
        ->and($notified)->not->toContain($inactive->id)
        ->and($notified)->not->toContain($moderator->id);

    $data = UserNotification::where('user_id', $supervisor->id)->sole()->data;
    expect($data['case_id'])->toBe($case->id)
        ->and($data['conversation_id'])->toBe($c->id)
        ->and($data['type'])->toBe('complaint')
        ->and($data['excerpt'])->toBe("📋 حالة #{$case->id} — شكوى — أولوية عالية · النوع: فرع");

    Event::assertDispatched(ConversationUpdated::class);
    expect($c->fresh()->handler)->toBe(Handler::Bot);
});

it('gives medium priority to a service complaint and to a size return', function () {
    $c = crConversation();

    expect(app(CaseRecorder::class)->record($c, 'complaint', ['complaint_type' => 'service'])->priority)->toBe('medium')
        ->and(app(CaseRecorder::class)->record($c, 'return_exchange', ['reason' => 'size', 'request' => 'exchange'])->priority)->toBe('medium')
        ->and(app(CaseRecorder::class)->record($c, 'return_exchange', ['reason' => 'missing_item'])->priority)->toBe('high')
        ->and(app(CaseRecorder::class)->record($c, 'complaint', ['complaint_type' => 'delivery'])->priority)->toBe('high')
        ->and(app(CaseRecorder::class)->record($c, 'delivery_followup', [])->priority)->toBe('high');
});

it('adds return policy notes, the order and photo ids to a return case', function () {
    $c = crConversation();
    $order = Order::factory()->create(['order_number' => '7788', 'placed_at' => now()->subDays(40)]);
    $m = Message::factory()->for($c, 'conversation')->create(['direction' => 'in', 'sender_type' => 'customer', 'body' => null]);
    $a1 = MessageAttachment::factory()->create(['message_id' => $m->id]);
    $a2 = MessageAttachment::factory()->create(['message_id' => $m->id]);

    $case = app(CaseRecorder::class)->record($c, 'return_exchange', [
        'order_number' => '#7788', 'order_id' => $order->id, 'order_placed_at' => $order->placed_at->toIso8601String(),
        'reason' => 'defective', 'reason_title' => 'بايظ / فيه عيب', 'request' => 'exchange', 'request_title' => 'استبدال',
        'product_photo' => [$a1->id], 'defect_photo' => [$a2->id], 'case_id' => 99,
    ]);

    expect($case->order_id)->toBe($order->id)
        ->and($case->order_number)->toBe('#7788')
        ->and($case->photo_attachment_ids)->toBe([$a1->id, $a2->id])
        ->and($case->policy_notes)->toHaveCount(1)
        ->and($case->policy_notes[0])->toContain('عدى 14 يوم')
        ->and($case->data)->not->toHaveKey('case_id')
        ->and($case->summary)->toContain("📦 الأوردر #7788\n")
        ->and($case->summary)->toContain("📝 الطلب\nالسبب: بايظ / فيه عيب\nالمطلوب: استبدال")
        ->and($case->summary)->toContain("📎 المرفقات\nصورة المنتج ✅ · صورة العيب ✅")
        ->and($case->summary)->toContain("⚠️ تنبيهات\nغالبًا عدى 14 يوم");
});

it('notes the cancel/edit window left or over when the order was found', function () {
    $c = crConversation();
    $fresh = Order::factory()->create(['order_number' => '1001', 'placed_at' => now()->subMinutes(30)]);
    $old = Order::factory()->create(['order_number' => '1002', 'placed_at' => now()->subHours(3)]);

    $left = app(CaseRecorder::class)->record($c, 'cancel_edit', ['order_number' => '#1001', 'order_id' => $fresh->id, 'order_placed_at' => $fresh->placed_at->toIso8601String(), 'request' => 'cancel']);
    $over = app(CaseRecorder::class)->record($c, 'cancel_edit', ['order_number' => '#1002', 'order_id' => $old->id, 'order_placed_at' => $old->placed_at->toIso8601String(), 'request' => 'edit']);
    $none = app(CaseRecorder::class)->record($c, 'cancel_edit', ['order_ref_text' => 'مش فاكرة', 'request' => 'cancel']);

    expect($left->policy_notes)->toBe(['باقي على مهلة الإلغاء/التعديل: 90 دقيقة'])
        ->and($over->policy_notes)->toBe(['انتهت مهلة الإلغاء/التعديل'])
        ->and($none->policy_notes)->toBe([])
        ->and($none->order_id)->toBeNull();
});

it('records the case from the complaint flow and sends the closing script with the case number', function () {
    $c = crConversation();
    FlowState::put($c, ['key' => 'complaint', 'step' => 'summary', 'retries' => 0, 'started_at' => now()->toIso8601String(), 'data' => [
        'complaint_type' => 'service', 'complaint_type_title' => 'خدمة العملاء', 'name' => 'منى', 'phone' => '01012345678', 'description' => 'محدش بيرد',
    ]]);

    app(InboxIngestor::class)->ingestMessage(new InboundMessageData(
        Platform::Facebook, 'PAGE1', 'PSID-CR', 'Mona', 'cr-confirm', 'تمام، سجل', CarbonImmutable::now(), payload: 'step:complaint:summary:confirm',
    ));
    $c = $c->fresh();
    app(FlowEngine::class)->handle($c, app(ReplyScheduler::class)->burst($c));

    $case = SupportCase::sole();
    $last = Message::where('sender_type', SenderType::Bot->value)->latest('id')->first();
    expect($case->type)->toBe('complaint')
        ->and($case->data['description'])->toBe('محدش بيرد')
        ->and($last->body)->toContain("برقم #{$case->id}")
        ->and(FlowState::flow($c->fresh()))->toBeNull()
        ->and($c->fresh()->handler)->toBe(Handler::Bot);
});

/** Tracks the order on $phone and taps «الأوردر اتأخر» on its status card; returns the bot's last reply. */
function crTrackLate(string $phone): ?string
{
    $c = Conversation::firstOrFail();
    app(FlowEngine::class)->start($c, 'order_tracking');

    foreach ([[$phone, null], ['الأوردر اتأخر', 'step:order_tracking:status:late']] as [$text, $payload]) {
        app(InboxIngestor::class)->ingestMessage(new InboundMessageData(
            Platform::Facebook, 'PAGE1', 'PSID-CR', 'Mona', 'cr-t-'.uniqid(), $text, CarbonImmutable::now(), payload: $payload,
        ));
        $c = $c->fresh();
        app(FlowEngine::class)->handle($c, app(ReplyScheduler::class)->burst($c));
    }

    return Message::where('sender_type', SenderType::Bot->value)->latest('id')->value('body');
}

it('records a delivery follow-up case when she says a returned order is late', function () {
    $c = crConversation();
    $order = Order::factory()->create(['order_number' => '4455', 'shipping_phone' => '+201001234567']);
    Shipment::factory()->for($order)->create(['status' => ShipmentStatus::Returned]);

    crTrackLate('01001234567');

    $case = SupportCase::sole();
    expect($case->type)->toBe('delivery_followup')
        ->and($case->priority)->toBe('high')
        ->and($case->order_id)->toBe($order->id)
        ->and($case->order_number)->toBe('#4455');
});

it('includes the latest cases in the conversation JSON', function () {
    $c = crConversation();
    $admin = User::factory()->create(['role' => UserRole::Admin]);
    $m = Message::factory()->for($c, 'conversation')->create(['direction' => 'in', 'sender_type' => 'customer', 'body' => null]);
    $a = MessageAttachment::factory()->stored()->create(['message_id' => $m->id]);

    app(CaseRecorder::class)->record($c, 'complaint', ['complaint_type' => 'service']);
    $latest = app(CaseRecorder::class)->record($c, 'return_exchange', ['reason' => 'size', 'request' => 'exchange', 'product_photo' => [$a->id]]);

    $this->actingAs($admin)->getJson("/inbox/conversations/{$c->id}")
        ->assertOk()
        ->assertJsonCount(2, 'cases')
        ->assertJsonPath('cases.0.id', $latest->id)
        ->assertJsonPath('cases.0.type_label', 'مرتجع/استبدال')
        ->assertJsonPath('cases.0.status', 'new')
        ->assertJsonPath('cases.0.photos.0.id', $a->id)
        ->assertJsonPath('cases.0.photos.0.url', route('media.show', $a, false))
        ->assertJsonPath('cases.0.customer.id', $c->customer_id)
        ->assertJsonPath('cases.0.assigned_to', null)
        ->assertJsonPath('cases.1.type_label', 'شكوى');
});

it('lets only users with access to the conversation platform view or update a case', function () {
    $c = crConversation();
    $case = app(CaseRecorder::class)->record($c, 'complaint', ['complaint_type' => 'service']);
    $fb = User::factory()->create(['role' => UserRole::Moderator]);
    $fb->userPlatforms()->create(['platform' => Platform::Facebook]);
    $wa = User::factory()->create(['role' => UserRole::Moderator]);
    $wa->userPlatforms()->create(['platform' => Platform::WhatsApp]);

    expect(Gate::forUser($fb)->allows('view', $case))->toBeTrue()
        ->and(Gate::forUser($fb)->allows('update', $case))->toBeTrue()
        ->and(Gate::forUser($wa)->allows('view', $case))->toBeFalse()
        ->and(Gate::forUser($wa)->allows('update', $case))->toBeFalse();
});

it('hands the customer to a person when the case cannot be recorded', function () {
    $c = crConversation();
    $this->mock(CaseRecorder::class)->shouldReceive('record')->andThrow(new RuntimeException('db down'));
    FlowState::put($c, ['key' => 'cancel_edit', 'step' => 'summary', 'retries' => 0, 'started_at' => now()->toIso8601String(), 'data' => [
        'order_ref_text' => 'مش فاكرة', 'request' => 'cancel', 'request_title' => 'إلغاء الأوردر',
    ]]);

    app(InboxIngestor::class)->ingestMessage(new InboundMessageData(
        Platform::Facebook, 'PAGE1', 'PSID-CR', 'Mona', 'cr-fail', 'تمام، سجل', CarbonImmutable::now(), payload: 'step:cancel_edit:summary:confirm',
    ));
    $c = $c->fresh();
    app(FlowEngine::class)->handle($c, app(ReplyScheduler::class)->burst($c));

    expect(SupportCase::count())->toBe(0)
        ->and($c->fresh()->handler)->toBe(Handler::Human)
        ->and(FlowState::flow($c->fresh()))->toBeNull()
        ->and(Message::where('sender_type', SenderType::Bot->value)->where('body', 'like', '%برقم #%')->exists())->toBeFalse();
});

it('rejects an unknown case type', function () {
    app(CaseRecorder::class)->record(crConversation(), 'refund_only', []);
})->throws(InvalidArgumentException::class);

it('keeps one open delivery follow-up case per order when she tracks it again', function () {
    $supervisor = User::factory()->create(['role' => UserRole::Supervisor]);
    $held = Order::factory()->create(['order_number' => '6601', 'shipping_phone' => '+201001111111']);
    Shipment::factory()->for($held)->create(['status' => ShipmentStatus::Returned]);
    $other = Order::factory()->create(['order_number' => '6602', 'shipping_phone' => '+201002222222']);
    Shipment::factory()->for($other)->create(['status' => ShipmentStatus::Returned]);

    $phones = ['6601' => '01001111111', '6602' => '01002222222'];
    $track = fn (string $number) => crTrackLate($phones[$number]);

    $c = crConversation();
    $recorded = 'سجلت طلب متابعة للأوردر #6601 🌸 الفريق هيتابع مع شركة الشحن ويرد عليكي في أقرب وقت';
    expect($track('6601'))->toBe($recorded)
        ->and($track('6601'))->toBe($recorded);

    expect(SupportCase::count())->toBe(1)
        ->and(ConversationNote::where('conversation_id', $c->id)->count())->toBe(1)
        ->and(UserNotification::where('user_id', $supervisor->id)->where('type', 'case.created')->count())->toBe(1);

    $track('6602');
    expect(SupportCase::where('type', 'delivery_followup')->pluck('order_id')->all())->toEqualCanonicalizing([$held->id, $other->id]);

    // A closed case does not block a new one; an order known only by number is matched by number.
    SupportCase::where('order_id', $held->id)->update(['status' => 'closed', 'closed_at' => now()]);
    $track('6601');
    $recorder = app(CaseRecorder::class);
    $a = $recorder->record($c, 'delivery_followup', ['order_number' => '#9999']);
    $b = $recorder->record($c, 'delivery_followup', ['order_number' => '#9999']);
    expect(SupportCase::where('order_id', $held->id)->count())->toBe(2)
        ->and($b->id)->toBe($a->id)
        ->and(SupportCase::count())->toBe(4);
});
