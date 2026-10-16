<?php

use App\Channels\Adapters\FakeChannelAdapter;
use App\Confirmations\ConfirmationPayload;
use App\Confirmations\ConfirmationSettings;
use App\Confirmations\Contracts\ConfirmationSender;
use App\Confirmations\WhatsApp\TemplateParams;
use App\Confirmations\WhatsApp\WhatsAppConfirmationSender;
use App\Enums\MessageStatus;
use App\Enums\OrderConfirmationStatus;
use App\Enums\OrderStatus;
use App\Enums\Platform;
use App\Enums\SenderType;
use App\Models\ChannelAccount;
use App\Models\Conversation;
use App\Models\Customer;
use App\Models\CustomerIdentity;
use App\Models\Message;
use App\Models\Order;
use App\Models\OrderConfirmation;
use App\Models\OrderItem;

// Slice W2: the confirmation / reminder template goes out through the normal outbound path.

beforeEach(function () {
    FakeChannelAdapter::reset();
    app(ConfirmationSettings::class)->save(['enabled' => true]);
    $this->account = ChannelAccount::factory()->create(['platform' => Platform::WhatsApp, 'external_id' => '1098765432']);
    $this->customer = Customer::factory()->create(['name' => 'ليلى أحمد']);
    $this->order = Order::factory()->for($this->customer)->create([
        'shopify_order_name' => '#1234', 'order_number' => '1234', 'shipping_name' => 'ليلى أحمد',
        'total' => 1250, 'currency' => 'EGP', 'financial_status' => 'pending',
        'shipping_province' => 'القاهرة', 'shipping_city' => 'مدينة نصر', 'shipping_address' => '12 شارع مكرم عبيد، الدور الثالث، شقة 7',
    ]);
    OrderItem::factory()->create(['order_id' => $this->order->id, 'title' => 'فستان سهرة', 'qty' => 2]);
    OrderItem::factory()->create(['order_id' => $this->order->id, 'title' => 'طرحة', 'qty' => 1]);
    $this->confirmation = OrderConfirmation::factory()->create([
        'order_id' => $this->order->id, 'channel_account_id' => $this->account->id, 'phone_e164' => '+201112223334',
    ]);
});

it('builds the body params in the template order', function () {
    expect(TemplateParams::BODY)->toBe(['first_name', 'order_number', 'items', 'total', 'address', 'payment'])
        ->and(array_keys(TemplateParams::EXAMPLES))->toBe(TemplateParams::BODY)
        ->and(TemplateParams::forOrder($this->order->fresh()))->toBe([
            'first_name' => 'ليلى',
            'order_number' => '#1234',
            'items' => 'فستان سهرة ×2، طرحة',
            'total' => '1,250 ج.م',
            'address' => 'القاهرة - 12 شارع مكرم عبيد، الدور الثا…',
            'payment' => 'الدفع عند الاستلام',
        ]);

    $this->order->forceFill(['financial_status' => 'paid', 'total' => 999.5])->save();
    $params = TemplateParams::forOrder($this->order->fresh());
    expect($params['payment'])->toBe('اتأكدنا من الدفع')->and($params['total'])->toBe('999.50 ج.م');
});

it('keeps the items and address short and never empty', function () {
    OrderItem::factory()->create(['order_id' => $this->order->id, 'title' => str_repeat('بلوزة قطن ', 8), 'qty' => 1]);
    expect(mb_strlen(TemplateParams::forOrder($this->order->fresh())['items']))->toBeLessThanOrEqual(60);

    $empty = Order::factory()->create(['shipping_name' => null, 'shipping_province' => null, 'shipping_city' => null, 'shipping_address' => null]);
    $params = TemplateParams::forOrder($empty);
    expect($params['items'])->not->toBe('')->and($params['first_name'])->not->toBe('')
        ->and($params['address'])->toBe(TemplateParams::NO_ADDRESS);

    $cityOnly = Order::factory()->create(['shipping_province' => null, 'shipping_city' => 'الإسكندرية', 'shipping_address' => "سموحة\nش فوزي معاذ"]);
    expect(TemplateParams::forOrder($cityOnly)['address'])->toBe('الإسكندرية - سموحة ش فوزي معاذ');
});

it('sends the confirmation template with button payloads and records it in the conversation', function () {
    app(ConfirmationSender::class)->send($this->confirmation, 'confirm');

    $row = $this->confirmation->fresh();
    $message = Message::find($row->outbound_message_id);
    $conversation = Conversation::find($row->conversation_id);

    expect($row->status)->toBe(OrderConfirmationStatus::Sent)
        ->and($row->sent_at)->not->toBeNull()
        ->and($row->last_error)->toBeNull()
        ->and($row->customer_id)->toBe($this->customer->id)
        ->and($conversation->channel_account_id)->toBe($this->account->id)
        ->and($conversation->customer_id)->toBe($this->customer->id)
        ->and($message->conversation_id)->toBe($conversation->id)
        ->and($message->status)->toBe(MessageStatus::Sent)
        ->and($message->sender_type)->toBe(SenderType::Bot)
        ->and($message->is_template)->toBeTrue()
        ->and($message->body)->toContain('#1234')
        ->and($message->template)->toBe([
            'name' => 'order_confirm_ar',
            'language' => 'ar',
            'params' => ['ليلى', '#1234', 'فستان سهرة ×2، طرحة', '1,250 ج.م', 'القاهرة - 12 شارع مكرم عبيد، الدور الثا…', 'الدفع عند الاستلام'],
            'buttons' => [ConfirmationPayload::make($row->id, 'confirm'), ConfirmationPayload::make($row->id, 'edit'), ConfirmationPayload::make($row->id, 'cancel')],
        ]);

    $identity = CustomerIdentity::where('platform', Platform::WhatsApp)->where('external_id', '201112223334')->first();
    expect($identity->customer_id)->toBe($this->customer->id);

    $sent = FakeChannelAdapter::sent();
    expect($sent)->toHaveCount(1)->and($sent[0]['options']['template'])->toBe($message->template);
});

it('reuses the existing WhatsApp identity and conversation for the number', function () {
    $other = Customer::factory()->create();
    CustomerIdentity::factory()->for($other)->create(['platform' => Platform::WhatsApp, 'external_id' => '201112223334']);
    $existing = Conversation::factory()->for($other)->for($this->account, 'channelAccount')->create(['platform' => Platform::WhatsApp]);

    app(ConfirmationSender::class)->send($this->confirmation, 'confirm');

    expect($this->confirmation->fresh()->conversation_id)->toBe($existing->id)
        ->and($this->confirmation->fresh()->customer_id)->toBe($other->id)
        ->and(Conversation::count())->toBe(1);
});

it('gives the number its own customer when the order customer already has another WhatsApp number', function () {
    CustomerIdentity::factory()->for($this->customer)->create(['platform' => Platform::WhatsApp, 'external_id' => '201000000000']);

    app(ConfirmationSender::class)->send($this->confirmation, 'confirm');

    $identity = CustomerIdentity::where('external_id', '201112223334')->first();
    expect($identity->customer_id)->not->toBe($this->customer->id)
        ->and(FakeChannelAdapter::sent()[0]['to'])->toBe('201112223334');
});

it('marks the row failed with the error instead of throwing', function () {
    FakeChannelAdapter::failNext('(132001) template does not exist');

    app(ConfirmationSender::class)->send($this->confirmation, 'confirm');

    $row = $this->confirmation->fresh();
    expect($row->status)->toBe(OrderConfirmationStatus::Failed)
        ->and($row->last_error)->toContain('template does not exist')
        ->and(Message::find($row->outbound_message_id)->status)->toBe(MessageStatus::Failed);
});

it('fails cleanly without a usable WhatsApp account', function () {
    $this->account->forceFill(['status' => 'disconnected'])->save();

    app(ConfirmationSender::class)->send($this->confirmation, 'confirm');

    expect($this->confirmation->fresh()->status)->toBe(OrderConfirmationStatus::Failed)
        ->and($this->confirmation->fresh()->last_error)->toBe('no_whatsapp_account')
        ->and(FakeChannelAdapter::sent())->toBe([]);
});

it('never sends the confirmation twice', function () {
    app(ConfirmationSender::class)->send($this->confirmation, 'confirm');
    app(ConfirmationSender::class)->send($this->confirmation->fresh(), 'confirm');

    expect(FakeChannelAdapter::sent())->toHaveCount(1);
});

it('sends one reminder with the same buttons and keeps the row awaiting', function () {
    app(ConfirmationSender::class)->send($this->confirmation, 'confirm');
    app(ConfirmationSender::class)->send($this->confirmation->fresh(), 'reminder');
    app(ConfirmationSender::class)->send($this->confirmation->fresh(), 'reminder');

    $row = $this->confirmation->fresh();
    $reminder = Message::find($row->reminder_message_id);

    expect($row->status)->toBe(OrderConfirmationStatus::Sent)
        ->and($row->reminded_at)->not->toBeNull()
        ->and($reminder->template['name'])->toBe('order_reminder_ar')
        ->and($reminder->template['buttons'][2])->toBe(ConfirmationPayload::make($row->id, 'cancel'))
        ->and($reminder->conversation_id)->toBe($row->conversation_id)
        ->and(FakeChannelAdapter::sent())->toHaveCount(2);
});

it('keeps the row awaiting when only the reminder fails', function () {
    app(ConfirmationSender::class)->send($this->confirmation, 'confirm');
    FakeChannelAdapter::failNext('boom');
    app(ConfirmationSender::class)->send($this->confirmation->fresh(), 'reminder');

    $row = $this->confirmation->fresh();
    expect($row->status)->toBe(OrderConfirmationStatus::Sent)
        ->and($row->reminded_at)->not->toBeNull()
        ->and($row->last_error)->toBe('boom');
});

it('marks the row failed when WhatsApp later reports the template undelivered', function () {
    app(ConfirmationSender::class)->send($this->confirmation, 'confirm');
    $message = Message::find($this->confirmation->fresh()->outbound_message_id);

    $message->forceFill(['status' => MessageStatus::Failed, 'error' => '(131026) undeliverable'])->save();

    expect($this->confirmation->fresh()->status)->toBe(OrderConfirmationStatus::Failed)
        ->and($this->confirmation->fresh()->last_error)->toBe('(131026) undeliverable');
});

it('sends nothing while the feature is off, and parks the confirmation as skipped', function () {
    app(ConfirmationSettings::class)->save(['enabled' => false]);

    app(ConfirmationSender::class)->send($this->confirmation, 'confirm');

    expect($this->confirmation->fresh()->status)->toBe(OrderConfirmationStatus::Skipped)
        ->and($this->confirmation->fresh()->skip_reason)->toBe('disabled')
        ->and(FakeChannelAdapter::sent())->toBe([]);
});

it('never confirms or reminds about a cancelled order', function () {
    app(ConfirmationSender::class)->send($this->confirmation, 'confirm');
    $this->order->forceFill(['status' => OrderStatus::Cancelled, 'cancelled_at' => now()])->save();

    app(ConfirmationSender::class)->send($this->confirmation->fresh(), 'reminder');

    $row = $this->confirmation->fresh();
    expect($row->reminded_at)->toBeNull()->and($row->status)->toBe(OrderConfirmationStatus::Sent)
        ->and(FakeChannelAdapter::sent())->toHaveCount(1);

    $other = OrderConfirmation::factory()->create([
        'order_id' => Order::factory()->create(['status' => OrderStatus::Cancelled, 'cancelled_at' => now()])->id,
        'channel_account_id' => $this->account->id, 'phone_e164' => '+201112220000',
    ]);
    app(ConfirmationSender::class)->send($other, 'confirm');

    expect($other->fresh()->status)->toBe(OrderConfirmationStatus::Skipped)
        ->and($other->fresh()->skip_reason)->toBe('cancelled')
        ->and(FakeChannelAdapter::sent())->toHaveCount(1);
});

it('sends no reminder while the feature is off', function () {
    app(ConfirmationSender::class)->send($this->confirmation, 'confirm');
    app(ConfirmationSettings::class)->save(['enabled' => false]);

    app(ConfirmationSender::class)->send($this->confirmation->fresh(), 'reminder');

    expect($this->confirmation->fresh()->reminded_at)->toBeNull()->and(FakeChannelAdapter::sent())->toHaveCount(1);
});

it('sends a test even while the feature is off, without giving the phone to the order customer', function () {
    app(ConfirmationSettings::class)->save(['enabled' => false]);

    app(ConfirmationSender::class)->send($this->confirmation, WhatsAppConfirmationSender::TEST);

    $row = $this->confirmation->fresh();
    $identity = CustomerIdentity::where('platform', Platform::WhatsApp)->where('external_id', '201112223334')->first();
    $message = Message::find($row->outbound_message_id);

    expect($row->status)->toBe(OrderConfirmationStatus::Sent)
        ->and($identity->customer_id)->not->toBe($this->customer->id)
        ->and($identity->customer->name)->not->toBe($this->customer->name)
        ->and($row->customer_id)->toBe($identity->customer_id)
        ->and($message->template['name'])->toBe('order_confirm_ar')
        ->and(FakeChannelAdapter::sent()[0]['to'])->toBe('201112223334');
});
