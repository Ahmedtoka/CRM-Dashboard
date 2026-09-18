<?php

use App\Analytics\ActivityLogger;
use App\Bot\Flows\FlowDrafts;
use App\Enums\UserRole;
use App\Models\ActivityLog;
use App\Models\BotFlow;
use App\Models\User;

it('lets supervisors reach the flow designer page and blocks moderators from every route', function () {
    $sup = User::factory()->create(['role' => UserRole::Supervisor]);
    $mod = User::factory()->create(['role' => UserRole::Moderator]);
    $flow = BotFlow::where('key', 'complaint')->firstOrFail();
    $version = $flow->versions()->firstOrFail();

    $this->actingAs($sup)->get('/settings/bot-flows')->assertOk()->assertInertia(fn ($p) => $p
        ->component('settings/BotFlows', false)
        ->has('flows', 7)
        ->has('stepTypes.photo'));

    $this->actingAs($mod)->get('/settings/bot-flows')->assertForbidden();
    $this->actingAs($mod)->getJson("/settings/bot-flows/{$flow->id}")->assertForbidden();
    $this->actingAs($mod)->postJson('/settings/bot-flows', ['key' => 'x', 'title_ar' => 'س'])->assertForbidden();
    $this->actingAs($mod)->putJson("/settings/bot-flows/{$flow->id}/draft", ['definition' => $flow->definition])->assertForbidden();
    $this->actingAs($mod)->deleteJson("/settings/bot-flows/{$flow->id}/draft")->assertForbidden();
    $this->actingAs($mod)->postJson("/settings/bot-flows/{$flow->id}/publish")->assertForbidden();
    $this->actingAs($mod)->patchJson("/settings/bot-flows/{$flow->id}", ['title_ar' => 'تعديل'])->assertForbidden();
    $this->actingAs($mod)->postJson("/settings/bot-flows/{$flow->id}/main-menu", ['title' => 'تجربة'])->assertForbidden();
    $this->actingAs($mod)->postJson("/settings/bot-flow-versions/{$version->id}/restore")->assertForbidden();
});

it('saves a draft without blocking on validation and keeps it separate from the published definition', function () {
    $sup = User::factory()->create(['role' => UserRole::Supervisor]);
    $flow = BotFlow::where('key', 'complaint')->firstOrFail();
    $original = $flow->definition['steps']['description']['text'];

    $definition = $flow->definition;
    $definition['steps']['description']['text'] = 'نص جديد للمسودة';

    $this->actingAs($sup)->putJson("/settings/bot-flows/{$flow->id}/draft", ['definition' => $definition])
        ->assertOk()
        ->assertJsonPath('data.errors', []);

    $this->actingAs($sup)->getJson("/settings/bot-flows/{$flow->id}")
        ->assertOk()
        ->assertJsonPath('data.draft.steps.description.text', 'نص جديد للمسودة')
        ->assertJsonPath('data.published.steps.description.text', $original);
});

it('reports validation errors on save without blocking it, and blocks publish', function () {
    $sup = User::factory()->create(['role' => UserRole::Supervisor]);
    $flow = BotFlow::where('key', 'complaint')->firstOrFail();

    $definition = $flow->definition;
    $definition['steps']['type']['next'] = 'nowhere';

    $this->actingAs($sup)->putJson("/settings/bot-flows/{$flow->id}/draft", ['definition' => $definition])
        ->assertOk()
        ->assertJsonPath('data.errors', fn ($errors) => $errors !== []);

    $this->actingAs($sup)->postJson("/settings/bot-flows/{$flow->id}/publish")
        ->assertStatus(422)
        ->assertJsonStructure(['message', 'errors']);
});

it('publishes a valid draft, mirrors it onto the flow definition and logs it', function () {
    $sup = User::factory()->create(['role' => UserRole::Supervisor]);
    $flow = BotFlow::where('key', 'complaint')->firstOrFail();

    $definition = $flow->definition;
    $definition['steps']['description']['text'] = 'نص بعد النشر';

    $this->actingAs($sup)->putJson("/settings/bot-flows/{$flow->id}/draft", ['definition' => $definition])->assertOk();

    $this->actingAs($sup)->postJson("/settings/bot-flows/{$flow->id}/publish", ['note' => 'تحديث النص'])
        ->assertOk()
        ->assertJsonPath('data.version.status', 'published');

    expect($flow->fresh()->definition['steps']['description']['text'])->toBe('نص بعد النشر');
    expect(ActivityLog::query()->where('action', ActivityLogger::BOT_FLOW_PUBLISHED)->where('subject_id', $flow->id)->exists())->toBeTrue();
});

it('rejects a stale draft save with a 409 conflict', function () {
    $sup = User::factory()->create(['role' => UserRole::Supervisor]);
    $flow = BotFlow::where('key', 'complaint')->firstOrFail();

    $this->actingAs($sup)->putJson("/settings/bot-flows/{$flow->id}/draft", ['definition' => $flow->definition])->assertOk();

    $staleBase = now()->subMinute()->toISOString();

    $this->actingAs($sup)->putJson("/settings/bot-flows/{$flow->id}/draft", [
        'definition' => $flow->definition,
        'base_updated_at' => $staleBase,
    ])->assertStatus(409)->assertJsonStructure(['message']);
});

it('rejects a save without a base when a draft already exists, and accepts it with the current base', function () {
    $sup = User::factory()->create(['role' => UserRole::Supervisor]);
    $flow = BotFlow::where('key', 'complaint')->firstOrFail();

    // Someone else creates the draft while this client still has the draft-less flow open.
    $first = $this->actingAs($sup)->putJson("/settings/bot-flows/{$flow->id}/draft", ['definition' => $flow->definition, 'base_updated_at' => null])
        ->assertOk();

    $this->actingAs($sup)->putJson("/settings/bot-flows/{$flow->id}/draft", ['definition' => $flow->definition, 'base_updated_at' => null])
        ->assertStatus(409)->assertJsonStructure(['message']);

    $this->actingAs($sup)->putJson("/settings/bot-flows/{$flow->id}/draft", [
        'definition' => $flow->definition,
        'base_updated_at' => $first->json('data.draft_updated_at'),
    ])->assertOk();
});

it('creates a flow from scratch or as a copy of another flow', function () {
    $sup = User::factory()->create(['role' => UserRole::Supervisor]);
    $source = BotFlow::where('key', 'complaint')->firstOrFail();

    $response = $this->actingAs($sup)->postJson('/settings/bot-flows', [
        'key' => 'complaint_copy',
        'title_ar' => 'شكوى نسخة',
        'copy_from' => $source->id,
    ])->assertCreated();

    expect($response->json('data.flow.key'))->toBe('complaint_copy');

    $copy = BotFlow::where('key', 'complaint_copy')->firstOrFail();
    expect($copy->definition)->toBe($source->definition)
        ->and($copy->is_active)->toBeFalse();
});

it('refuses to deactivate the main menu flow', function () {
    $sup = User::factory()->create(['role' => UserRole::Supervisor]);
    $mainMenu = BotFlow::where('key', 'main_menu')->firstOrFail();

    $this->actingAs($sup)->patchJson("/settings/bot-flows/{$mainMenu->id}", ['is_active' => false])
        ->assertStatus(422)
        ->assertJsonStructure(['message']);

    expect($mainMenu->fresh()->is_active)->toBeTrue();
});

it('adds a flow to the main menu draft once, and blocks a duplicate action', function () {
    $sup = User::factory()->create(['role' => UserRole::Supervisor]);
    $source = BotFlow::where('key', 'complaint')->firstOrFail();
    $copy = app(FlowDrafts::class)->create('complaint_copy', 'شكوى نسخة', $source, $sup);

    $this->actingAs($sup)->postJson("/settings/bot-flows/{$copy->id}/main-menu", ['title' => 'شكوى 2'])
        ->assertOk();

    $mainMenu = BotFlow::where('key', 'main_menu')->firstOrFail();
    $draft = $mainMenu->draft()->firstOrFail();
    expect(collect($draft->definition['steps']['menu']['options'])->pluck('action'))->toContain('flow:complaint_copy');

    $this->actingAs($sup)->postJson("/settings/bot-flows/{$copy->id}/main-menu", ['title' => 'تكرار'])
        ->assertStatus(422)
        ->assertJsonStructure(['message']);
});

it('restores an old version as a brand-new published version', function () {
    $sup = User::factory()->create(['role' => UserRole::Supervisor]);
    $flow = BotFlow::where('key', 'complaint')->firstOrFail();
    $v1 = $flow->versions()->where('version', 1)->firstOrFail();

    $definition = $flow->definition;
    $definition['steps']['description']['text'] = 'نسخة جديدة قبل الاسترجاع';
    $this->actingAs($sup)->putJson("/settings/bot-flows/{$flow->id}/draft", ['definition' => $definition])->assertOk();
    $this->actingAs($sup)->postJson("/settings/bot-flows/{$flow->id}/publish")->assertOk();

    $response = $this->actingAs($sup)->postJson("/settings/bot-flow-versions/{$v1->id}/restore")->assertOk();

    expect($response->json('data.version.version'))->toBe(3)
        ->and($flow->fresh()->definition)->toBe($v1->definition);
    expect(ActivityLog::query()->where('action', ActivityLogger::BOT_FLOW_RESTORED)->exists())->toBeTrue();
});

it('passes script bodies and their active flag to the designer for the customer preview', function () {
    $sup = User::factory()->create(['role' => UserRole::Supervisor]);
    \App\Models\BotKnowledgeEntry::query()->updateOrCreate(['key' => 'script.preview_on'], ['title' => 'شغال', 'body' => '{time_greeting} يا فندم', 'is_active' => true]);
    \App\Models\BotKnowledgeEntry::query()->updateOrCreate(['key' => 'script.preview_off'], ['title' => 'متوقف', 'body' => 'نص قديم', 'is_active' => false]);

    $this->actingAs($sup)->get('/settings/bot-flows')->assertOk()->assertInertia(function ($page) {
        $scripts = collect($page->toArray()['props']['scripts'])->keyBy('key');

        expect($scripts['preview_on'])->toMatchArray(['title' => 'شغال', 'body' => '{time_greeting} يا فندم', 'is_active' => true])
            ->and($scripts['preview_off'])->toMatchArray(['body' => 'نص قديم', 'is_active' => false]);
    });
});
