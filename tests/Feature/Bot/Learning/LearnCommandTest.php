<?php

use App\Bot\BotServiceProvider;
use App\Bot\Learning\ClaudeLearningAnalyst;
use App\Bot\Learning\ConversationReviewer;
use App\Bot\Learning\FakeConversationReviewer;
use App\Bot\Learning\LearningAnalyst;
use App\Bot\Learning\LearningOutcome;
use App\Bot\Learning\TranscriptBuilder;
use App\Enums\MessageDirection;
use App\Enums\SenderType;
use App\Models\BotFlow;
use App\Models\BotIntent;
use App\Models\BotKnowledgeEntry;
use App\Models\BotLearningNote;
use App\Models\BotLearningReport;
use App\Models\BotRun;
use App\Models\BotSuggestion;
use App\Models\ChannelAccount;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\SupportCase;
use Carbon\CarbonImmutable;
use Illuminate\Console\Application as ConsoleApplication;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\Http;

/** An analyst that returns exactly what the test hands it, without any HTTP. */
function stubAnalyst(array $suggestions, string $summary = 'ملخص اليوم'): void
{
    app()->instance(LearningAnalyst::class, new class($suggestions, $summary) implements LearningAnalyst
    {
        public array $notes = [];

        public array $catalog = [];

        public function __construct(private array $suggestions, private string $summary) {}

        public function analyze(array $notes, array $catalog): array
        {
            $this->notes = $notes;
            $this->catalog = $catalog;

            return [
                'summary' => $this->summary,
                'stats' => ['conversations' => 99, 'handovers' => 0, 'cases' => 0, 'top_intents' => ['price']],
                'suggestions' => $this->suggestions,
                'model' => 'fake-model',
                'input_tokens' => 11,
                'output_tokens' => 22,
            ];
        }
    });
}

/** A conversation on a real (live-driver) page — the only kind learning reads. */
function liveConversation(array $attributes = []): Conversation
{
    return Conversation::factory()->create($attributes + [
        'channel_account_id' => ChannelAccount::factory()->create(['driver' => 'live', 'name' => 'Pro Max'])->id,
    ]);
}

/** The per-conversation reviewer the backfill uses, returning `$notes` for every conversation. */
function fakeReviewer(array $notes = [['kind' => 'agent_knowledge', 'summary' => 'العميلة سألت عن التأخير والموظف رد', 'quote' => 'الأوردر متأخر', 'agent_answer' => 'هنشحنه بكرة']]): FakeConversationReviewer
{
    $reviewer = new FakeConversationReviewer($notes, 'claude-haiku-4-5-20251001', 1000, 200);
    app()->instance(ConversationReviewer::class, $reviewer);

    return $reviewer;
}

function learningFixtures(string $cairoDay = '2026-09-16'): Conversation
{
    fakeReviewer();

    // The Le Voile migrations already seed scripts, intents and flows, so the
    // fixtures below overwrite the two rows the assertions read.
    BotKnowledgeEntry::updateOrCreate(['key' => 'script.return_policy'], ['title' => 'سياسة الاسترجاع', 'body' => 'النص القديم', 'is_active' => true, 'is_template' => false, 'sort' => 10]);

    BotIntent::updateOrCreate(['key' => 'price'], [
        'group' => 'general', 'label_ar' => 'سعر', 'label_en' => 'Price',
        'route' => 'answer', 'priority' => 'low', 'queue' => null,
        'script_keys' => [], 'required_details' => [], 'keywords' => ['سعر'], 'is_active' => true, 'sort' => 10,
    ]);

    BotFlow::create([
        'key' => 'learning_demo', 'title_ar' => 'تجربة', 'is_active' => true,
        'definition' => [
            'start' => 'kind',
            'steps' => [
                'kind' => [
                    'type' => 'choice', 'field' => 'kind', 'text' => 'شكوى من إيه؟', 'next' => 'end',
                    'options' => [
                        ['title' => 'فرع', 'value' => 'branch'],
                        ['title' => 'شحن', 'value' => 'shipping'],
                    ],
                ],
            ],
        ],
    ]);

    $conversation = liveConversation();

    $at = CarbonImmutable::parse($cairoDay.' 12:00:00', 'Africa/Cairo')->setTimezone('UTC');

    Message::factory()->create(['conversation_id' => $conversation->id, 'direction' => MessageDirection::In, 'sender_type' => SenderType::Customer, 'body' => 'الأوردر متأخر', 'created_at' => $at]);
    Message::factory()->create(['conversation_id' => $conversation->id, 'direction' => MessageDirection::Out, 'sender_type' => SenderType::Bot, 'body' => 'تحت أمرك', 'created_at' => $at->addMinute()]);
    Message::factory()->create(['conversation_id' => $conversation->id, 'direction' => MessageDirection::Out, 'sender_type' => SenderType::User, 'body' => 'هنشحنه بكرة', 'created_at' => $at->addMinutes(2)]);
    Message::factory()->create(['conversation_id' => $conversation->id, 'direction' => MessageDirection::In, 'sender_type' => SenderType::Customer, 'body' => 'تمام شكرا', 'created_at' => $at->addMinutes(3)]);

    BotRun::factory()->create(['conversation_id' => $conversation->id, 'intent' => 'delivery_time', 'created_at' => $at->addMinute()]);

    return $conversation;
}

it('saves a report and only the valid suggestions', function () {
    $conversation = learningFixtures();

    stubAnalyst([
        ['type' => 'script_text', 'target' => 'return_policy', 'proposed' => ['body' => 'النص الجديد'], 'reason' => 'أوضح', 'evidence' => ['conversation_ids' => [$conversation->id], 'quote' => 'الأوردر متأخر']],
        ['type' => 'new_faq', 'target' => null, 'proposed' => ['key' => 'gift_wrap', 'title' => 'تغليف هدايا', 'body' => 'عندنا تغليف هدايا', 'keywords' => ['تغليف']], 'reason' => 'اتسألت كتير', 'evidence' => ['conversation_ids' => [$conversation->id], 'quote' => 'تغليف']],
        ['type' => 'intent_keywords', 'target' => 'price', 'proposed' => ['add' => ['بكام ده']], 'reason' => 'صيغة جديدة', 'evidence' => ['conversation_ids' => [$conversation->id], 'quote' => 'بكام ده']],
        ['type' => 'flow_step', 'target' => 'learning_demo.ghost_step', 'proposed' => ['text' => 'نص'], 'reason' => 'مش موجودة', 'evidence' => ['conversation_ids' => [$conversation->id], 'quote' => 'x']],
    ]);

    $this->artisan('bot:learn', ['--date' => '2026-09-16'])->assertSuccessful();

    $report = BotLearningReport::query()->firstOrFail();

    expect($report->report_date->toDateString())->toBe('2026-09-16')
        ->and($report->summary)->toBe('ملخص اليوم')
        ->and($report->model)->toBe('fake-model')
        ->and($report->input_tokens)->toBe(11)
        ->and($report->stats['conversations'] ?? null)->toBe(1);

    $suggestions = BotSuggestion::query()->get();

    expect($suggestions)->toHaveCount(3)
        ->and($suggestions->pluck('status')->unique()->all())->toBe(['pending'])
        ->and($suggestions->pluck('type')->all())->toContain('script_text', 'new_faq', 'intent_keywords')
        ->and($suggestions->pluck('type')->all())->not->toContain('flow_step')
        // new_faq carries its proposed key as the target so re-runs can dedupe it.
        ->and($suggestions->firstWhere('type', 'new_faq')->target)->toBe('gift_wrap');
});

it('backfills the review with role-prefixed lines and sends the notes grouped by kind', function () {
    $conversation = learningFixtures();

    stubAnalyst([]);

    $this->artisan('bot:learn', ['--date' => '2026-09-16'])->assertSuccessful();

    $analyst = app(LearningAnalyst::class);
    $reviewer = app(ConversationReviewer::class);

    expect($reviewer->reviewed)->toHaveCount(1)
        ->and($reviewer->reviewed[0]['lines'])->toBe(['عميل: الأوردر متأخر', 'بوت: تحت أمرك', 'موظف: هنشحنه بكرة', 'عميل: تمام شكرا'])
        ->and($reviewer->reviewed[0]['intents'])->toBe(['delivery_time'])
        ->and($analyst->notes)->toBe(['agent_knowledge' => [[
            'conversation_id' => $conversation->id,
            'summary' => 'العميلة سألت عن التأخير والموظف رد',
            'quote' => 'الأوردر متأخر',
            'agent_answer' => 'هنشحنه بكرة',
        ]]])
        ->and(array_keys($analyst->catalog))->toBe(['scripts', 'intents', 'flows'])
        ->and($analyst->catalog['scripts']['return_policy'] ?? null)->toBe('النص القديم')
        ->and($analyst->catalog['intents']['price'] ?? null)->toBe(['سعر'])
        ->and($analyst->catalog['flows']['learning_demo']['kind']['text'] ?? null)->toBe('شكوى من إيه؟');
});

it('skips the day when there are no transcripts', function () {
    learningFixtures();

    stubAnalyst([['type' => 'script_text', 'target' => 'return_policy', 'proposed' => ['body' => 'x'], 'reason' => 'r', 'evidence' => []]]);

    $this->artisan('bot:learn', ['--date' => '2026-09-01'])->assertSuccessful();

    expect(BotLearningReport::query()->count())->toBe(0)
        ->and(BotSuggestion::query()->count())->toBe(0);
});

it('replaces the pending suggestions of the same day on a re-run', function () {
    $conversation = learningFixtures();

    stubAnalyst([
        ['type' => 'script_text', 'target' => 'return_policy', 'proposed' => ['body' => 'أول نص'], 'reason' => 'r', 'evidence' => ['conversation_ids' => [$conversation->id]]],
    ]);
    $this->artisan('bot:learn', ['--date' => '2026-09-16'])->assertSuccessful();

    stubAnalyst([
        ['type' => 'script_text', 'target' => 'return_policy', 'proposed' => ['body' => 'تاني نص'], 'reason' => 'r', 'evidence' => ['conversation_ids' => [$conversation->id]]],
    ]);
    $this->artisan('bot:learn', ['--date' => '2026-09-16'])->assertSuccessful();

    expect(BotLearningReport::query()->count())->toBe(1)
        ->and(BotSuggestion::query()->count())->toBe(1)
        ->and(BotSuggestion::query()->first()->proposed['body'])->toBe('تاني نص');
});

it('skips a suggestion that duplicates a pending one from another day', function () {
    $conversation = learningFixtures();

    $payload = [['type' => 'script_text', 'target' => 'return_policy', 'proposed' => ['body' => 'نص'], 'reason' => 'r', 'evidence' => ['conversation_ids' => [$conversation->id]]]];

    stubAnalyst($payload);
    $this->artisan('bot:learn', ['--date' => '2026-09-16'])->assertSuccessful();

    // A second day with the same conversation, so it is reviewed again there too.
    $at = CarbonImmutable::parse('2026-09-17 12:00:00', 'Africa/Cairo')->setTimezone('UTC');
    Message::factory()->create(['conversation_id' => $conversation->id, 'direction' => MessageDirection::In, 'sender_type' => SenderType::Customer, 'body' => 'تاني', 'created_at' => $at]);
    Message::factory()->create(['conversation_id' => $conversation->id, 'direction' => MessageDirection::In, 'sender_type' => SenderType::Customer, 'body' => 'ردوا', 'created_at' => $at->addMinute()]);

    stubAnalyst($payload);
    $this->artisan('bot:learn', ['--date' => '2026-09-17'])->assertSuccessful();

    expect(BotLearningReport::query()->count())->toBe(2)
        ->and(BotSuggestion::query()->count())->toBe(1);
});

it('caps a day at 15 suggestions', function () {
    $conversation = learningFixtures();

    $suggestions = [];
    for ($i = 1; $i <= 20; $i++) {
        $suggestions[] = ['type' => 'new_faq', 'target' => null, 'proposed' => ['key' => "faq_{$i}", 'title' => "س {$i}", 'body' => 'رد', 'keywords' => ['ك']], 'reason' => 'r', 'evidence' => ['conversation_ids' => [$conversation->id]]];
    }

    stubAnalyst($suggestions);

    $this->artisan('bot:learn', ['--date' => '2026-09-16'])->assertSuccessful();

    expect(BotSuggestion::query()->count())->toBe(15);
});

it('defaults to yesterday in Cairo', function () {
    learningFixtures('2026-09-16');

    $this->travelTo(CarbonImmutable::parse('2026-09-17 03:00:00', 'Africa/Cairo'));

    stubAnalyst([]);

    $this->artisan('bot:learn')->assertSuccessful();

    expect(BotLearningReport::query()->first()?->report_date->toDateString())->toBe('2026-09-16');
});

it('schedules bot:learn daily at 02:00 Cairo', function () {
    $events = collect(app(Schedule::class)->events())->filter(fn ($e) => str_contains($e->command ?? '', 'bot:learn'));

    expect($events)->toHaveCount(1)
        ->and($events->first()->expression)->toBe('0 2 * * *')
        ->and($events->first()->timezone)->toBe('Africa/Cairo');
});

it('registers bot:learn even when the app is not running in console', function () {
    $app = app();

    // Review fix 1: under php-fpm `runningInConsole()` is false, so a guarded
    // registration made Artisan::call('bot:learn') throw CommandNotFoundException
    // inside the web request behind the "تشغيل التعلم الآن" button. The static
    // bootstrapper list is process-wide, so it is cleared and restored here.
    $consoleFlag = new ReflectionProperty(Application::class, 'isRunningInConsole');
    $bootstrappers = new ReflectionProperty(ConsoleApplication::class, 'bootstrappers');

    $originalFlag = $consoleFlag->getValue($app);
    $originalBootstrappers = $bootstrappers->getValue();

    try {
        $consoleFlag->setValue($app, false);
        $bootstrappers->setValue(null, []);

        expect($app->runningInConsole())->toBeFalse();

        (new BotServiceProvider($app))->boot();

        $console = new ConsoleApplication($app, $app->make(Dispatcher::class), $app->version());

        expect(array_keys($console->all()))->toContain('bot:learn');
    } finally {
        $consoleFlag->setValue($app, $originalFlag);
        $bootstrappers->setValue(null, $originalBootstrappers);
    }
});

it('keeps only the newest lines of a long conversation', function () {
    learningFixtures();

    $conversation = liveConversation();
    $at = CarbonImmutable::parse('2026-09-16 09:00:00', 'Africa/Cairo')->setTimezone('UTC');

    for ($i = 1; $i <= 50; $i++) {
        Message::factory()->create([
            'conversation_id' => $conversation->id,
            'direction' => MessageDirection::In,
            'sender_type' => SenderType::Customer,
            'body' => "رسالة {$i}",
            'created_at' => $at->addMinutes($i),
        ]);
    }

    $long = collect(app(TranscriptBuilder::class)->forDate(CarbonImmutable::parse('2026-09-16', 'Africa/Cairo')))->firstWhere('conversation_id', $conversation->id);

    expect($long['lines'])->toHaveCount(40)
        ->and($long['lines'][0])->toBe('عميل: رسالة 11')
        ->and(end($long['lines']))->toBe('عميل: رسالة 50');
});

it('drops the smallest conversations when the character budget is exceeded', function () {
    $at = CarbonImmutable::parse('2026-09-16 09:00:00', 'Africa/Cairo')->setTimezone('UTC');

    // Three conversations of 5, 3 and 1 message (~56 chars a line): a 300-char
    // budget only fits the busiest one, at 284.
    $ids = [];
    foreach ([5, 3, 1] as $count) {
        $conversation = liveConversation();
        $ids[] = $conversation->id;

        for ($i = 0; $i < $count; $i++) {
            Message::factory()->create([
                'conversation_id' => $conversation->id,
                'direction' => MessageDirection::In,
                'sender_type' => SenderType::Customer,
                'body' => str_repeat('ا', 50),
                'created_at' => $at->addMinutes($i),
            ]);
        }
    }

    $day = CarbonImmutable::parse('2026-09-16', 'Africa/Cairo');

    $transcripts = app(TranscriptBuilder::class)->forDate($day, 60, 300);

    expect($transcripts)->toHaveCount(1)->and($transcripts[0]['conversation_id'])->toBe($ids[0]);

    // Never returns nothing: the busiest conversation survives any budget.
    expect(app(TranscriptBuilder::class)->forDate($day, 60, 1))->toHaveCount(1);
});

it('truncates long script bodies in the catalog it sends', function () {
    learningFixtures();

    BotKnowledgeEntry::updateOrCreate(['key' => 'script.return_policy'], ['body' => str_repeat('ط', 600)]);

    stubAnalyst([]);
    $this->artisan('bot:learn', ['--date' => '2026-09-16'])->assertSuccessful();

    expect(mb_strlen(app(LearningAnalyst::class)->catalog['scripts']['return_policy']))->toBe(401);
});

it('overwrites the stats the model reported with counted ones', function () {
    $conversation = learningFixtures();

    SupportCase::factory()->count(2)->create([
        'conversation_id' => $conversation->id,
        'created_at' => CarbonImmutable::parse('2026-09-16 13:00:00', 'Africa/Cairo')->setTimezone('UTC'),
    ]);
    // A case from another day must not be counted.
    SupportCase::factory()->create([
        'conversation_id' => $conversation->id,
        'created_at' => CarbonImmutable::parse('2026-09-15 13:00:00', 'Africa/Cairo')->setTimezone('UTC'),
    ]);

    app()->instance(LearningAnalyst::class, new class implements LearningAnalyst
    {
        public function analyze(array $notes, array $catalog): array
        {
            return [
                'summary' => 'ملخص',
                // Invented numbers the command must not trust.
                'stats' => ['conversations' => 999, 'handovers' => 888, 'cases' => 777, 'top_intents' => ['price']],
                'suggestions' => [],
                'model' => 'fake-model',
                'input_tokens' => 1,
                'output_tokens' => 2,
            ];
        }
    });

    $this->artisan('bot:learn', ['--date' => '2026-09-16'])->assertSuccessful();

    $stats = BotLearningReport::query()->firstOrFail()->stats;

    expect($stats['conversations'])->toBe(1)
        ->and($stats['handovers'])->toBe(1)
        ->and($stats['cases'])->toBe(2)
        // top_intents stays the model's read of the day.
        ->and($stats['top_intents'])->toBe(['price']);
});

it('reports a failed analyst as a failure without writing a report', function () {
    learningFixtures();

    app()->instance(LearningAnalyst::class, new class implements LearningAnalyst
    {
        public function analyze(array $notes, array $catalog): array
        {
            throw new RuntimeException('claude down');
        }
    });

    $this->artisan('bot:learn', ['--date' => '2026-09-16'])->assertFailed();

    expect(BotLearningReport::query()->count())->toBe(0)
        ->and(app(LearningOutcome::class)->status)->toBe('failed');
});

// Bug fix (2026-09-17): a 4000-token cap truncated the Claude response before it
// finished, so the JSON never parsed and the command still saved an empty report
// (id 1 on the live Pro Max page). These exercise the real ClaudeLearningAnalyst
// over a faked HTTP call, through the whole `bot:learn` command.
it('fails without saving a report when the response is truncated by the output cap', function () {
    learningFixtures();
    config(['crm.drivers.ai' => 'claude', 'crm.anthropic.key' => 'sk-test']);
    Http::preventStrayRequests();
    Http::fake(['api.anthropic.com/*' => Http::response([
        'content' => [['type' => 'text', 'text' => '{"summary": "الرد اتقطع في النص وممكن يبقى فيه بيانات ناقصة أو غير']],
        'usage' => ['input_tokens' => 500, 'output_tokens' => 12000],
        'stop_reason' => 'max_tokens',
    ])]);

    $this->artisan('bot:learn', ['--date' => '2026-09-16'])->assertFailed();

    expect(BotLearningReport::query()->count())->toBe(0)
        ->and(BotSuggestion::query()->count())->toBe(0)
        ->and(app(LearningOutcome::class)->status)->toBe('failed')
        ->and(app(LearningOutcome::class)->message)->toBe(ClaudeLearningAnalyst::TRUNCATED_MESSAGE);
});

it('fails without saving a report when the JSON cannot be parsed', function () {
    learningFixtures();
    config(['crm.drivers.ai' => 'claude', 'crm.anthropic.key' => 'sk-test']);
    Http::preventStrayRequests();
    Http::fake(['api.anthropic.com/*' => Http::response([
        'content' => [['type' => 'text', 'text' => 'sorry, I cannot produce that right now.']],
        'usage' => ['input_tokens' => 200, 'output_tokens' => 20],
        'stop_reason' => 'end_turn',
    ])]);

    $this->artisan('bot:learn', ['--date' => '2026-09-16'])->assertFailed();

    expect(BotLearningReport::query()->count())->toBe(0)
        ->and(BotSuggestion::query()->count())->toBe(0)
        ->and(app(LearningOutcome::class)->status)->toBe('failed')
        ->and(app(LearningOutcome::class)->message)->toBe(ClaudeLearningAnalyst::TRUNCATED_MESSAGE);
});

it('saves the report and suggestions from a well-formed Claude response', function () {
    $conversation = learningFixtures();
    config(['crm.drivers.ai' => 'claude', 'crm.anthropic.key' => 'sk-test']);
    Http::preventStrayRequests();

    $payload = [
        'summary' => 'ملخص اليوم',
        'stats' => ['conversations' => 1, 'handovers' => 1, 'cases' => 0, 'top_intents' => ['delivery_time']],
        'suggestions' => [
            ['type' => 'intent_keywords', 'target' => 'price', 'proposed' => ['add' => ['بكام ده']], 'reason' => 'صيغة جديدة', 'evidence' => ['conversation_ids' => [$conversation->id], 'quote' => 'بكام ده']],
        ],
    ];

    Http::fake(['api.anthropic.com/*' => Http::response([
        'content' => [['type' => 'text', 'text' => json_encode($payload, JSON_UNESCAPED_UNICODE)]],
        'usage' => ['input_tokens' => 500, 'output_tokens' => 300],
        'stop_reason' => 'end_turn',
    ])]);

    $this->artisan('bot:learn', ['--date' => '2026-09-16'])->assertSuccessful();

    $report = BotLearningReport::query()->firstOrFail();

    expect($report->summary)->toBe('ملخص اليوم')
        ->and(BotSuggestion::query()->count())->toBe(1)
        ->and(app(LearningOutcome::class)->status)->toBe('created');

    Http::assertSent(fn ($r) => $r['max_tokens'] === 12000);
});

it('deletes an empty learning report but keeps a good one', function () {
    $good = BotLearningReport::create(['report_date' => '2026-09-15', 'summary' => 'ملخص كويس', 'stats' => [], 'model' => 'm', 'input_tokens' => 1, 'output_tokens' => 2]);
    BotSuggestion::create(['report_id' => $good->id, 'type' => 'new_faq', 'target' => 'x', 'proposed' => ['key' => 'x'], 'status' => 'pending']);

    $emptyWithNoSuggestions = BotLearningReport::create(['report_date' => '2026-09-16', 'summary' => '', 'stats' => [], 'model' => 'm', 'input_tokens' => 500, 'output_tokens' => 4000]);
    $emptyButNull = BotLearningReport::create(['report_date' => '2026-09-17', 'summary' => null, 'stats' => [], 'model' => 'm', 'input_tokens' => 500, 'output_tokens' => 4000]);

    // An empty summary with a (perhaps stale) suggestion must survive: only a
    // report with NO suggestions at all is bad data.
    $emptyButHasSuggestion = BotLearningReport::create(['report_date' => '2026-09-18', 'summary' => '', 'stats' => [], 'model' => 'm', 'input_tokens' => 1, 'output_tokens' => 1]);
    BotSuggestion::create(['report_id' => $emptyButHasSuggestion->id, 'type' => 'new_faq', 'target' => 'y', 'proposed' => ['key' => 'y'], 'status' => 'pending']);

    (require database_path('migrations/2026_09_17_300010_delete_empty_bot_learning_reports.php'))->up();

    expect(BotLearningReport::query()->whereKey($good->id)->exists())->toBeTrue()
        ->and(BotLearningReport::query()->whereKey($emptyWithNoSuggestions->id)->exists())->toBeFalse()
        ->and(BotLearningReport::query()->whereKey($emptyButNull->id)->exists())->toBeFalse()
        ->and(BotLearningReport::query()->whereKey($emptyButHasSuggestion->id)->exists())->toBeTrue()
        ->and(BotSuggestion::query()->whereKey($emptyButHasSuggestion->suggestions()->first()->id ?? null)->exists())->toBeTrue()
        ->and(BotSuggestion::query()->count())->toBe(2);

    // Idempotent: running it again changes nothing further.
    (require database_path('migrations/2026_09_17_300010_delete_empty_bot_learning_reports.php'))->up();

    expect(BotLearningReport::query()->count())->toBe(2)
        ->and(BotSuggestion::query()->count())->toBe(2);
});

it('publishes the run outcome for the caller', function () {
    learningFixtures();
    stubAnalyst([['type' => 'intent_keywords', 'target' => 'price', 'proposed' => ['add' => ['بكام ده']], 'reason' => 'r', 'evidence' => []]]);

    $this->artisan('bot:learn', ['--date' => '2026-09-16'])->assertSuccessful();

    $outcome = app(LearningOutcome::class);

    expect($outcome->status)->toBe('created')
        ->and($outcome->suggestions)->toBe(1)
        ->and($outcome->reportId)->toBe(BotLearningReport::query()->firstOrFail()->id);

    $this->artisan('bot:learn', ['--date' => '2026-09-01'])->assertSuccessful();

    expect(app(LearningOutcome::class)->status)->toBe('skipped');
});

// Learning v2 (spec 2026-09-18 §1-2): the nightly report is built from the
// day's per-conversation notes, real conversations only.

function noteRow(Conversation $conversation, array $notes, string $cairoAt = '2026-09-16 18:00:00', float $cost = 0.002): BotLearningNote
{
    $row = BotLearningNote::create([
        'conversation_id' => $conversation->id,
        'channel_account_id' => $conversation->channel_account_id,
        'last_message_id' => (int) Message::query()->where('conversation_id', $conversation->id)->max('id'),
        'notes' => $notes,
        'model' => 'claude-haiku-4-5-20251001',
        'input_tokens' => 1000,
        'output_tokens' => 200,
        'cost_usd' => $cost,
    ]);

    $row->forceFill(['created_at' => CarbonImmutable::parse($cairoAt, 'Africa/Cairo')->setTimezone('UTC')])->save();

    return $row;
}

it('builds the report from the notes of the day, marks them used and counts sources and cost', function () {
    $first = learningFixtures();
    $reviewer = fakeReviewer();

    // Two more reviewed conversations: one on the same page, one on another real page.
    $samePage = liveConversation(['channel_account_id' => $first->channel_account_id]);
    $otherPage = liveConversation();
    $otherPage->channelAccount->update(['name' => 'Le Voile']);

    $a = noteRow($first, [['kind' => 'unanswered', 'summary' => 'سؤال عن الخامة', 'quote' => 'الخامة إيه؟']]);
    $b = noteRow($samePage, [['kind' => 'new_phrasing', 'summary' => 'صيغة جديدة للسعر', 'quote' => 'بكام دي']]);
    $c = noteRow($otherPage, [
        ['kind' => 'agent_knowledge', 'summary' => 'الموظف رد بميعاد الشحن', 'quote' => 'هيوصل امتى', 'agent_answer' => 'خلال 3 أيام'],
        ['kind' => 'flow_friction', 'summary' => 'العميلة كررت رقمها', 'quote' => '010'],
    ]);
    // A note from another day stays out.
    $otherDay = noteRow($samePage, [['kind' => 'unanswered', 'summary' => 'امبارح', 'quote' => 'x']], '2026-09-15 18:00:00');

    stubAnalyst([]);

    $this->artisan('bot:learn', ['--date' => '2026-09-16'])->assertSuccessful();

    // Every conversation of the day was already reviewed, so nothing was backfilled.
    expect($reviewer->reviewed)->toBe([]);

    $analyst = app(LearningAnalyst::class);

    expect(array_keys($analyst->notes))->toBe(['agent_knowledge', 'unanswered', 'new_phrasing', 'flow_friction'])
        ->and($analyst->notes['agent_knowledge'][0])->toBe(['conversation_id' => $otherPage->id, 'summary' => 'الموظف رد بميعاد الشحن', 'quote' => 'هيوصل امتى', 'agent_answer' => 'خلال 3 أيام'])
        ->and($analyst->notes['unanswered'])->toHaveCount(1)
        ->and($analyst->notes['unanswered'][0])->not->toHaveKey('agent_answer');

    $report = BotLearningReport::query()->sole();
    $stats = $report->stats;

    expect($stats['conversations_reviewed'])->toBe(3)
        ->and($stats['notes'])->toBe(4)
        ->and($stats['handovers'])->toBe(1)
        ->and($stats['sources'])->toBe([
            ['channel_account_id' => $first->channel_account_id, 'name' => 'Pro Max', 'count' => 2],
            ['channel_account_id' => $otherPage->channel_account_id, 'name' => 'Le Voile', 'count' => 1],
        ])
        // Three reviews at $0.002 plus the analyst's call on an unpriced model.
        ->and($stats['cost_usd'])->toBe(0.006)
        ->and($stats)->not->toHaveKey('demo');

    expect($a->fresh()->used_in_report_id)->toBe($report->id)
        ->and($b->fresh()->used_in_report_id)->toBe($report->id)
        ->and($c->fresh()->used_in_report_id)->toBe($report->id)
        ->and($otherDay->fresh()->used_in_report_id)->toBeNull();
});

it('prices the analyst call into the cost of the day', function () {
    learningFixtures();

    app()->instance(LearningAnalyst::class, new class implements LearningAnalyst
    {
        public function analyze(array $notes, array $catalog): array
        {
            return ['summary' => 'ملخص', 'stats' => [], 'suggestions' => [], 'model' => 'claude-haiku-4-5-20251001', 'input_tokens' => 10000, 'output_tokens' => 2000];
        }
    });

    $this->artisan('bot:learn', ['--date' => '2026-09-16'])->assertSuccessful();

    // Backfilled review: 1000 in / 200 out = $0.002; analyst: 10000 in / 2000 out = $0.02.
    expect(BotLearningReport::query()->sole()->stats['cost_usd'])->toBe(0.022);
});

it('never reads demo conversations or their notes', function () {
    $reviewer = fakeReviewer();

    $demo = Conversation::factory()->create(['channel_account_id' => ChannelAccount::factory()->create(['driver' => 'fake'])->id]);
    $at = CarbonImmutable::parse('2026-09-16 12:00:00', 'Africa/Cairo')->setTimezone('UTC');

    foreach (['سلام', 'السعر كام'] as $i => $body) {
        Message::factory()->create(['conversation_id' => $demo->id, 'direction' => MessageDirection::In, 'sender_type' => SenderType::Customer, 'body' => $body, 'created_at' => $at->addMinutes($i)]);
    }

    noteRow($demo, [['kind' => 'unanswered', 'summary' => 'تجريبي', 'quote' => 'x']]);

    stubAnalyst([]);

    $this->artisan('bot:learn', ['--date' => '2026-09-16'])->assertSuccessful();

    expect($reviewer->reviewed)->toBe([])
        ->and(BotLearningReport::query()->count())->toBe(0)
        ->and(app(LearningOutcome::class)->status)->toBe('skipped');
});

it('skips the day when the reviews found nothing to learn', function () {
    learningFixtures();
    fakeReviewer([]);

    stubAnalyst([]);

    $this->artisan('bot:learn', ['--date' => '2026-09-16'])->assertSuccessful();

    expect(BotLearningReport::query()->count())->toBe(0)
        ->and(BotLearningNote::query()->count())->toBe(1)
        ->and(app(LearningOutcome::class)->status)->toBe('skipped');
});

it('backfills at most the requested number of conversations', function () {
    $reviewer = fakeReviewer();
    $at = CarbonImmutable::parse('2026-09-16 12:00:00', 'Africa/Cairo')->setTimezone('UTC');

    for ($n = 0; $n < 4; $n++) {
        $conversation = liveConversation();

        foreach (['سلام', 'السعر كام'] as $i => $body) {
            Message::factory()->create(['conversation_id' => $conversation->id, 'direction' => MessageDirection::In, 'sender_type' => SenderType::Customer, 'body' => $body, 'created_at' => $at->addMinutes($i)]);
        }
    }

    stubAnalyst([]);

    $this->artisan('bot:learn', ['--date' => '2026-09-16', '--backfill' => 2])->assertSuccessful();

    expect($reviewer->reviewed)->toHaveCount(2)
        ->and(BotLearningReport::query()->sole()->stats['conversations_reviewed'])->toBe(2);
});

it('marks the reports written before learning v2 as demo data', function () {
    $old = BotLearningReport::create(['report_date' => '2026-09-15', 'summary' => 'قديم', 'stats' => ['conversations' => 5], 'model' => 'm', 'input_tokens' => 1, 'output_tokens' => 2]);
    $noStats = BotLearningReport::create(['report_date' => '2026-09-16', 'summary' => 'قديم', 'stats' => null, 'model' => 'm', 'input_tokens' => 1, 'output_tokens' => 2]);

    $migration = require database_path('migrations/2026_09_18_300020_mark_demo_bot_learning_reports.php');
    $migration->up();
    $migration->up();

    expect($old->fresh()->stats)->toBe(['conversations' => 5, 'demo' => true])
        ->and($noStats->fresh()->stats)->toBe(['demo' => true]);
});
