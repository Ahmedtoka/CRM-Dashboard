<?php

use App\Bot\Flows\FakeFlowAnswerInterpreter;
use App\Bot\Flows\FlowAnswer;
use App\Bot\Flows\FlowAnswerInterpreter;
use App\Bot\Flows\FlowDefinitionSource;
use App\Bot\Flows\FlowDrafts;
use App\Bot\Flows\FlowEngine;
use App\Bot\Flows\PublishedFlowDefinitions;
use App\Bot\Flows\Sandbox\FlowSandbox;
use App\Bot\Flows\Sandbox\SandboxCaseRecorder;
use App\Bot\Flows\Sandbox\SandboxMode;
use App\Cases\CaseRecorder;
use App\Channels\Adapters\FakeChannelAdapter;
use App\Enums\ShipmentStatus;
use App\Enums\UserRole;
use App\Events\ConversationUpdated;
use App\Inbox\OutboundService;
use App\Models\BotFlow;
use App\Models\Conversation;
use App\Models\Customer;
use App\Models\Message;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Shipment;
use App\Models\SupportCase;
use App\Models\User;
use App\Support\SafeBroadcast;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    Queue::fake();
    Event::fake();
    Http::preventStrayRequests();
    FakeChannelAdapter::reset();
    config(['crm.drivers.ai' => 'fake']);
    app()->bind(FlowAnswerInterpreter::class, FakeFlowAnswerInterpreter::class);
    $this->sup = User::factory()->create(['role' => UserRole::Supervisor]);
});

function sbRun(string $key, string $source, ?array $state, array $input): array
{
    return app(FlowSandbox::class)->run(BotFlow::where('key', $key)->firstOrFail(), $source, $state, $input, test()->sup);
}

function sbTexts(array $result): string
{
    return implode("\n", array_column($result['messages'], 'text'));
}

it('starts the flow under test and shows the policy and the order prompt', function () {
    $r = sbRun('return_exchange', 'published', null, []);

    expect(sbTexts($r))->toContain('14 يوم')->toContain('رقم الأوردر')
        ->and($r['current'])->toBe(['flow_key' => 'return_exchange', 'step_id' => 'order'])
        ->and($r['state']['flow']['key'])->toBe('return_exchange')
        ->and($r['events'])->toBe([]);
});

it('walks the whole return flow with state threaded through and saves nothing', function () {
    $order = Order::factory()->create(['order_number' => '1047', 'shipping_name' => 'سارة أحمد', 'shipping_phone' => '+201001234567']);
    $item = OrderItem::factory()->for($order)->create(['title' => 'فستان ليلى', 'qty' => 1, 'price' => 850, 'discount' => 0]);
    Shipment::factory()->for($order)->create(['status' => ShipmentStatus::Delivered]);
    $customers = Customer::count();

    $r = sbRun('return_exchange', 'published', null, []);

    // The order number alone: only "found it" and the ownership question.
    $r = sbRun('return_exchange', 'published', $r['state'], ['text' => '1047']);
    expect($r['current']['step_id'])->toBe('order')
        ->and(sbTexts($r))->toContain('آخر ٤ أرقام')->not->toContain('سارة')->not->toContain('فستان');

    $r = sbRun('return_exchange', 'published', $r['state'], ['text' => '4567']);
    expect($r['current']['step_id'])->toBe('order_items')
        ->and(sbTexts($r))->toContain('لقيت أوردر #1047 باسم سارة أحمد')->toContain('1. فستان ليلى × 1 — 850 ج.م');

    $r = sbRun('return_exchange', 'published', $r['state'], ['payload' => "step:return_exchange:order_items:item:{$item->id}"]);
    expect($r['current']['step_id'])->toBe('reason')
        ->and($r['messages'])->not->toBeEmpty()
        ->and(end($r['messages'])['buttons'])->toHaveCount(6);

    $r = sbRun('return_exchange', 'published', $r['state'], ['payload' => 'step:return_exchange:reason:defective']);
    expect($r['current']['step_id'])->toBe('request')
        ->and(end($r['messages'])['buttons'])->toHaveCount(3);

    $r = sbRun('return_exchange', 'published', $r['state'], ['payload' => 'step:return_exchange:request:exchange']);
    expect($r['current']['step_id'])->toBe('product_photo')
        ->and(sbTexts($r))->toContain('صورة');

    $r = sbRun('return_exchange', 'published', $r['state'], ['photo' => true]);
    expect($r['current']['step_id'])->toBe('defect_photo')
        ->and(sbTexts($r))->toContain('وصلتني الصورة')->toContain('العيب');

    $r = sbRun('return_exchange', 'published', $r['state'], ['photo' => true]);
    expect($r['current']['step_id'])->toBe('summary')
        ->and(end($r['messages'])['buttons'])->toHaveCount(2);

    $r = sbRun('return_exchange', 'published', $r['state'], ['payload' => 'step:return_exchange:summary:confirm']);
    $case = collect($r['events'])->firstWhere('type', 'case');
    expect($case)->not->toBeNull()
        ->and($case['label'])->toBe('هيتسجل حالة: '.SupportCase::TYPE_LABELS['return_exchange'])
        ->and($case['data']['reason'])->toBe('defective')
        ->and($case['data']['selected_items'][0]['title'])->toBe('فستان ليلى')
        ->and(sbTexts($r))->toContain('#0')
        ->and($r['current'])->toBeNull();

    expect(SupportCase::count())->toBe(0)
        ->and(Conversation::count())->toBe(0)
        ->and(Message::count())->toBe(0)
        ->and(Customer::count())->toBe($customers)
        ->and(FakeChannelAdapter::sent())->toBe([])
        ->and(SandboxMode::active())->toBeFalse()
        ->and(app(OutboundService::class))->toBeInstanceOf(OutboundService::class)
        ->and(get_class(app(OutboundService::class)))->toBe(OutboundService::class);
    Queue::assertNothingPushed();
});

it('runs the draft or the published definition of the flow under test', function () {
    $flow = BotFlow::where('key', 'branches')->firstOrFail();
    $old = $flow->definition['steps']['list']['text'];
    $definition = $flow->definition;
    $definition['steps']['list']['text'] = 'نص تجريبي جديد للفروع';
    app(FlowDrafts::class)->saveDraft($flow, $definition, $this->sup);

    expect(sbTexts(sbRun('branches', 'draft', null, [])))->toContain('نص تجريبي جديد للفروع')
        ->and(sbTexts(sbRun('branches', 'published', null, [])))->toContain($old)->not->toContain('نص تجريبي جديد');
});

it('runs an inactive flow under test', function () {
    BotFlow::where('key', 'branches')->update(['is_active' => false]);

    expect(sbRun('branches', 'published', null, [])['current'])->toBe(['flow_key' => 'branches', 'step_id' => 'list']);
});

it('records handover, flow start and exit events from the main menu', function () {
    $menu = sbRun('main_menu', 'published', null, []);
    expect(end($menu['messages'])['buttons'])->not->toBeEmpty();

    $handover = sbRun('main_menu', 'published', $menu['state'], ['payload' => 'handover']);
    expect(collect($handover['events'])->pluck('type')->all())->toBe(['handover'])
        ->and($handover['events'][0]['label'])->toBe('هيتحول لموظف')
        ->and($handover['current'])->toBeNull();

    $start = sbRun('main_menu', 'published', $menu['state'], ['payload' => 'flow:complaint']);
    expect($start['events'][0]['type'])->toBe('flow_start')
        ->and($start['events'][0]['label'])->toBe('بدء فلو: شكوى')
        ->and($start['current']['flow_key'])->toBe('complaint');

    $exit = sbRun('main_menu', 'published', $start['state'], ['payload' => 'menu:main_menu']);
    expect(collect($exit['events'])->pluck('type')->all())->toBe(['exit'])
        ->and($exit['current']['flow_key'])->toBe('main_menu');

    expect(Conversation::count())->toBe(0)->and(Message::count())->toBe(0);
    Queue::assertNothingPushed();
});

it('rolls back, resets sandbox mode and restores the bindings when the run throws', function () {
    $level = DB::transactionLevel();
    $outbound = app(OutboundService::class);
    app()->bind(FlowEngine::class, fn () => throw new RuntimeException('boom'));

    expect(fn () => sbRun('return_exchange', 'published', null, []))->toThrow(RuntimeException::class, 'boom');

    expect(DB::transactionLevel())->toBe($level)
        ->and(SandboxMode::active())->toBeFalse()
        ->and(app(OutboundService::class))->toBe($outbound)
        ->and(app(CaseRecorder::class))->not->toBeInstanceOf(SandboxCaseRecorder::class)
        ->and(app(FlowDefinitionSource::class))->toBeInstanceOf(PublishedFlowDefinitions::class)
        ->and(Conversation::count())->toBe(0);
});

it('explains a question turn that sends nothing', function () {
    app()->instance(FlowAnswerInterpreter::class, new class implements FlowAnswerInterpreter
    {
        public function interpret(array $step, string $text, array $history): FlowAnswer
        {
            return new FlowAnswer('question');
        }
    });

    $start = sbRun('complaint', 'published', null, []);
    $r = sbRun('complaint', 'published', $start['state'], ['text' => 'الشحن بياخد قد ايه؟']);

    expect($r['messages'])->toBe([])
        ->and(collect($r['events'])->pluck('type')->all())->toBe(['question'])
        ->and($r['events'][0]['label'])->toBe('السؤال ده هيرد عليه الـ Agent في الحقيقة، وبعدين يرجع يسأل نفس الخطوة')
        ->and($r['current'])->toBe(['flow_key' => 'complaint', 'step_id' => 'type']);
});

it('flags a draft that cannot start as invalid', function () {
    $flow = BotFlow::where('key', 'branches')->firstOrFail();
    app(FlowDrafts::class)->saveDraft($flow, ['start' => 'missing', 'steps' => []], $this->sup);

    $r = sbRun('branches', 'draft', null, []);

    expect($r['messages'])->toBe([])
        ->and($r['events'])->toBe([['type' => 'invalid', 'label' => 'الفلو ده فيه أخطاء ومش هيشتغل', 'data' => []]])
        ->and($r['current'])->toBeNull();
});

it('answers a malformed state with 422 and saves nothing', function () {
    $flow = BotFlow::where('key', 'return_exchange')->firstOrFail();
    $customers = Customer::count();

    $this->actingAs($this->sup)->postJson("/settings/bot-flows/{$flow->id}/simulate", [
        'source' => 'published',
        'state' => ['flow' => ['key' => 'return_exchange', 'step' => ['bad'], 'data' => []]],
        'input' => ['text' => '1047'],
    ])->assertStatus(422)->assertJsonPath('message', 'حصلت مشكلة في تجربة الفلو، جرّب تبدأ من جديد');

    expect(Conversation::count())->toBe(0)
        ->and(Message::count())->toBe(0)
        ->and(Customer::count())->toBe($customers)
        ->and(SandboxMode::active())->toBeFalse()
        ->and(get_class(app(OutboundService::class)))->toBe(OutboundService::class);
});

it('makes SafeBroadcast a no-op while sandbox mode is active', function () {
    SandboxMode::set(true);

    try {
        SafeBroadcast::send(new ConversationUpdated(Conversation::factory()->create()));
    } finally {
        SandboxMode::set(false);
    }

    Event::assertNotDispatched(ConversationUpdated::class);
});

it('serves the simulate route to supervisors only', function () {
    $flow = BotFlow::where('key', 'return_exchange')->firstOrFail();
    $mod = User::factory()->create(['role' => UserRole::Moderator]);

    $this->actingAs($this->sup)->postJson("/settings/bot-flows/{$flow->id}/simulate", ['source' => 'published', 'state' => null, 'input' => []])
        ->assertOk()
        ->assertJsonStructure(['messages' => [['text', 'buttons']], 'events', 'state', 'current' => ['flow_key', 'step_id']])
        ->assertJsonPath('current.step_id', 'order');

    $this->actingAs($this->sup)->postJson("/settings/bot-flows/{$flow->id}/simulate", ['source' => 'nope', 'input' => []])
        ->assertStatus(422);

    $this->actingAs($mod)->postJson("/settings/bot-flows/{$flow->id}/simulate", ['source' => 'published', 'input' => []])
        ->assertForbidden();

    expect(Conversation::count())->toBe(0);
});
