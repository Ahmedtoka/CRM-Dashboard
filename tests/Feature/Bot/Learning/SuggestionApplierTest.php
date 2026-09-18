<?php

use App\Bot\Flows\FlowDrafts;
use App\Bot\Learning\SuggestionApplier;
use App\Bot\Learning\SuggestionValidator;
use App\Models\BotFlow;
use App\Models\BotIntent;
use App\Models\BotKnowledgeEntry;
use App\Models\BotLearningReport;
use App\Models\BotSuggestion;
use App\Models\User;
use Illuminate\Database\Eloquent\ModelNotFoundException;

function applierReport(): BotLearningReport
{
    return BotLearningReport::create([
        'report_date' => '2026-09-16',
        'summary' => 'ملخص',
        'stats' => [],
        'model' => 'fake',
        'input_tokens' => 0,
        'output_tokens' => 0,
    ]);
}

function suggestion(string $type, ?string $target, array $proposed): BotSuggestion
{
    return BotSuggestion::create([
        'report_id' => applierReport()->id,
        'type' => $type,
        'target' => $target,
        'current' => null,
        'proposed' => $proposed,
        'reason' => 'سبب',
        'evidence' => ['conversation_ids' => [1], 'quote' => 'اقتباس'],
        'status' => 'pending',
    ]);
}

function complaintFlow(): BotFlow
{
    return BotFlow::create([
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
}

it('applies a script_text suggestion to the knowledge body', function () {
    $user = User::factory()->create();
    $entry = BotKnowledgeEntry::updateOrCreate(['key' => 'script.return_policy'], ['title' => 'سياسة', 'body' => 'قديم', 'is_active' => true, 'is_template' => false, 'sort' => 10]);

    $s = suggestion('script_text', 'return_policy', ['body' => 'جديد']);

    app(SuggestionApplier::class)->apply($s, $user);

    expect($entry->fresh()->body)->toBe('جديد')
        ->and($s->fresh()->status)->toBe('approved')
        ->and($s->fresh()->decided_by_id)->toBe($user->id)
        ->and($s->fresh()->applied_at)->not->toBeNull()
        ->and($s->fresh()->error)->toBeNull();
});

it('applies a new_faq suggestion as a script and an active answer intent', function () {
    $user = User::factory()->create();

    $s = suggestion('new_faq', 'gift_wrap', ['key' => 'gift_wrap', 'title' => 'تغليف هدايا', 'body' => 'أيوه بنغلف', 'keywords' => ['تغليف', 'هدية']]);

    app(SuggestionApplier::class)->apply($s, $user);

    $entry = BotKnowledgeEntry::where('key', 'script.gift_wrap')->firstOrFail();
    $intent = BotIntent::where('key', 'gift_wrap')->firstOrFail();

    expect($entry->body)->toBe('أيوه بنغلف')
        ->and($entry->is_active)->toBeTrue()
        ->and($intent->route)->toBe('answer')
        ->and($intent->is_active)->toBeTrue()
        ->and($intent->script_keys)->toBe(['gift_wrap'])
        ->and($intent->keywords)->toBe(['تغليف', 'هدية'])
        ->and($s->fresh()->status)->toBe('approved');
});

it('merges intent keywords without duplicates', function () {
    $user = User::factory()->create();

    $intent = BotIntent::updateOrCreate(['key' => 'price'], [
        'group' => 'general', 'label_ar' => 'سعر', 'label_en' => 'Price',
        'route' => 'answer', 'priority' => 'low', 'queue' => null,
        'script_keys' => [], 'required_details' => [], 'keywords' => ['سعر', 'بكام'], 'is_active' => true, 'sort' => 10,
    ]);

    $s = suggestion('intent_keywords', 'price', ['add' => ['بكام', 'السعر كام']]);

    app(SuggestionApplier::class)->apply($s, $user);

    expect($intent->fresh()->keywords)->toBe(['سعر', 'بكام', 'السعر كام'])
        ->and($s->fresh()->status)->toBe('approved');
});

it('applies a flow_step suggestion to the flow draft, not the published definition', function () {
    $user = User::factory()->create();
    $flow = complaintFlow();

    $s = suggestion('flow_step', 'learning_demo.kind', ['text' => 'الشكوى من إيه يا فندم؟', 'options' => [['index' => 1, 'title' => 'شحن وتوصيل']]]);

    app(SuggestionApplier::class)->apply($s, $user);

    $draft = app(FlowDrafts::class)->draftFor($flow->fresh());

    expect($draft['steps']['kind']['text'])->toBe('الشكوى من إيه يا فندم؟')
        ->and($draft['steps']['kind']['options'][1]['title'])->toBe('شحن وتوصيل')
        // The published definition is untouched until the owner publishes the draft.
        ->and($flow->fresh()->definition['steps']['kind']['text'])->toBe('شكوى من إيه؟')
        ->and($s->fresh()->status)->toBe('approved');
});

it('rejects a flow_step option title longer than 20 characters and keeps it pending', function () {
    $user = User::factory()->create();
    $flow = complaintFlow();

    $s = suggestion('flow_step', 'learning_demo.kind', ['options' => [['index' => 0, 'title' => 'عنوان طويل جدا جدا جدا اوي']]]);

    expect(fn () => app(SuggestionApplier::class)->apply($s, $user))->toThrow(DomainException::class);

    $fresh = $s->fresh();

    expect($fresh->status)->toBe('pending')
        ->and($fresh->error)->not->toBeNull()
        ->and($fresh->applied_at)->toBeNull()
        ->and($flow->fresh()->draft()->first())->toBeNull();
});

it('refuses a flow_step suggestion that changes anything but text and option titles', function () {
    $user = User::factory()->create();
    complaintFlow();

    $s = suggestion('flow_step', 'learning_demo.kind', ['next' => 'somewhere', 'text' => 'نص']);

    expect(fn () => app(SuggestionApplier::class)->apply($s, $user))->toThrow(DomainException::class);
    expect($s->fresh()->status)->toBe('pending');
});

it('refuses a script_text suggestion whose script no longer exists', function () {
    $user = User::factory()->create();

    $s = suggestion('script_text', 'ghost', ['body' => 'نص']);

    expect(fn () => app(SuggestionApplier::class)->apply($s, $user))->toThrow(DomainException::class);
    expect($s->fresh()->status)->toBe('pending')->and($s->fresh()->error)->not->toBeNull();
});

it('records an unexpected failure and keeps the suggestion pending', function () {
    $user = User::factory()->create();

    // A validator that waves everything through stands in for the real race:
    // the script row disappearing between validation and the write.
    app()->instance(SuggestionValidator::class, new class(app(FlowDrafts::class)) extends SuggestionValidator
    {
        public function validate(array $suggestion): ?string
        {
            return null;
        }
    });

    $s = suggestion('script_text', 'vanished', ['body' => 'نص']);

    expect(fn () => app(SuggestionApplier::class)->apply($s, $user))->toThrow(ModelNotFoundException::class);

    $fresh = $s->fresh();

    expect($fresh->status)->toBe('pending')
        ->and($fresh->error)->not->toBeNull()
        ->and($fresh->applied_at)->toBeNull();
});

it('refuses to apply a suggestion that is not pending', function () {
    $user = User::factory()->create();
    BotKnowledgeEntry::updateOrCreate(['key' => 'script.return_policy'], ['title' => 'س', 'body' => 'قديم', 'is_active' => true, 'is_template' => false, 'sort' => 10]);

    $s = suggestion('script_text', 'return_policy', ['body' => 'جديد']);
    $s->update(['status' => 'rejected']);

    expect(fn () => app(SuggestionApplier::class)->apply($s, $user))->toThrow(DomainException::class);
    expect(BotKnowledgeEntry::where('key', 'script.return_policy')->first()->body)->toBe('قديم');
});
