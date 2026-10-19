<?php

use App\Bot\Agent\StoreAgent;
use App\Enums\UserRole;
use App\Models\BotKnowledgeEntry;
use App\Models\BotRule;
use App\Models\BotRun;
use App\Models\BotSetting;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\User;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;

// «عقل البوت» and «جرّبي البوت» (b2).

beforeEach(function () {
    Http::preventStrayRequests();
    BotSetting::current()->update(['enabled' => true, 'ai_enabled' => true, 'working_hours' => null]);
    BotRule::query()->update(['is_active' => false]);
});

it('shows admins the live router, the exact prompt the agent is sent, its sources, tools and guards', function () {
    config(['crm.bot.router.mode' => 'hybrid', 'crm.drivers.ai' => 'claude', 'crm.anthropic.key' => 'k']);
    BotKnowledgeEntry::updateOrCreate(['key' => StoreAgent::INSTRUCTIONS_KEY], ['title' => 'تعليمات', 'body' => 'متقوليش السعر قبل ما تتأكدي', 'is_active' => true]);

    $this->actingAs(User::factory()->create(['role' => UserRole::Admin]))->get('/bot/brain')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('Bot/Brain')
            ->where('full', true)
            ->where('brain.router.mode', 'hybrid')
            ->where('brain.router.models.tier1', 'claude-haiku-5-5')
            ->where('brain.router.models.tier2', 'claude-sonnet-5-5')
            ->where('brain.prompt.text', fn (string $text) => str_contains($text, 'OWNER INSTRUCTIONS') && str_contains($text, 'متقوليش السعر قبل ما تتأكدي'))
            ->where('brain.prompt.parts.1.href', '/bot/replies')
            ->where('brain.tools', fn ($tools) => collect($tools)->pluck('name')->contains('search_products'))
            ->where('brain.guards', ['price', 'promise', 'link', 'order_fact'])
            ->has('brain.sources')
            ->has('brain.layers_7d'));
});

it('gives moderators the read-only summary without the prompt or the sources', function () {
    $this->actingAs(User::factory()->create(['role' => UserRole::Moderator]))->get('/bot/brain')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('full', false)->missing('brain.prompt')->missing('brain.sources')->has('brain.tools'));
});

it('counts who answered in the last 7 days by layer', function () {
    $c = Conversation::factory()->create();
    BotRun::factory()->create(['conversation_id' => $c->id, 'engine' => 'hybrid', 'tier' => '0', 'decision' => 'reply']);
    BotRun::factory()->create(['conversation_id' => $c->id, 'engine' => 'hybrid', 'tier' => '1', 'decision' => 'reply']);
    BotRun::factory()->create(['conversation_id' => $c->id, 'engine' => 'keyword', 'decision' => 'handover']);

    $this->actingAs(User::factory()->create(['role' => UserRole::Supervisor]))->get('/bot/brain')
        ->assertInertia(fn (Assert $page) => $page->where('brain.layers_7d.script', 1)->where('brain.layers_7d.agent', 1)->where('brain.layers_7d.handover', 1));
});

it('runs a message through the real bot in a sandbox and returns the replies with the full trace, leaving nothing behind', function () {
    config(['crm.drivers.ai' => 'claude', 'crm.anthropic.key' => 'test-key', 'crm.bot.agent.enabled' => true, 'crm.bot.router.mode' => 'hybrid', 'crm.bot.hold_notice.enabled' => false]);
    $plan = ['stop_reason' => 'end_turn', 'model' => 'claude-haiku-5-5', 'usage' => ['input_tokens' => 300, 'output_tokens' => 60], 'content' => [['type' => 'text', 'text' => json_encode(['tasks' => [['kind' => 'product', 'order_refs' => [], 'items' => [], 'governorate' => null, 'place' => null, 'product_query' => 'عباية', 'question' => null, 'confidence' => 0.9]], 'sentiment' => 'neutral', 'wants_human' => false, 'continues_previous' => false, 'policy_edge' => false, 'clarify' => null])]]];
    $answer = ['stop_reason' => 'end_turn', 'model' => 'claude-haiku-5-5', 'usage' => ['input_tokens' => 400, 'output_tokens' => 40], 'content' => [['type' => 'text', 'text' => 'عندنا عبايات كتير يا فندم، تحبي تشوفي الموقع؟']]];
    Http::fake(['api.anthropic.com/*' => fn (Request $r) => Http::response(isset($r->data()['output_config']['format']) ? $plan : $answer)]);
    $before = ['messages' => Message::count(), 'conversations' => Conversation::count(), 'runs' => BotRun::count(), 'traces' => DB::table('bot_reply_traces')->count()];

    $res = $this->actingAs(User::factory()->create(['role' => UserRole::Supervisor]))
        ->postJson('/settings/bot-brain/try', ['text' => 'عندكم عبايات؟'])
        ->assertOk();

    expect($res->json('data.replies.0.text'))->toBe('عندنا عبايات كتير يا فندم، تحبي تشوفي الموقع؟')
        ->and($res->json('data.trace.layer'))->toBe('agent')
        ->and($res->json('data.trace.plan.tasks.0.kind'))->toBe('product')
        ->and(array_column($res->json('data.trace.calls'), 'phase'))->toBe(['planner', 'agent'])
        ->and($res->json('data.trace.guards'))->toBe(['pass'])
        ->and($res->json('data.handover'))->toBeFalse()
        ->and($res->json('data.error'))->toBeNull()
        ->and(['messages' => Message::count(), 'conversations' => Conversation::count(), 'runs' => BotRun::count(), 'traces' => DB::table('bot_reply_traces')->count()])->toBe($before)
        ->and((float) Cache::get('bot:console-spend:'.now('Africa/Cairo')->toDateString()))->toBeGreaterThan(0);
});

it('runs the scripted paths when no model is available, and refuses moderators', function () {
    config(['crm.drivers.ai' => 'fake', 'crm.anthropic.key' => null]);

    $res = $this->actingAs(User::factory()->create(['role' => UserRole::Admin]))
        ->postJson('/settings/bot-brain/try', ['text' => 'السلام عليكم', 'history' => [['role' => 'customer', 'text' => 'اهلا'], ['role' => 'bot', 'text' => 'اهلا بيكي']]])
        ->assertOk();

    expect($res->json('data.agent_ready'))->toBeFalse()
        ->and($res->json('data.error'))->toBeNull();

    $this->actingAs(User::factory()->create(['role' => UserRole::Moderator]))->postJson('/settings/bot-brain/try', ['text' => 'x'])->assertForbidden();
});

it('renders the guide from the repo markdown for admins and supervisors', function () {
    $this->actingAs(User::factory()->create(['role' => UserRole::Supervisor]))->get('/bot/guide')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('Bot/Guide')->where('html', fn (string $html) => str_contains($html, '<table>') && str_contains($html, 'CRM_BOT_ROUTER')));
    $this->actingAs(User::factory()->create(['role' => UserRole::Moderator]))->get('/bot/guide')->assertForbidden();
});
