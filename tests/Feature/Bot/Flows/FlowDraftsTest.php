<?php

use App\Bot\Flows\FlowDrafts;
use App\Bot\Flows\FlowValidationException;
use App\Enums\UserRole;
use App\Models\BotFlow;
use App\Models\BotFlowVersion;
use App\Models\User;

beforeEach(fn () => $this->u = User::factory()->create(['role' => UserRole::Supervisor]));

it('seeds a published version 1 for every existing flow', function () {
    expect(BotFlowVersion::where('status', 'published')->count())->toBe(BotFlow::count())
        ->and(BotFlow::where('key', 'complaint')->first()->publishedVersion->version)->toBe(1);
});

it('saves a draft without touching the published definition, then publishes it as version 2', function () {
    $flow = BotFlow::where('key', 'branches')->first();
    $def = $flow->definition;
    $def['steps']['list']['text'] = 'اختاري منطقتك 👇';

    $draft = app(FlowDrafts::class)->saveDraft($flow, $def, $this->u);
    expect($draft->status)->toBe('draft')->and($draft->version)->toBe(2)
        ->and($flow->fresh()->definition['steps']['list']['text'])->not->toBe('اختاري منطقتك 👇');

    app(FlowDrafts::class)->saveDraft($flow, $def, $this->u);
    expect(BotFlowVersion::where('bot_flow_id', $flow->id)->where('status', 'draft')->count())->toBe(1);

    $published = app(FlowDrafts::class)->publish($flow, $this->u, 'تعديل النص');
    expect($published->status)->toBe('published')->and($published->note)->toBe('تعديل النص')
        ->and($flow->fresh()->definition['steps']['list']['text'])->toBe('اختاري منطقتك 👇')
        ->and(BotFlowVersion::where('bot_flow_id', $flow->id)->where('version', 1)->value('status'))->toBe('archived');
});

it('refuses to publish an invalid draft and keeps the live definition', function () {
    $flow = BotFlow::where('key', 'branches')->first();
    $def = $flow->definition;
    $def['steps']['list']['next'] = 'nowhere';
    app(FlowDrafts::class)->saveDraft($flow, $def, $this->u);

    expect(fn () => app(FlowDrafts::class)->publish($flow, $this->u, null))->toThrow(FlowValidationException::class);
    expect($flow->fresh()->definition['steps']['list']['next'])->toBe('end');
});

it('rejects a menu action pointing at a missing flow or script on publish', function () {
    $flow = BotFlow::where('key', 'main_menu')->first();
    $def = $flow->definition;
    $def['steps']['menu']['options'][] = ['title' => 'جديد', 'action' => 'flow:does_not_exist'];
    $def['steps']['menu']['options'][] = ['title' => 'سكريبت', 'action' => 'script:nope'];

    expect(app(FlowDrafts::class)->errors($def))->toHaveCount(2);
});

it('restores an archived version as a new published version', function () {
    $flow = BotFlow::where('key', 'branches')->first();
    $original = $flow->definition;
    $def = $original;
    $def['steps']['list']['text'] = 'نص جديد';
    app(FlowDrafts::class)->saveDraft($flow, $def, $this->u);
    app(FlowDrafts::class)->publish($flow, $this->u, null);

    $restored = app(FlowDrafts::class)->restore(BotFlowVersion::where('bot_flow_id', $flow->id)->where('version', 1)->first(), $this->u);

    expect($restored->version)->toBe(3)->and($restored->note)->toBe('استرجاع نسخة 1')
        ->and($flow->fresh()->definition['steps']['list']['text'])->toBe($original['steps']['list']['text']);
});

it('keeps an existing draft unchanged when restoring an older version', function () {
    $flow = BotFlow::where('key', 'branches')->first();

    $publishedDef = $flow->definition;
    $publishedDef['steps']['list']['text'] = 'نص جديد';
    app(FlowDrafts::class)->saveDraft($flow, $publishedDef, $this->u);
    app(FlowDrafts::class)->publish($flow, $this->u, null);

    $draftDef = $flow->fresh()->definition;
    $draftDef['steps']['list']['text'] = 'مسودة قيد التعديل';
    $draft = app(FlowDrafts::class)->saveDraft($flow, $draftDef, $this->u);

    app(FlowDrafts::class)->restore(BotFlowVersion::where('bot_flow_id', $flow->id)->where('version', 1)->first(), $this->u);

    $freshDraft = $flow->fresh()->draft;
    expect($freshDraft->id)->toBe($draft->id)
        ->and($freshDraft->status)->toBe('draft')
        ->and($freshDraft->definition['steps']['list']['text'])->toBe('مسودة قيد التعديل');
});

it('rejects a publish or restore note longer than 200 characters', function () {
    $flow = BotFlow::where('key', 'branches')->first();
    $def = $flow->definition;
    $def['steps']['list']['text'] = 'نص جديد';
    app(FlowDrafts::class)->saveDraft($flow, $def, $this->u);

    expect(fn () => app(FlowDrafts::class)->publish($flow, $this->u, str_repeat('a', 201)))
        ->toThrow(InvalidArgumentException::class);
});

it('creates a new inactive flow or copies an existing one', function () {
    $new = app(FlowDrafts::class)->create('vip_orders', 'طلبات VIP', null, $this->u);
    $copy = app(FlowDrafts::class)->create('complaint_copy', 'شكوى 2', BotFlow::where('key', 'complaint')->first(), $this->u);

    expect($new->is_active)->toBeFalse()->and($new->definition)->toBe(FlowDrafts::NEW_FLOW_DEFINITION)
        ->and($new->publishedVersion->version)->toBe(1)
        ->and($copy->definition)->toBe(BotFlow::where('key', 'complaint')->first()->definition);
    expect(fn () => app(FlowDrafts::class)->create('Bad Key', 'x', null, $this->u))->toThrow(InvalidArgumentException::class);
    expect(fn () => app(FlowDrafts::class)->create('complaint', 'x', null, $this->u))->toThrow(InvalidArgumentException::class);
});
