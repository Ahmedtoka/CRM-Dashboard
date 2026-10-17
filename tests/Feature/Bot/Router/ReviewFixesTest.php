<?php

use App\Bot\ArabicNormalizer;
use App\Bot\Flows\EntityExtractor;
use App\Bot\Flows\FlowEngine;
use App\Bot\Flows\FlowState;
use App\Bot\Flows\Returns\ReturnPrefill;
use App\Bot\OrderFactGuard;
use App\Bot\Router\CaseLock;
use App\Bot\Router\TaskOrchestrator;
use App\Bot\Router\TaskPlan;
use App\Bot\Router\TaskQueue;
use App\Cases\CaseSummary;
use App\Channels\Data\InboundMessageData;
use App\Enums\Handler;
use App\Enums\Platform;
use App\Enums\SenderType;
use App\Inbox\InboxIngestor;
use App\Models\BotFlow;
use App\Models\BotRule;
use App\Models\BotSetting;
use App\Models\ChannelAccount;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\SupportCase;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;

// Regression tests for the code review of feature/bot-hybrid (findings #2, #6, #8–#12, #20, #5).

beforeEach(function () {
    Event::fake();
    Http::preventStrayRequests();
    config(['crm.drivers.ai' => 'fake']);
    ChannelAccount::factory()->create(['platform' => Platform::Facebook, 'external_id' => 'PAGE1']);
    BotSetting::current()->update(['enabled' => true, 'ai_enabled' => true, 'working_hours' => null]);
    BotRule::query()->update(['is_active' => false]);
});

function rvSay(string $text, ?string $payload = null): Conversation
{
    app(InboxIngestor::class)->ingestMessage(new InboundMessageData(
        Platform::Facebook, 'PAGE1', 'PSID-RF', 'Mona', 'rf-'.uniqid(), $text, CarbonImmutable::now(), payload: $payload,
    ));

    return Conversation::firstOrFail();
}

it('#2 records a second complaint about the same order (only overlapping return pieces are duplicates)', function () {
    $c = Conversation::factory()->create();
    SupportCase::factory()->create(['conversation_id' => $c->id, 'type' => 'complaint', 'status' => 'new', 'order_number' => '#209129', 'data' => []]);
    SupportCase::factory()->create(['conversation_id' => $c->id, 'type' => 'return', 'status' => 'new', 'order_number' => '#209129', 'data' => ['selected_items' => [['line_item_id' => 5]]]]);

    expect(CaseLock::duplicate($c, 'complaint', ['order_number' => '#209129']))->toBeNull()
        ->and(CaseLock::duplicate($c, 'return', ['order_number' => '#209129']))->toBeNull()
        ->and(CaseLock::duplicate($c, 'return', ['order_number' => '#209129', 'selected_items' => [['line_item_id' => 6]]]))->toBeNull()
        ->and(CaseLock::duplicate($c, 'return', ['order_number' => '#209129', 'selected_items' => [['line_item_id' => 5]]]))->not->toBeNull();
});

it('#9 reads a price as a price, not as an order number', function () {
    expect(EntityExtractor::orderRefsInContext('عايزة ارجع الفستان اللي ب 1250'))->toBe([])
        ->and(ReturnPrefill::fromText('عايزة ارجع الفستان اللي ب 1250')['order_refs'])->toBe([])
        ->and(EntityExtractor::orderRefsInContext('عايزة ارجع اوردر 209129 واوردر 209200'))->toBe(['209129', '209200'])
        ->and(EntityExtractor::orderRefsInContext('1047'))->toBe(['1047'])
        ->and(EntityExtractor::orderRefsInContext('el order bta3i 209129 feen'))->toBe(['209129']);
});

it('#10 lets ordinary replies through the order-fact guard', function () {
    $g = new OrderFactGuard(new ArabicNormalizer);

    expect($g->isSafe('خليني أتأكد من المقاس وأرد عليكي', [], [], []))->toBeTrue()
        ->and($g->isSafe('التوصيل بياخد 2-3 أيام', [], [], []))->toBeTrue()
        ->and($g->isSafe('الأوردر اتأكد يا فندم', [], [], []))->toBeFalse();
});

it('#20 does not invent a mixed request from «return or exchange» next to a return', function () {
    $plan = TaskPlan::fromArray(['tasks' => [
        ['kind' => 'return', 'order_refs' => ['209129'], 'items' => [], 'confidence' => 0.9],
        ['kind' => 'return_or_exchange', 'order_refs' => ['209129'], 'items' => [], 'confidence' => 0.9],
    ]], 'ارجع اوردر 209129');

    expect($plan->tasks)->toHaveCount(1)->and($plan->tasks[0]['kind'])->toBe('return');
});

it('#8 never «starts» a switched-off flow: the request goes to the reply instead', function () {
    BotFlow::where('key', 'complaint')->update(['is_active' => false]);

    $plan = TaskPlan::fromArray(['tasks' => [['kind' => 'complaint', 'order_refs' => [], 'items' => [], 'confidence' => 0.9]]], 'عايزة اشتكي');

    expect($plan->directed())->toBe([])
        ->and(array_column($plan->immediate(), 'kind'))->toBe(['complaint']);
});

it('#11 forgets old tasks and ignores a flow she opened herself', function () {
    $c = Conversation::factory()->create();
    TaskQueue::put($c, [TaskQueue::task('return', 'مرتجع', 'return_exchange'), TaskQueue::task('return', 'مرتجع 2', 'return_exchange')]);

    $this->travel(7)->hours();
    expect(TaskQueue::all($c->refresh()))->toBe([]);
});

it('#11 a flow started from the menu ends the old queue', function () {
    $c = rvSay('اهلا');
    TaskQueue::put($c, [['status' => TaskQueue::RUNNING] + TaskQueue::task('return', 'مرتجع', 'return_exchange')]);

    app(FlowEngine::class)->runPayload($c->refresh(), 'flow:complaint');

    expect(TaskQueue::all($c->refresh()))->toBe([]);
});

it('#12 «بعدين» sends its own line and the summary, not the main menu too', function () {
    $c = rvSay('اهلا');
    FlowState::clear($c); // the greeting's menu is closed: only the task offer is waiting
    TaskQueue::put($c, [
        ['status' => TaskQueue::DONE, 'case_id' => 7] + TaskQueue::task('return', 'مرتجع أوردر #1', 'return_exchange'),
        TaskQueue::task('return', 'مرتجع أوردر #2', 'return_exchange'),
    ]);
    FlowState::setConfirm($c->refresh(), TaskOrchestrator::CONFIRM);
    $before = Message::where('sender_type', SenderType::Bot->value)->count();

    rvSay('بعدين', 'no');

    $sent = Message::where('sender_type', SenderType::Bot->value)->orderBy('id')->skip($before)->take(10)->pluck('body')->all();
    expect($sent)->toHaveCount(2)
        ->and($sent[1])->toContain('ملخص طلبات حضرتك')
        ->and(FlowState::flow($c->refresh()))->toBeNull();
});

it('#5 shows staff the replacement of every exchanged piece', function () {
    $case = SupportCase::factory()->create(['type' => 'exchange', 'data' => [
        'reason' => 'size',
        'exchange_product' => ['title' => 'عباية كتان', 'url' => 'https://levoilestores.com/products/a', 'price' => 900],
        'exchange_products' => [
            ['item_title' => 'جيبة بليسيه', 'product' => ['title' => 'عباية كتان', 'url' => 'https://levoilestores.com/products/a', 'price' => 900]],
            ['item_title' => 'بلوزة كتان', 'product' => null, 'text' => 'نفس البلوزة مقاس L'],
        ],
    ]]);

    $request = collect(CaseSummary::sections($case))->firstWhere('key', 'request')['lines'];

    expect(implode("\n", $request))->toContain('«جيبة بليسيه»')->toContain('عباية كتان')->toContain('«بلوزة كتان»')->toContain('نفس البلوزة مقاس L')
        ->and(CaseSummary::exchangeNote($case->data))->toContain('بلوزة كتان');
});

it('#6 a legacy agent that asks for help hands her to a person instead of the keyword path', function () {
    config(['crm.drivers.ai' => 'claude', 'crm.anthropic.key' => 'k', 'crm.bot.agent.enabled' => true, 'crm.bot.router.mode' => 'legacy']);
    Http::fakeSequence('api.anthropic.com/*')
        ->push(['stop_reason' => 'tool_use', 'model' => 'claude-sonnet-5-5', 'usage' => [], 'content' => [['type' => 'tool_use', 'id' => 't1', 'name' => 'escalate', 'input' => ['reason' => 'dispute']]]]);

    $c = rvSay('انا اتحسب عليا الشحن مرتين في نفس الاوردر ومش هسكت');

    expect($c->refresh()->handler)->not->toBe(Handler::Bot);
});
