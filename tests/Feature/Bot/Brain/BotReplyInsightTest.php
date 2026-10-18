<?php

use App\Bot\Brain\Trace\TraceView;
use App\Enums\SenderType;
use App\Enums\UserRole;
use App\Models\BotReplyFeedback;
use App\Models\BotRun;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\User;
use Illuminate\Support\Facades\DB;

// The bot bubble's «ليه البوت رد كده؟» and «رد البوت ده غلط» (b2).

function biTrace(Conversation $c, array $runAttrs, array $messageIds, array $trace = [], ?string $at = null): int
{
    $run = BotRun::factory()->create(['conversation_id' => $c->id] + $runAttrs);
    $id = (int) DB::table('bot_reply_traces')->where('bot_run_id', $run->id)->value('id');

    DB::table('bot_reply_traces')->where('id', $id)->update([
        'message_ids' => json_encode($messageIds),
        'trace' => json_encode($trace + ['v' => 1, 'router' => 'hybrid', 'plan' => null, 'calls' => [], 'tools' => [], 'guards' => [], 'handover' => null]),
        'created_at' => $at ?? now(),
    ]);

    return $id;
}

it('shows the turn that sent a bot message, its layer and its tools', function () {
    $c = Conversation::factory()->create();
    $m = Message::factory()->create(['conversation_id' => $c->id]);
    biTrace($c, ['engine' => 'hybrid', 'tier' => '1', 'decision' => 'reply', 'intent' => 'shipping_fee|tools:get_shipping_fee', 'cost_usd' => 0.0012], [$m->id], [
        'tools' => [['name' => 'get_shipping_fee', 'input' => '{"governorate":"الجيزة"}', 'output' => '60 جنيه']],
    ]);

    $this->actingAs(User::factory()->create(['role' => UserRole::Supervisor]))
        ->getJson("/inbox/messages/{$m->id}/bot-trace")
        ->assertOk()
        ->assertJsonPath('data.layer', 'agent')
        ->assertJsonPath('data.tier', '1')
        ->assertJsonPath('data.kinds', ['shipping_fee'])
        ->assertJsonPath('data.tools_used', ['get_shipping_fee'])
        ->assertJsonPath('data.tools.0.output', '60 جنيه')
        ->assertJsonPath('feedback', null);
});

it('falls back to the turn written just before a message it does not list (a delayed send)', function () {
    $c = Conversation::factory()->create();
    $id = biTrace($c, ['engine' => 'flow', 'decision' => 'reply'], [], [], now()->subSeconds(30)->toDateTimeString());
    $m = Message::factory()->create(['conversation_id' => $c->id]);

    expect(app(TraceView::class)->traceIdFor($m))->toBe($id);
});

it('has no trace for an old message from before the trace existed', function () {
    $c = Conversation::factory()->create();
    biTrace($c, ['engine' => 'flow', 'decision' => 'reply'], [], [], now()->subHour()->toDateTimeString());
    $m = Message::factory()->create(['conversation_id' => $c->id]);

    $this->actingAs(User::factory()->create(['role' => UserRole::Supervisor]))
        ->getJson("/inbox/messages/{$m->id}/bot-trace")
        ->assertOk()
        ->assertJsonPath('data', null);
});

it('names each layer the way staff read it', function (array $run, string $layer) {
    expect(TraceView::layer(new BotRun($run)))->toBe($layer);
})->with([
    'tier 0 script' => [['engine' => 'hybrid', 'tier' => '0', 'decision' => 'reply'], 'script'],
    'hybrid flow' => [['engine' => 'hybrid', 'tier' => 'flow', 'decision' => 'flow_started'], 'flow'],
    'haiku agent' => [['engine' => 'hybrid', 'tier' => '1', 'decision' => 'reply'], 'agent'],
    'safe fallback' => [['engine' => 'hybrid', 'tier' => '1', 'decision' => 'safe_fallback'], 'fallback'],
    'handover' => [['engine' => 'hybrid', 'tier' => 'handover', 'decision' => 'handover'], 'handover'],
    'owner rule' => [['engine' => 'rule', 'decision' => 'reply'], 'rule'],
    'guided flow' => [['engine' => 'flow_engine', 'decision' => 'reply'], 'flow'],
    'legacy agent' => [['engine' => 'agent', 'tier' => 'legacy', 'decision' => 'reply'], 'agent'],
    'keyword' => [['engine' => 'keyword', 'decision' => 'handover'], 'handover'],
]);

it('lets any staff member who can open the chat flag a bot reply, and updates it on a second send', function () {
    $mod = User::factory()->create(['role' => UserRole::Supervisor]);
    $c = Conversation::factory()->create();
    $m = Message::factory()->create(['conversation_id' => $c->id]);
    biTrace($c, ['engine' => 'hybrid', 'tier' => '2', 'decision' => 'reply'], [$m->id]);

    $this->actingAs($mod)->postJson("/inbox/messages/{$m->id}/bot-feedback", ['reasons' => ['wrong_info'], 'correct_reply' => 'الشحن للجيزة 60 جنيه'])
        ->assertCreated()->assertJsonPath('data.reasons', ['wrong_info']);
    $this->actingAs($mod)->postJson("/inbox/messages/{$m->id}/bot-feedback", ['reasons' => ['wrong_info', 'tone']])
        ->assertOk();

    $f = BotReplyFeedback::sole();
    expect($f->reasons)->toBe(['wrong_info', 'tone'])
        ->and($f->correct_reply)->toBeNull()
        ->and($f->tier)->toBe('2')
        ->and($f->layer)->toBe('agent')
        ->and($f->bot_run_id)->not->toBeNull()
        ->and($f->status)->toBe('open');
});

it('refuses unknown reasons, non-bot messages and people who cannot open the chat', function () {
    $c = Conversation::factory()->create();
    $bot = Message::factory()->create(['conversation_id' => $c->id]);
    $customer = Message::factory()->create(['conversation_id' => $c->id, 'sender_type' => SenderType::Customer]);
    $sup = User::factory()->create(['role' => UserRole::Supervisor]);

    $this->actingAs($sup)->postJson("/inbox/messages/{$bot->id}/bot-feedback", ['reasons' => ['nope']])->assertUnprocessable();
    $this->actingAs($sup)->postJson("/inbox/messages/{$bot->id}/bot-feedback", ['reasons' => []])->assertUnprocessable();
    $this->actingAs($sup)->postJson("/inbox/messages/{$customer->id}/bot-feedback", ['reasons' => ['tone']])->assertNotFound();
    $this->actingAs(User::factory()->create(['role' => UserRole::Moderator]))
        ->postJson("/inbox/messages/{$bot->id}/bot-feedback", ['reasons' => ['tone']])->assertForbidden();
});
