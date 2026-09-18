<?php

use App\Bot\Flows\FlowDrafts;
use App\Bot\Learning\ConversationReviewer;
use App\Bot\Learning\FakeConversationReviewer;
use App\Bot\Learning\LearningAnalyst;
use App\Bot\Learning\SuggestionValidator;
use App\Enums\MessageDirection;
use App\Enums\SenderType;
use App\Enums\UserRole;
use App\Models\BotIntent;
use App\Models\BotKnowledgeEntry;
use App\Models\BotLearningNote;
use App\Models\BotLearningReport;
use App\Models\BotSuggestion;
use App\Models\ChannelAccount;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\User;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    Http::preventStrayRequests();
});

/**
 * A real conversation the "run now" button will find, since that button
 * reviews today; its inline review writes one note.
 */
function todayConversation(): Conversation
{
    app()->instance(ConversationReviewer::class, new FakeConversationReviewer([
        ['kind' => 'unanswered', 'summary' => 'العميلة سألت عن التأخير', 'quote' => 'الأوردر متأخر'],
    ]));

    $conversation = Conversation::factory()->create([
        'channel_account_id' => ChannelAccount::factory()->create(['driver' => 'live'])->id,
    ]);

    foreach (['الأوردر متأخر', 'حد يرد'] as $body) {
        Message::factory()->create([
            'conversation_id' => $conversation->id,
            'direction' => MessageDirection::In,
            'sender_type' => SenderType::Customer,
            'body' => $body,
        ]);
    }

    return $conversation;
}

function stubPageAnalyst(array $suggestions): void
{
    app()->instance(LearningAnalyst::class, new class($suggestions) implements LearningAnalyst
    {
        public function __construct(private array $suggestions) {}

        public function analyze(array $notes, array $catalog): array
        {
            return [
                'summary' => 'ملخص',
                'stats' => [],
                'suggestions' => $this->suggestions,
                'model' => 'fake-model',
                'input_tokens' => 1,
                'output_tokens' => 2,
            ];
        }
    });
}

function pageReport(string $date = '2026-09-16'): BotLearningReport
{
    return BotLearningReport::create([
        'report_date' => $date,
        'summary' => 'ملخص اليوم',
        'stats' => ['conversations' => 3, 'handovers' => 1, 'cases' => 0, 'top_intents' => ['price']],
        'model' => 'claude-haiku-4-5-20251001',
        'input_tokens' => 100,
        'output_tokens' => 50,
    ]);
}

function pageSuggestion(BotLearningReport $report, string $type = 'script_text', ?string $target = 'return_policy', array $proposed = ['body' => 'نص جديد']): BotSuggestion
{
    return BotSuggestion::create([
        'report_id' => $report->id,
        'type' => $type,
        'target' => $target,
        'current' => ['body' => 'نص قديم'],
        'proposed' => $proposed,
        'reason' => 'سبب',
        'evidence' => ['conversation_ids' => [7], 'quote' => 'اقتباس'],
        'status' => 'pending',
    ]);
}

it('shows the learning page to a supervisor and blocks a moderator', function () {
    $sup = User::factory()->create(['role' => UserRole::Supervisor]);
    $mod = User::factory()->create(['role' => UserRole::Moderator]);

    BotKnowledgeEntry::updateOrCreate(['key' => 'script.return_policy'], ['title' => 'س', 'body' => 'نص قديم', 'is_active' => true, 'is_template' => false, 'sort' => 10]);
    pageSuggestion(pageReport());

    $this->actingAs($mod)->get('/settings/bot-learning')->assertForbidden();

    $this->actingAs($sup)->get('/settings/bot-learning')->assertOk()
        ->assertInertia(fn ($p) => $p->component('settings/BotLearning')
            ->has('reports', 1)
            ->has('report.suggestions', 1)
            ->where('report.suggestions.0.type', 'script_text')
            // The "current" side is read live from the database at render.
            ->where('report.suggestions.0.current.body', 'نص قديم'));
});

it('applies a suggestion when a supervisor approves it', function () {
    $sup = User::factory()->create(['role' => UserRole::Supervisor]);
    $mod = User::factory()->create(['role' => UserRole::Moderator]);

    $entry = BotKnowledgeEntry::updateOrCreate(['key' => 'script.return_policy'], ['title' => 'س', 'body' => 'نص قديم', 'is_active' => true, 'is_template' => false, 'sort' => 10]);
    $s = pageSuggestion(pageReport());

    $this->actingAs($mod)->postJson("/settings/bot-suggestions/{$s->id}/approve")->assertForbidden();

    $this->actingAs($sup)->postJson("/settings/bot-suggestions/{$s->id}/approve")->assertOk();

    expect($entry->fresh()->body)->toBe('نص جديد')
        ->and($s->fresh()->status)->toBe('approved')
        ->and($s->fresh()->decided_by_id)->toBe($sup->id);
});

it('answers 422 and keeps the suggestion pending when applying fails', function () {
    $sup = User::factory()->create(['role' => UserRole::Supervisor]);

    $s = pageSuggestion(pageReport(), 'script_text', 'ghost');

    $this->actingAs($sup)->postJson("/settings/bot-suggestions/{$s->id}/approve")->assertStatus(422);

    expect($s->fresh()->status)->toBe('pending')->and($s->fresh()->error)->not->toBeNull();
});

it('marks a suggestion rejected', function () {
    $sup = User::factory()->create(['role' => UserRole::Supervisor]);
    $mod = User::factory()->create(['role' => UserRole::Moderator]);

    BotIntent::updateOrCreate(['key' => 'price'], [
        'group' => 'general', 'label_ar' => 'سعر', 'label_en' => 'Price',
        'route' => 'answer', 'priority' => 'low', 'queue' => null,
        'script_keys' => [], 'required_details' => [], 'keywords' => ['سعر'], 'is_active' => true, 'sort' => 10,
    ]);

    $s = pageSuggestion(pageReport(), 'intent_keywords', 'price', ['add' => ['بكام ده']]);

    $this->actingAs($mod)->postJson("/settings/bot-suggestions/{$s->id}/reject")->assertForbidden();
    $this->actingAs($sup)->postJson("/settings/bot-suggestions/{$s->id}/reject")->assertOk();

    $fresh = $s->fresh();

    expect($fresh->status)->toBe('rejected')
        ->and($fresh->decided_by_id)->toBe($sup->id)
        ->and($fresh->decided_at)->not->toBeNull()
        ->and($fresh->applied_at)->toBeNull()
        // Rejecting never touches the intent.
        ->and(BotIntent::where('key', 'price')->first()->keywords)->toBe(['سعر']);
});

it('runs the learning for today from the page', function () {
    $sup = User::factory()->create(['role' => UserRole::Supervisor]);
    $mod = User::factory()->create(['role' => UserRole::Moderator]);

    $this->actingAs($mod)->postJson('/settings/bot-learning/run')->assertForbidden();

    // No conversations today: the command skips and says so, instead of a success toast.
    $this->actingAs($sup)->postJson('/settings/bot-learning/run')
        ->assertOk()
        ->assertJsonPath('data.status', 'skipped');

    expect(BotLearningReport::query()->count())->toBe(0);
});

it('answers created with the suggestion count when the run wrote a report', function () {
    $sup = User::factory()->create(['role' => UserRole::Supervisor]);
    $conversation = todayConversation();

    BotKnowledgeEntry::updateOrCreate(['key' => 'script.return_policy'], ['title' => 'س', 'body' => 'نص قديم', 'is_active' => true, 'is_template' => false, 'sort' => 10]);

    stubPageAnalyst([
        ['type' => 'script_text', 'target' => 'return_policy', 'proposed' => ['body' => 'نص جديد'], 'reason' => 'r', 'evidence' => ['conversation_ids' => [$conversation->id]]],
    ]);

    $this->actingAs($sup)->postJson('/settings/bot-learning/run')
        ->assertOk()
        ->assertJsonPath('data.status', 'created')
        ->assertJsonPath('data.suggestions', 1);

    expect(BotLearningReport::query()->count())->toBe(1);
});

it('answers 422 when the run fails instead of toasting success', function () {
    $sup = User::factory()->create(['role' => UserRole::Supervisor]);
    todayConversation();

    app()->instance(LearningAnalyst::class, new class implements LearningAnalyst
    {
        public function analyze(array $notes, array $catalog): array
        {
            throw new RuntimeException('claude down');
        }
    });

    $this->actingAs($sup)->postJson('/settings/bot-learning/run')->assertStatus(422);

    expect(BotLearningReport::query()->count())->toBe(0);
});

it('answers 422 instead of 500 when applying blows up unexpectedly', function () {
    $sup = User::factory()->create(['role' => UserRole::Supervisor]);

    // A permissive validator lets the suggestion through to a script row that
    // is not there — the real race, and a ModelNotFoundException, not a
    // DomainException. The page must still get a clean 422.
    app()->instance(SuggestionValidator::class, new class(app(FlowDrafts::class)) extends SuggestionValidator
    {
        public function validate(array $suggestion): ?string
        {
            return null;
        }
    });

    $s = pageSuggestion(pageReport(), 'script_text', 'vanished');

    $this->actingAs($sup)->postJson("/settings/bot-suggestions/{$s->id}/approve")->assertStatus(422);

    expect($s->fresh()->status)->toBe('pending')->and($s->fresh()->error)->not->toBeNull();
});

it('lists the latest 14 reports and selects one by id', function () {
    $sup = User::factory()->create(['role' => UserRole::Supervisor]);

    for ($i = 1; $i <= 16; $i++) {
        pageReport(sprintf('2026-08-%02d', $i));
    }

    $oldest = BotLearningReport::query()->orderBy('report_date')->first();

    $this->actingAs($sup)->get('/settings/bot-learning')->assertOk()
        ->assertInertia(fn ($p) => $p->has('reports', 14)->where('report.report_date', '2026-08-16'));

    $this->actingAs($sup)->get("/settings/bot-learning?report={$oldest->id}")->assertOk()
        ->assertInertia(fn ($p) => $p->where('report.report_date', '2026-08-01'));
});

it('shows the review chips and the notes of today, real conversations only', function () {
    $sup = User::factory()->create(['role' => UserRole::Supervisor]);
    $mod = User::factory()->create(['role' => UserRole::Moderator]);

    $live = Conversation::factory()->create(['channel_account_id' => ChannelAccount::factory()->create(['driver' => 'live', 'name' => 'Pro Max'])->id]);
    $demo = Conversation::factory()->create(['channel_account_id' => ChannelAccount::factory()->create(['driver' => 'fake'])->id]);

    $row = fn (Conversation $c, array $notes, float $cost) => BotLearningNote::create([
        'conversation_id' => $c->id, 'channel_account_id' => $c->channel_account_id, 'last_message_id' => 1,
        'notes' => $notes, 'model' => 'claude-haiku-4-5-20251001', 'input_tokens' => 1000, 'output_tokens' => 200, 'cost_usd' => $cost,
    ]);

    $row($live, [
        ['kind' => 'agent_knowledge', 'summary' => 'الموظف رد بميعاد الشحن', 'quote' => 'هيوصل امتى', 'agent_answer' => 'خلال 3 أيام'],
        ['kind' => 'unanswered', 'summary' => 'سؤال عن الخامة', 'quote' => 'الخامة إيه؟'],
    ], 0.002);
    $row($live, [], 0.001);
    $row($demo, [['kind' => 'unanswered', 'summary' => 'تجريبي', 'quote' => 'x']], 0.5);

    // Yesterday's review is not today's.
    $old = $row($live, [['kind' => 'unanswered', 'summary' => 'امبارح', 'quote' => 'x']], 0.3);
    $old->forceFill(['created_at' => now()->subDays(2)])->save();

    $this->actingAs($mod)->get('/settings/bot-learning')->assertForbidden();

    $this->actingAs($sup)->get('/settings/bot-learning')->assertOk()
        ->assertInertia(fn ($p) => $p->component('settings/BotLearning')
            ->where('today.reviewed', 2)
            ->where('today.notes', 2)
            ->where('today.cost_usd', 0.003)
            ->where('today.cap', 200)
            ->has('todayNotes', 2)
            ->where('todayNotes.0.kind', 'agent_knowledge')
            ->where('todayNotes.0.conversation_id', $live->id)
            ->where('todayNotes.0.channel_account', 'Pro Max')
            ->where('todayNotes.0.agent_answer', 'خلال 3 أيام')
            ->where('todayNotes.1.kind', 'unanswered')
            ->where('todayNotes.1.agent_answer', null));
});

it('passes the demo flag and the sources of a report to the page', function () {
    $sup = User::factory()->create(['role' => UserRole::Supervisor]);

    BotLearningReport::create([
        'report_date' => '2026-09-16', 'summary' => 'ملخص', 'model' => 'm', 'input_tokens' => 1, 'output_tokens' => 1,
        'stats' => ['demo' => true, 'sources' => [['channel_account_id' => 1, 'name' => 'Pro Max', 'count' => 3]], 'cost_usd' => 0.01],
    ]);

    $this->actingAs($sup)->get('/settings/bot-learning')->assertOk()
        ->assertInertia(fn ($p) => $p->where('report.stats.demo', true)
            ->where('report.stats.sources.0.name', 'Pro Max')
            ->where('reports.0.stats.demo', true));
});

it('limits the inline backfill of the run button', function () {
    $sup = User::factory()->create(['role' => UserRole::Supervisor]);
    $reviewer = new FakeConversationReviewer([['kind' => 'unanswered', 'summary' => 'س', 'quote' => 'ق']]);
    app()->instance(ConversationReviewer::class, $reviewer);
    stubPageAnalyst([]);

    $account = ChannelAccount::factory()->create(['driver' => 'live']);

    for ($n = 0; $n < 12; $n++) {
        $conversation = Conversation::factory()->create(['channel_account_id' => $account->id]);

        foreach (['سلام', 'السعر كام'] as $body) {
            Message::factory()->create(['conversation_id' => $conversation->id, 'direction' => MessageDirection::In, 'sender_type' => SenderType::Customer, 'body' => $body]);
        }
    }

    $this->actingAs($sup)->postJson('/settings/bot-learning/run')->assertOk()->assertJsonPath('data.conversations', 10);

    expect($reviewer->reviewed)->toHaveCount(10);
});

it('names suggestion targets and top intents in words instead of raw keys', function () {
    $sup = User::factory()->create(['role' => UserRole::Supervisor]);
    BotKnowledgeEntry::updateOrCreate(['key' => 'script.return_policy'], ['title' => 'سياسة المرتجع', 'body' => 'نص قديم', 'is_active' => true, 'is_template' => false, 'sort' => 10]);
    $report = pageReport();
    pageSuggestion($report);
    pageSuggestion($report, 'intent_keywords', 'price', ['add' => ['بكام']]);
    pageSuggestion($report, 'new_faq', 'nothing_yet', ['key' => 'nothing_yet', 'body' => 'x']);

    $priceLabel = BotIntent::where('key', 'price')->value('label_ar');

    $this->actingAs($sup)->get('/settings/bot-learning')->assertOk()
        ->assertInertia(fn ($p) => $p
            ->where('report.suggestions.0.target_label', 'سياسة المرتجع')
            ->where('report.suggestions.1.target_label', $priceLabel)
            ->where('report.suggestions.2.target_label', null)
            ->where('intentLabels.price', $priceLabel));
});
