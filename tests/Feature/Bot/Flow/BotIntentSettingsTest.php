<?php

use App\Enums\UserRole;
use App\Models\BotIntent;
use App\Models\BotSetting;
use App\Models\User;

it('lets supervisors list and update intents and blocks moderators', function () {
    $sup = User::factory()->create(['role' => UserRole::Supervisor]);
    $mod = User::factory()->create(['role' => UserRole::Moderator]);
    $intent = BotIntent::where('key', 'cancel_order')->firstOrFail();

    $this->actingAs($mod)->get('/settings/bot-intents')->assertForbidden();
    $this->actingAs($sup)->get('/settings/bot-intents')->assertOk()->assertInertia(fn ($p) => $p->component('settings/BotIntents')->has('intents'));

    $this->actingAs($sup)->patch("/settings/bot-intents/{$intent->id}", ['priority' => 'high', 'queue' => 'senior', 'is_active' => true])->assertRedirect();
    expect($intent->fresh()->priority)->toBe('high');

    $this->actingAs($sup)->patchJson("/settings/bot-intents/{$intent->id}", ['priority' => 'urgent'])->assertStatus(422);
});

it('shares only script.* knowledge entries as the scripts prop', function () {
    $sup = User::factory()->create(['role' => UserRole::Supervisor]);

    $this->actingAs($sup)->get('/settings/bot-intents')->assertOk()->assertInertia(fn ($p) => $p
        ->component('settings/BotIntents')
        ->has('scripts')
        ->where('scripts', fn ($scripts) => collect($scripts)->isNotEmpty()
            && collect($scripts)->every(fn ($s) => str_starts_with($s['key'], 'script.')))
        ->where('intents', fn ($intents) => collect($intents)->pluck('key')->contains('cancel_order')));

    $this->actingAs(User::factory()->create(['role' => UserRole::Moderator]))->patchJson('/settings/bot-intents/'.BotIntent::value('id'), ['is_active' => false])->assertForbidden();
});

it('saves a full row as json and applies partial patches without touching other fields', function () {
    $admin = User::factory()->create(['role' => UserRole::Admin]);
    $intent = BotIntent::where('key', 'store_complaint')->firstOrFail();

    $this->actingAs($admin)->patchJson("/settings/bot-intents/{$intent->id}", [
        'route' => 'handover',
        'priority' => 'medium',
        'queue' => null,
        'script_keys' => ['branch_complaint', 'greeting'],
        'required_details' => ['order_ref|phone|email', 'photos', 'invoice?', 'visit_date'],
        'keywords' => ['فرع', 'branch'],
        'is_active' => false,
    ])->assertOk()->assertJsonPath('data.route', 'handover')->assertJsonPath('data.queue', null);

    $fresh = $intent->fresh();
    expect($fresh->route)->toBe('handover')
        ->and($fresh->queue)->toBeNull()
        ->and($fresh->script_keys)->toBe(['branch_complaint', 'greeting'])
        ->and($fresh->required_details)->toBe(['order_ref|phone|email', 'photos', 'invoice?', 'visit_date'])
        ->and($fresh->keywords)->toBe(['فرع', 'branch'])
        ->and($fresh->is_active)->toBeFalse();

    $this->actingAs($admin)->patchJson("/settings/bot-intents/{$intent->id}", ['is_active' => true])->assertOk();
    expect($intent->fresh()->route)->toBe('handover')->and($intent->fresh()->is_active)->toBeTrue()->and($intent->fresh()->label_ar)->toBe($intent->label_ar);
});

it('rejects unknown routes, queues, scripts, detail tokens and long keywords', function (array $payload, string $field) {
    $sup = User::factory()->create(['role' => UserRole::Supervisor]);
    $intent = BotIntent::where('key', 'cancel_order')->firstOrFail();

    $this->actingAs($sup)->patchJson("/settings/bot-intents/{$intent->id}", $payload)->assertStatus(422)->assertJsonValidationErrorFor($field);
})->with([
    'route' => [['route' => 'reply'], 'route'],
    'queue' => [['queue' => 'vip'], 'queue'],
    'script' => [['script_keys' => ['does_not_exist']], 'script_keys.0'],
    'script prefix' => [['script_keys' => ['script.greeting']], 'script_keys.0'],
    'token' => [['required_details' => ['shoe_size']], 'required_details.0'],
    'token alt' => [['required_details' => ['phone|shoe_size']], 'required_details.0'],
    'token not a string' => [['required_details' => [['x']]], 'required_details.0'],
    'script not a string' => [['script_keys' => [['greeting']]], 'script_keys.0'],
    'keyword not a string' => [['keywords' => [['x']]], 'keywords.0'],
    'keyword' => [['keywords' => [str_repeat('a', 61)]], 'keywords.0'],
    'active' => [['is_active' => 'maybe'], 'is_active'],
]);

it('saves timing settings within bounds', function () {
    $admin = User::factory()->create(['role' => UserRole::Admin]);

    $this->actingAs($admin)->putJson('/settings/bot', [
        'burst_wait_seconds' => 10,
        'burst_max_wait_seconds' => 30,
        'typing_ms_per_char' => 40,
        'order_lookup_enabled' => false,
    ])->assertOk();

    $s = BotSetting::current();
    expect($s->burst_wait_seconds)->toBe(10)
        ->and($s->burst_max_wait_seconds)->toBe(30)
        ->and($s->typing_ms_per_char)->toBe(40)
        ->and($s->order_lookup_enabled)->toBeFalse();

    $this->actingAs($admin)->putJson('/settings/bot', ['burst_wait_seconds' => 999])->assertStatus(422)->assertJsonValidationErrorFor('burst_wait_seconds');
    $this->actingAs($admin)->putJson('/settings/bot', ['burst_wait_seconds' => 20, 'burst_max_wait_seconds' => 10])->assertStatus(422)->assertJsonValidationErrorFor('burst_max_wait_seconds');
    $this->actingAs($admin)->putJson('/settings/bot', ['burst_max_wait_seconds' => 121])->assertStatus(422)->assertJsonValidationErrorFor('burst_max_wait_seconds');
    $this->actingAs($admin)->putJson('/settings/bot', ['typing_ms_per_char' => 121])->assertStatus(422)->assertJsonValidationErrorFor('typing_ms_per_char');

    // Max wait alone is checked against the saved wait (10): 5 is below it.
    $this->actingAs($admin)->putJson('/settings/bot', ['burst_max_wait_seconds' => 5])->assertStatus(422)->assertJsonValidationErrorFor('burst_max_wait_seconds');

    expect(BotSetting::current()->burst_wait_seconds)->toBe(10);
});

it('lets a supervisor save timing settings and shares them on the bot page', function () {
    $sup = User::factory()->create(['role' => UserRole::Supervisor]);

    $this->actingAs($sup)->putJson('/settings/bot', ['typing_ms_per_char' => 0, 'burst_wait_seconds' => 0, 'burst_max_wait_seconds' => 0])->assertOk();

    $this->actingAs($sup)->get('/settings/bot')->assertInertia(fn ($p) => $p
        ->component('settings/Bot')
        ->where('settings.typing_ms_per_char', 0)
        ->where('settings.burst_max_wait_seconds', 0)
        ->has('settings.order_lookup_enabled')
        // The keyword-rule editor is gone (menu + guided flows replaced it).
        ->missing('rules'));

    $this->actingAs($sup)->postJson('/settings/bot/rules', ['name' => 'x'])->assertNotFound();
});

it('shares the flows and saves, clears or rejects an intent flow_key', function () {
    $admin = User::factory()->create(['role' => UserRole::Admin]);
    $intent = BotIntent::where('key', 'price')->firstOrFail();

    $this->actingAs($admin)->get('/settings/bot-intents')->assertOk()->assertInertia(fn ($p) => $p
        ->where('flows', fn ($flows) => collect($flows)->pluck('key')->contains('return_exchange')));

    $this->actingAs($admin)->patchJson("/settings/bot-intents/{$intent->id}", ['flow_key' => 'complaint'])->assertOk();
    expect($intent->fresh()->flow_key)->toBe('complaint');

    $this->actingAs($admin)->patchJson("/settings/bot-intents/{$intent->id}", ['flow_key' => null])->assertOk();
    expect($intent->fresh()->flow_key)->toBeNull();

    $this->actingAs($admin)->patchJson("/settings/bot-intents/{$intent->id}", ['flow_key' => 'nope'])->assertStatus(422)->assertJsonValidationErrors('flow_key');
});
