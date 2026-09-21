<?php

use App\Bot\Replies\ReplyCatalog;
use App\Models\BotKnowledgeEntry;
use App\Models\User;
use Inertia\Testing\AssertableInertia;

it('lists every reply with the moment it is given, and saves an edit through the knowledge endpoint', function () {
    $rows = app(ReplyCatalog::class)->rows();

    expect($rows->firstWhere('key', 'script.products_intro')['section'])->toBe('products')
        ->and($rows->firstWhere('key', 'script.handover_in_hours')['when'])->toContain('مواعيد العمل')
        ->and($rows->where('source', 'flow')->where('key', 'main_menu')->first()['buttons'])->toContain('المرتجع والاستبدال')
        ->and($rows->where('section', 'questions')->count())->toBeGreaterThan(10);

    $supervisor = User::factory()->create(['role' => 'supervisor']);

    $this->actingAs($supervisor)->get('/settings/bot-replies')->assertOk()->assertInertia(
        fn (AssertableInertia $page) => $page->component('settings/BotReplies')->has('sections')->where('agent.entry.key', 'agent_instructions')
    );

    $entry = BotKnowledgeEntry::where('key', 'script.thanks')->firstOrFail();
    $this->actingAs($supervisor)->putJson("/settings/bot-knowledge/entries/{$entry->id}", ['body' => 'العفو 🌸'])->assertOk();

    expect(app(ReplyCatalog::class)->rows()->firstWhere('key', 'script.thanks')['reply'])->toBe('العفو 🌸');
});
