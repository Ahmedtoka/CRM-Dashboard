<?php

use App\Bot\Agent\StoreAgent;
use App\Bot\Brain\Feedback\FeedbackEvalCases;
use App\Bot\Learning\SuggestionApplier;
use App\Enums\UserRole;
use App\Models\BotKnowledgeEntry;
use App\Models\BotReplyFeedback;
use App\Models\BotSuggestion;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;

// «مراجعة ردود البوت» (b2): the admin turns staff feedback into a pending learning suggestion,
// applied only when approved in «التعلم».

function frFeedback(array $attrs = []): BotReplyFeedback
{
    $c = Conversation::factory()->create();
    $m = Message::factory()->create(['conversation_id' => $c->id, 'body' => 'الشحن للجيزة 45 جنيه']);

    return BotReplyFeedback::create($attrs + [
        'message_id' => $m->id, 'conversation_id' => $c->id, 'user_id' => User::factory()->create()->id,
        'reasons' => ['wrong_info'], 'correct_reply' => 'الشحن للجيزة 60 جنيه', 'layer' => 'agent', 'tier' => '1', 'status' => 'open',
    ]);
}

beforeEach(function () {
    $this->admin = User::factory()->create(['role' => UserRole::Admin]);
});

it('lists open feedback with its filters and the per-reason counts', function () {
    frFeedback();
    frFeedback(['reasons' => ['slow'], 'layer' => 'flow', 'tier' => null]);
    frFeedback(['status' => 'dismissed']);

    $this->actingAs($this->admin)->get('/bot/feedback?reason=wrong_info')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('Bot/Feedback')
            ->has('items.data', 1)
            ->where('items.data.0.reply', 'الشحن للجيزة 45 جنيه')
            ->where('counts.open', 2)
            ->where('counts.by_reason.wrong_info', 2)
            ->where('counts.by_reason.slow', 1));

    $this->actingAs($this->admin)->get('/bot/feedback?layer=flow')
        ->assertInertia(fn (Assert $page) => $page->has('items.data', 1));
});

it('is for admins and supervisors only', function () {
    $this->actingAs(User::factory()->create(['role' => UserRole::Moderator]))->get('/bot/feedback')->assertForbidden();
});

it('turns feedback into new knowledge that only goes live on approval', function () {
    $f = frFeedback();

    $this->actingAs($this->admin)->postJson("/settings/bot-feedback/{$f->id}/convert", [
        'kind' => 'knowledge', 'title' => 'شحن الجيزة', 'body' => 'الشحن للجيزة 60 جنيه', 'keywords' => ['شحن الجيزة'],
    ])->assertOk();

    $s = BotSuggestion::sole();
    expect($s->type)->toBe('new_faq')
        ->and($s->source)->toBe('feedback')
        ->and($s->status)->toBe('pending')
        ->and($s->evidence['feedback_id'])->toBe($f->id)
        ->and($f->fresh()->status)->toBe('converted')
        ->and($f->fresh()->converted_to)->toBe('knowledge')
        ->and(BotKnowledgeEntry::where('key', 'script.fb_'.$f->id)->exists())->toBeFalse();

    app(SuggestionApplier::class)->apply($s, $this->admin);

    expect(BotKnowledgeEntry::where('key', 'script.fb_'.$f->id)->value('body'))->toBe('الشحن للجيزة 60 جنيه');
});

it('turns feedback into an instruction line for the agent, appended on approval', function () {
    BotKnowledgeEntry::updateOrCreate(['key' => StoreAgent::INSTRUCTIONS_KEY], ['title' => 'تعليمات', 'body' => '- خليكي مختصرة', 'is_active' => true]);
    $f = frFeedback(['reasons' => ['should_handover']]);

    $this->actingAs($this->admin)->postJson("/settings/bot-feedback/{$f->id}/convert", ['kind' => 'instruction', 'line' => 'لو سألت عن الجملة حوّليها لموظفة'])->assertOk();

    $s = BotSuggestion::sole();
    expect($s->type)->toBe('agent_instruction')
        ->and($s->current['body'])->toBe('- خليكي مختصرة');

    app(SuggestionApplier::class)->apply($s, $this->admin);

    expect(BotKnowledgeEntry::where('key', StoreAgent::INSTRUCTIONS_KEY)->value('body'))->toBe("- خليكي مختصرة\n- لو سألت عن الجملة حوّليها لموظفة");
});

it('turns feedback into an eval scenario that bot:eval then runs', function () {
    $f = frFeedback(['reasons' => ['should_handover']]);

    $this->actingAs($this->admin)->postJson("/settings/bot-feedback/{$f->id}/convert", [
        'kind' => 'eval', 'messages' => ['عايزة اكلم المدير'], 'expect' => ['handover' => true, 'says' => [], 'never' => ['خصم']],
    ])->assertOk();

    app(SuggestionApplier::class)->apply(BotSuggestion::sole(), $this->admin);

    expect(FeedbackEvalCases::scenarios())->toBe([[
        'id' => FeedbackEvalCases::ID_OFFSET + (int) DB::table('bot_eval_cases')->value('id'),
        'category' => 'feedback', 'safety' => false,
        'messages' => ['عايزة اكلم المدير'],
        'expect' => ['handover' => true, 'never' => ['خصم']],
    ]]);
});

it('explains why a conversion cannot be made, and refuses a second one', function () {
    $f = frFeedback();

    $this->actingAs($this->admin)->postJson("/settings/bot-feedback/{$f->id}/convert", ['kind' => 'instruction', 'line' => 'لا'])
        ->assertUnprocessable()->assertJsonPath('message', 'التعليمة لازم تكون من 5 لـ 400 حرف.');
    $this->actingAs($this->admin)->postJson("/settings/bot-feedback/{$f->id}/convert", ['kind' => 'eval', 'messages' => ['اهلا'], 'expect' => []])
        ->assertUnprocessable();

    $this->actingAs($this->admin)->postJson("/settings/bot-feedback/{$f->id}/dismiss")->assertOk();
    expect($f->fresh()->status)->toBe('dismissed');

    $this->actingAs($this->admin)->postJson("/settings/bot-feedback/{$f->id}/convert", ['kind' => 'instruction', 'line' => 'قاعدة جديدة طويلة كفاية'])
        ->assertUnprocessable()->assertJsonPath('message', 'الملاحظة دي اتراجعت قبل كده.');
});

it('keeps feedback suggestions when the nightly review re-runs the same day', function () {
    $f = frFeedback();
    $this->actingAs($this->admin)->postJson("/settings/bot-feedback/{$f->id}/convert", ['kind' => 'instruction', 'line' => 'قاعدة جديدة طويلة كفاية'])->assertOk();
    $report = BotSuggestion::sole()->report;

    // What LearnCommand::saveSuggestions does first on a re-run.
    $report->suggestions()->where('status', 'pending')->where('source', '!=', BotSuggestion::SOURCE_FEEDBACK)->delete();

    expect(BotSuggestion::count())->toBe(1);
});
