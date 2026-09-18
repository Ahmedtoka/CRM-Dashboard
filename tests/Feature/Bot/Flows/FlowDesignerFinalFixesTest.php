<?php

use App\Bot\Flow\ReplyScheduler;
use App\Bot\Flows\FakeFlowAnswerInterpreter;
use App\Bot\Flows\FlowAnswerInterpreter;
use App\Bot\Flows\FlowDefinition;
use App\Bot\Flows\FlowEngine;
use App\Bot\Flows\FlowState;
use App\Bot\Flows\FlowStepCatalog;
use App\Bot\Flows\Sandbox\FlowSandbox;
use App\Channels\Data\InboundMessageData;
use App\Enums\Platform;
use App\Enums\SenderType;
use App\Enums\UserRole;
use App\Inbox\InboxIngestor;
use App\Models\BotFlow;
use App\Models\BotSetting;
use App\Models\ChannelAccount;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    Event::fake();
    Queue::fake();
    Http::preventStrayRequests();
    config(['crm.drivers.ai' => 'fake']);
    ChannelAccount::factory()->create(['platform' => Platform::Facebook, 'external_id' => 'PAGE1']);
    BotSetting::current()->update(['enabled' => false, 'working_hours' => null]);
    app()->bind(FlowAnswerInterpreter::class, FakeFlowAnswerInterpreter::class);
    $this->sup = User::factory()->create(['role' => UserRole::Supervisor]);
});

function ffixSay(string $text, ?string $payload = null): Conversation
{
    app(InboxIngestor::class)->ingestMessage(new InboundMessageData(
        Platform::Facebook, 'PAGE1', 'PSID-FF', 'Mona', 'ffix-'.uniqid('', true), $text, CarbonImmutable::now(), payload: $payload,
    ));

    return Conversation::firstOrFail();
}

function ffixTurn(string $text, ?string $payload = null): Conversation
{
    $c = ffixSay($text, $payload);
    app(FlowEngine::class)->handle($c, app(ReplyScheduler::class)->burst($c));

    return $c->fresh();
}

function ffixLastBot(): Message
{
    return Message::where('sender_type', SenderType::Bot->value)->latest('id')->firstOrFail();
}

function ffixChoiceFlow(): BotFlow
{
    return BotFlow::create(['key' => 'ff_choice', 'title_ar' => 'اختبار الاختيار', 'is_active' => true, 'definition' => ['start' => 'pick', 'steps' => [
        'pick' => ['type' => 'choice', 'field' => 'color', 'text' => 'تحبي أنهي لون؟', 'options' => [
            ['value' => 'red', 'title' => 'أحمر', 'synonyms' => [], 'next' => 'x'],
            ['value' => 'blue', 'title' => 'أزرق', 'synonyms' => []],
        ], 'next' => 'y'],
        'x' => ['type' => 'text', 'field' => 'x', 'text' => 'خطوة اكس', 'next' => 'end'],
        'y' => ['type' => 'text', 'field' => 'y', 'text' => 'خطوة واي', 'next' => 'end'],
    ]]]);
}

it('follows a choice option next when tapped, and the step next for an option without one (R-F8)', function () {
    ffixChoiceFlow();
    $c = ffixSay('اهلا');
    app(FlowEngine::class)->start($c, 'ff_choice');

    $c = ffixTurn('أحمر', 'step:ff_choice:pick:red');
    expect(FlowState::flow($c)['step'])->toBe('x')
        ->and(ffixLastBot()->body)->toBe('خطوة اكس');

    FlowState::clear($c);
    app(FlowEngine::class)->start($c, 'ff_choice');
    $c = ffixTurn('أزرق');
    expect(FlowState::flow($c)['step'])->toBe('y')
        ->and(FlowState::flow($c)['data']['color'])->toBe('blue');
});

it('follows a choice option next on a typed title in the live engine and in the sandbox', function () {
    $flow = ffixChoiceFlow();
    $c = ffixSay('اهلا');
    app(FlowEngine::class)->start($c, 'ff_choice');
    expect(FlowState::flow(ffixTurn('أحمر'))['step'])->toBe('x');

    $sandbox = app(FlowSandbox::class);
    $r = $sandbox->run($flow, 'published', null, [], $this->sup);
    $red = $sandbox->run($flow, 'published', $r['state'], ['payload' => 'step:ff_choice:pick:red'], $this->sup);
    $blue = $sandbox->run($flow, 'published', $r['state'], ['text' => 'أزرق'], $this->sup);

    expect($red['current']['step_id'])->toBe('x')
        ->and($blue['current']['step_id'])->toBe('y');
});

it('hides main menu buttons that open an inactive flow, live and in the sandbox (R-F9)', function () {
    BotFlow::where('key', 'return_exchange')->update(['is_active' => false]);

    $c = ffixSay('اهلا');
    app(FlowEngine::class)->start($c, 'main_menu');
    $payloads = collect(ffixLastBot()->buttons)->pluck('payload');
    expect($payloads)->not->toContain('flow:return_exchange')
        ->and($payloads)->toContain('flow:complaint')
        ->and(app(FlowEngine::class)->runPayload($c->fresh(), 'flow:return_exchange'))->toBeFalse();

    $r = app(FlowSandbox::class)->run(BotFlow::where('key', 'main_menu')->firstOrFail(), 'published', null, [], $this->sup);
    expect(collect(end($r['messages'])['buttons'])->pluck('payload'))->not->toContain('flow:return_exchange')
        ->toContain('flow:complaint');
});

it('warns on draft save and show when a menu button opens an inactive flow', function () {
    $menu = BotFlow::where('key', 'main_menu')->firstOrFail();
    BotFlow::where('key', 'return_exchange')->update(['is_active' => false]);
    $stepKey = FlowDefinition::menuStepKey($menu->definition);
    $title = collect($menu->definition['steps'][$stepKey]['options'])->firstWhere('action', 'flow:return_exchange')['title'];
    $expected = "الزرار «{$title}» بيوديكي لفلو مش شغال: return_exchange";

    $this->actingAs($this->sup)->putJson("/settings/bot-flows/{$menu->id}/draft", ['definition' => $menu->definition])
        ->assertOk()
        ->assertJsonPath('data.errors', [])
        ->assertJsonPath('data.warnings', fn ($w) => in_array($expected, $w, true));

    $this->actingAs($this->sup)->getJson("/settings/bot-flows/{$menu->id}")
        ->assertOk()
        ->assertJsonPath('data.warnings', fn ($w) => in_array($expected, $w, true));

    BotFlow::where('key', 'return_exchange')->update(['is_active' => true]);
    expect(FlowDefinition::referenceWarnings($menu->definition))->toBe([]);
});

it('rejects the reserved step id end', function () {
    $errors = FlowDefinition::validate(['start' => 'a', 'steps' => [
        'a' => ['type' => 'text', 'field' => 'a', 'next' => 'end'],
        'end' => ['type' => 'end'],
    ]]);

    expect($errors)->toContain("step id 'end' is reserved");
});

it('requires non-empty, unique choice option values', function () {
    $def = fn (array $options) => ['start' => 'a', 'steps' => [
        'a' => ['type' => 'choice', 'field' => 'a', 'options' => $options, 'next' => 'end'],
    ]];

    expect(FlowDefinition::validate($def([['title' => 'x', 'value' => null], ['title' => 'y', 'value' => '']])))
        ->toContain("step 'a' option #0 requires a 'value'")
        ->toContain("step 'a' option #1 requires a 'value'")
        ->and(FlowDefinition::validate($def([['title' => 'x', 'value' => 'v'], ['title' => 'y', 'value' => 'v']])))
        ->toBe(["step 'a' option #1 repeats the value 'v'"])
        ->and(FlowDefinition::validate($def([['title' => 'x', 'value' => 'v'], ['title' => 'y', 'value' => 'w']])))
        ->toBe([]);
});

it('reports an empty option value sent through the draft save endpoint (null after middleware)', function () {
    $flow = BotFlow::where('key', 'complaint')->firstOrFail();
    $definition = $flow->definition;
    $definition['steps']['type']['options'][0]['value'] = '';

    $this->actingAs($this->sup)->putJson("/settings/bot-flows/{$flow->id}/draft", ['definition' => $definition])
        ->assertOk()
        ->assertJsonPath('data.errors', fn ($e) => in_array("step 'type' option #0 requires a 'value'", $e, true));
});

it('does not offer a text field on handover steps', function () {
    expect(FlowStepCatalog::all()['handover']['fields'])->toBe([]);
});

it('keeps every seeded flow valid', function () {
    BotFlow::all()->each(fn (BotFlow $f) => expect(FlowDefinition::validate($f->definition))->toBe([]));
});
