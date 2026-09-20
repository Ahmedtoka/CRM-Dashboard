<?php

use App\Bot\Flow\ReplyScheduler;
use App\Bot\Flows\FakeFlowAnswerInterpreter;
use App\Bot\Flows\FlowAnswerInterpreter;
use App\Bot\Flows\FlowDefinition;
use App\Bot\Flows\FlowEngine;
use App\Bot\Flows\FlowResult;
use App\Bot\Flows\FlowState;
use App\Bot\Flows\OwnerFlowsUpgrade;
use App\Bot\Flows\Returns\RemoteProductLookup;
use App\Bot\Flows\Sandbox\FlowSandbox;
use App\Bot\Flows\Steps\ItemChangesStep;
use App\Bot\Flows\Steps\OrderItemsStep;
use App\Bot\Flows\TrackingFlowUpgrade;
use App\Channels\Data\InboundMessageData;
use App\Enums\Handler;
use App\Enums\Platform;
use App\Enums\SenderType;
use App\Enums\UserRole;
use App\Inbox\InboxIngestor;
use App\Models\BotFlow;
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
use App\Models\ProductVariant;
use App\Models\SupportCase;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;

// The owner's cancel/edit flow of 2026-09-19 (OwnerFlowsUpgrade::cancelEditDefinition()), end to end.

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

function oceSay(string $text, ?string $payload = null): Conversation
{
    static $n = 0;
    $n++;
    app(InboxIngestor::class)->ingestMessage(new InboundMessageData(
        Platform::Facebook, 'PAGE1', 'PSID-OCE', 'Mona', 'oce'.$n.'-'.uniqid(), $text, CarbonImmutable::now(), payload: $payload,
    ));

    return Conversation::firstOrFail();
}

function oceTurn(string $text, ?string $payload = null): FlowResult
{
    $c = oceSay($text, $payload);

    return app(FlowEngine::class)->handle($c, app(ReplyScheduler::class)->burst($c));
}

function oceTap(string $step, string $value, string $title = 'x'): FlowResult
{
    return oceTurn($title, "step:cancel_edit:{$step}:{$value}");
}

function oceFlow(): ?array
{
    return FlowState::flow(Conversation::firstOrFail());
}

function oceBot(): Message
{
    return Message::where('sender_type', SenderType::Bot->value)->latest('id')->firstOrFail();
}

/** @return list<string> */
function oceBodies(): array
{
    return Message::where('sender_type', SenderType::Bot->value)->orderBy('id')->pluck('body')->map(fn ($b) => (string) $b)->all();
}

/** @return list<string> */
function oceButtons(): array
{
    return array_column((array) oceBot()->buttons, 'title');
}

/** Order #1047 of سارة أحمد (mobile ending 4567), not shipped yet: a dress and 2 scarves. */
function oceOrder(array $attrs = []): Order
{
    $order = Order::factory()->create($attrs + [
        'order_number' => '1047',
        'shopify_order_name' => '#1047',
        'shipping_name' => 'سارة أحمد',
        'shipping_phone' => '+201001234567',
        'shipping_province_code' => 'C',
        'fulfillment_status' => null,
        'placed_at' => CarbonImmutable::parse('2026-09-18 10:00', 'Africa/Cairo'),
    ]);

    OrderItem::factory()->for($order)->create(['title' => 'فستان ليلى', 'qty' => 1, 'price' => 850, 'discount' => 0]);
    OrderItem::factory()->for($order)->create(['title' => 'طرحة شيفون', 'qty' => 1, 'price' => 150, 'discount' => 100]);

    return $order->fresh();
}

/** Starts the flow and finds the order by her mobile (ownership proven): the greeting is on screen. */
function oceGreeting(): void
{
    $c = oceSay('اهلا');
    app(FlowEngine::class)->runPayload($c, 'flow:cancel_edit');
    oceTurn('01001234567');
}

it('greets her by name with the order and asks cancel or edit', function () {
    oceOrder();
    $c = oceSay('اهلا');
    app(FlowEngine::class)->runPayload($c, 'flow:cancel_edit');

    expect(oceBot()->body)->toBe(OwnerFlowsUpgrade::ASK_ORDER_TEXT);

    oceTurn('01001234567');

    expect(oceFlow()['step'])->toBe('request')
        ->and(oceFlow()['data']['order_editable'])->toBe('yes')
        ->and(oceBot()->body)->toBe('أهلاً يا سارة 🌸 أوردر #1047 — تحبي تلغيه ولا تعدلي فيه؟')
        ->and(oceButtons())->toBe(['إلغاء', 'تعديل', 'القائمة الرئيسية']);
});

it('asks for the last 4 digits when only the order number was given', function () {
    oceOrder();
    $c = oceSay('اهلا');
    app(FlowEngine::class)->runPayload($c, 'flow:cancel_edit');
    oceTurn('1047');

    expect(oceBot()->body)->not->toContain('سارة')->not->toContain('#1047');

    oceTurn('٤٥٦٧');
    expect(oceFlow()['step'])->toBe('request')
        ->and(oceBot()->body)->toBe('أهلاً يا سارة 🌸 أوردر #1047 — تحبي تلغيه ولا تعدلي فيه؟');
});

it('refuses a shipped order with a person or «تمام», and records nothing', function (array $attrs, bool $withFulfillment) {
    $order = oceOrder($attrs);

    if ($withFulfillment) {
        Fulfillment::factory()->for($order)->create();
    }

    oceGreeting();

    expect(oceFlow()['step'])->toBe('shipped')
        ->and(oceFlow()['data']['order_editable'])->toBe('no')
        ->and(oceBot()->body)->toBe('للأسف الأوردر #1047 اتشحن خلاص فمينفعش نلغيه أو نعدل فيه 🙏')
        ->and(oceButtons())->toBe(['كلم موظف', 'تمام', 'القائمة الرئيسية']);

    oceTap('shipped', 'ok', 'تمام');

    expect(oceFlow())->toBeNull()
        ->and(oceBot()->body)->toBe(TrackingFlowUpgrade::THANKS_TEXT)
        ->and(SupportCase::count())->toBe(0)
        ->and(Conversation::first()->handler)->toBe(Handler::Bot);
})->with([
    'fulfilled' => [['fulfillment_status' => 'fulfilled'], false],
    'partially fulfilled counts as shipped' => [['fulfillment_status' => 'partial'], false],
    'a fulfillment row' => [[], true],
]);

it('hands a shipped order to a person on «كلم موظف»', function () {
    oceOrder(['fulfillment_status' => 'fulfilled']);
    oceGreeting();
    oceTap('shipped', 'agent', 'كلم موظف');

    expect(Conversation::first()->handler)->toBe(Handler::Human)
        ->and(oceBot()->body)->toBe('تمام ✅ هيتم تحويلك لموظف خدمة العملاء، هيرد عليكي في أقرب وقت 🌸');
});

it('says an already cancelled order is cancelled', function () {
    oceOrder(['cancelled_at' => now()->subDay()]);
    oceGreeting();

    expect(oceFlow()['step'])->toBe('already_cancelled')
        ->and(oceBot()->body)->toBe('الأوردر #1047 ملغي أصلاً 🌸')
        ->and(oceButtons())->toBe(['كلم موظف', 'تمام', 'القائمة الرئيسية']);
});

it('records a cancel request with her own reason, asking again for an empty or emoji-only reason', function () {
    $order = oceOrder();
    oceGreeting();
    oceTap('request', 'cancel', 'إلغاء');

    expect(oceBot()->body)->toBe('ممكن تكتبيلي سبب الإلغاء؟ 🙏')->and(oceButtons())->toBe([]);

    oceTurn('🙏');
    expect(oceFlow()['step'])->toBe('cancel_reason')
        ->and(array_slice(oceBodies(), -2))->toBe(['معلش مفهمتش 🙏', 'ممكن تكتبيلي سبب الإلغاء؟ 🙏'])
        ->and(SupportCase::count())->toBe(0);

    oceTurn('لقيت الموديل أرخص في مكان تاني');

    $case = SupportCase::sole();
    expect($case->type)->toBe('cancel_edit')
        ->and($case->order_id)->toBe($order->id)
        ->and($case->data['request'])->toBe('cancel')
        ->and($case->data['cancel_reason'])->toBe('لقيت الموديل أرخص في مكان تاني')
        ->and($case->summary)->toContain('المطلوب: إلغاء')->toContain('سبب الإلغاء: لقيت الموديل أرخص في مكان تاني')
        ->and($case->policy_notes)->toBe(['الأوردر لسه متشحنش وقت الطلب — اتأكدوا قبل ما يخرج من الشركة'])
        ->and(oceBot()->body)->toBe('تمام ✅ سجلت طلب إلغاء أوردر #1047، والفريق هيأكد معاكي الإلغاء في أقرب وقت 🌸')
        ->and(oceFlow())->toBeNull()
        ->and(implode("\n", oceBodies()))->not->toContain('#0');
});

it('edits the pieces: swap one by a store link, remove another, then records the changes with a note', function () {
    oceOrder();
    $product = Product::factory()->create(['title' => 'عباية كتان', 'handle' => 'abaya-linen']);
    ProductVariant::factory()->for($product)->create(['shopify_id' => '4001', 'title' => 'بيج / S', 'price' => 1200]);

    oceGreeting();
    oceTap('request', 'edit', 'تعديل');

    expect(oceBot()->body)->toBe('عايزة تعدلي إيه؟')
        ->and(oceButtons())->toBe(['القطع في الأوردر', 'العنوان', 'رقم الموبايل', 'القائمة الرئيسية']);

    oceTap('edit_what', 'items', 'القطع في الأوردر');

    // No return rules: the discounted scarf is offered like any piece, and no order header is repeated.
    $list = oceBot();
    expect($list->body)->toContain('1. فستان ليلى')->toContain('2. طرحة شيفون')->toEndWith(OrderItemsStep::PLAIN_TEXT)
        ->not->toContain('لقيت أوردر')
        ->and(oceButtons())->toBe(['فستان ليلى', 'طرحة شيفون']);

    oceTurn('1 و 2');
    expect(oceFlow()['step'])->toBe('item_changes')
        ->and(implode("\n", oceBodies()))->not->toContain('خصم')
        ->and(oceBot()->body)->toBe("«فستان ليلى»\nتحبي تبدليها ولا تشيليها من الأوردر؟")
        ->and(oceButtons())->toBe([ItemChangesStep::SWAP_BUTTON, ItemChangesStep::REMOVE_BUTTON]);

    oceTap('item_changes', 'swap', 'أبدلها');
    expect(oceBot()->body)->toBe(ItemChangesStep::SWAP_TEXT);

    oceTurn('ده اللينك https://levoilestores.com/products/abaya-linen?variant=4001');
    expect(oceBot()->body)->toBe("«طرحة شيفون»\nتحبي تبدليها ولا تشيليها من الأوردر؟");

    oceTurn('شيليها');

    $case = SupportCase::sole();
    $changes = $case->data['item_changes'];
    expect($case->data['request'])->toBe('edit')
        ->and($changes)->toHaveCount(2)
        ->and($changes[0]['action'])->toBe('swap')
        ->and($changes[0]['product']['title'])->toBe('عباية كتان')
        ->and($changes[0]['product']['variant_title'])->toBe('بيج / S')
        ->and($changes[1]['action'])->toBe('remove')
        ->and($changes[1]['title'])->toBe('طرحة شيفون')
        ->and(oceBot()->body)->toBe('تمام ✅ سجلت طلب تعديل أوردر #1047، والفريق هيأكد معاكي التعديل 🌸');

    $note = ConversationNote::where('body', 'like', '✏️%')->sole();
    expect($note->body)->toBe("✏️ تعديلات مطلوبة على أوردر #1047:\n"
        ."🔁 تبديل: فستان ليلى × 1 ← عباية كتان (بيج / S) — 1,200 ج.م — https://levoilestores.com/products/abaya-linen?variant=4001\n"
        .'❌ شيل: طرحة شيفون × 1')
        ->and($case->summary)->toContain('نوع التعديل: القطع في الأوردر')->toContain('❌ شيل: طرحة شيفون × 1');
});

it('keeps a typed new size or colour, asks once more for a link that matches nothing, and keeps a photo', function () {
    oceOrder();
    oceGreeting();
    oceTap('request', 'edit', 'تعديل');
    oceTap('edit_what', 'items', 'القطع في الأوردر');
    oceTurn('1 و 2');

    oceTurn('ابدلها');
    oceTurn('مقاس L لون أسود');
    oceTap('item_changes', 'swap', 'أبدلها');
    oceTurn('https://levoilestores.com/products/not-a-product');
    expect(oceBot()->body)->toBe('اللينك ده مش واضح، ابعتيه من صفحة المنتج على الموقع 🙏');

    // A screenshot instead: kept with the case photos.
    $c = Conversation::firstOrFail();
    $m = Message::factory()->for($c, 'conversation')->create(['direction' => 'in', 'sender_type' => 'customer', 'body' => null]);
    $photo = MessageAttachment::factory()->create(['message_id' => $m->id]);
    app(FlowEngine::class)->handle($c->fresh(), app(ReplyScheduler::class)->burst($c->fresh()));

    $case = SupportCase::sole();
    expect($case->data['item_changes'][0])->toMatchArray(['action' => 'swap', 'new_option' => 'مقاس L لون أسود'])
        ->and($case->data['item_changes'][1]['photo'])->toBe([$photo->id])
        ->and($case->photo_attachment_ids)->toBe([$photo->id])
        ->and($case->summary)->toContain('🔁 تبديل: فستان ليلى × 1 ← مقاس/لون جديد: «مقاس L لون أسود»');
});

it('records a new address', function () {
    oceOrder();
    oceGreeting();
    oceTap('request', 'edit', 'تعديل');
    oceTap('edit_what', 'address', 'العنوان');

    expect(oceBot()->body)->toBe('اكتبي العنوان الجديد بالتفصيل (المحافظة - المنطقة - الشارع)');

    oceTurn('القاهرة - مدينة نصر - 5 شارع مكرم عبيد');

    $case = SupportCase::sole();
    expect($case->data['new_address'])->toBe('القاهرة - مدينة نصر - 5 شارع مكرم عبيد')
        ->and(ConversationNote::where('body', "✏️ تعديلات مطلوبة على أوردر #1047:\n📍 العنوان الجديد: القاهرة - مدينة نصر - 5 شارع مكرم عبيد")->exists())->toBeTrue()
        ->and(oceBot()->body)->toBe('تمام ✅ سجلت طلب تعديل أوردر #1047، والفريق هيأكد معاكي التعديل 🌸');
});

it('records a new mobile (Arabic digits too)', function () {
    oceOrder();
    oceGreeting();
    oceTap('request', 'edit', 'تعديل');
    oceTurn('رقم الموبايل');

    expect(oceBot()->body)->toBe('اكتبي رقم الموبايل الجديد 📞');

    oceTurn('٠١١٢٢٣٣٤٤٥٥');

    expect(SupportCase::sole()->data['new_phone'])->toBe('01122334455')
        ->and(ConversationNote::where('body', 'like', '%📞 الموبايل الجديد: 01122334455')->exists())->toBeTrue();
});

it('offers a person when the order cannot be found', function () {
    $c = oceSay('اهلا');
    app(FlowEngine::class)->runPayload($c, 'flow:cancel_edit');
    oceTurn('5555');
    oceTurn('9999');

    expect(oceFlow()['step'])->toBe('not_found')
        ->and(oceBot()->body)->toBe(TrackingFlowUpgrade::NOT_FOUND_TEXT)
        ->and(oceButtons())->toBe(['كلم موظف', 'القائمة الرئيسية']);
});

it('validates and walks the published flow in the designer sandbox, showing the order number not #0', function () {
    oceOrder();
    $flow = BotFlow::where('key', 'cancel_edit')->firstOrFail();
    $user = User::factory()->create(['role' => UserRole::Admin]);
    $sandbox = app(FlowSandbox::class);

    expect(FlowDefinition::validate($flow->definition))->toBe([])
        ->and(FlowDefinition::warnings($flow->definition))->toBe([]);

    $r = $sandbox->run($flow, 'published', null, [], $user);
    foreach ([['text' => '01001234567'], ['payload' => 'step:cancel_edit:request:cancel'], ['text' => 'غيرت رأيي']] as $input) {
        $r = $sandbox->run($flow, 'published', $r['state'], $input, $user);
    }

    expect(collect($r['events'])->firstWhere('type', 'case'))->not->toBeNull()
        ->and(end($r['messages'])['text'])->toBe('تمام ✅ سجلت طلب إلغاء أوردر #1047، والفريق هيأكد معاكي الإلغاء في أقرب وقت 🌸')
        ->and(SupportCase::count())->toBe(0);
});
