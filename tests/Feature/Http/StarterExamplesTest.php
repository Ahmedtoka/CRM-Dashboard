<?php

use App\Enums\UserRole;
use App\Models\QuickReply;
use App\Models\Tag;
use App\Models\User;
use App\Support\StarterExamples;

// Empty-state "أضيفي أمثلة جاهزة" on the Tags and Saved replies screens.

it('adds the starter tags once, keeping existing ones', function () {
    $sup = User::factory()->create(['role' => UserRole::Supervisor]);
    Tag::factory()->create(['name' => 'VIP', 'color' => '#000000']);

    $this->actingAs($sup)->postJson('/settings/tags/examples')->assertCreated()->assertJsonPath('data.created', count(StarterExamples::TAGS) - 1);
    $this->actingAs($sup)->postJson('/settings/tags/examples')->assertOk()->assertJsonPath('data.created', 0);

    expect(Tag::count())->toBe(count(StarterExamples::TAGS))
        ->and(Tag::where('name', 'VIP')->value('color'))->toBe('#000000')
        ->and(Tag::pluck('name')->all())->toContain('مرتجع', 'شكوى', 'متابعة', 'أوردر متأخر');
});

it('adds the starter shared replies once', function () {
    $admin = User::factory()->create(['role' => UserRole::Admin]);

    $this->actingAs($admin)->postJson('/settings/quick-replies/examples')->assertCreated()->assertJsonPath('data.created', count(StarterExamples::QUICK_REPLIES));
    $this->actingAs($admin)->postJson('/settings/quick-replies/examples')->assertOk()->assertJsonPath('data.created', 0);

    $replies = QuickReply::all();
    expect($replies)->toHaveCount(count(StarterExamples::QUICK_REPLIES))
        ->and($replies->every(fn (QuickReply $r) => $r->scope->value === 'shared' && $r->user_id === null && $r->created_by === $admin->id))->toBeTrue();
});

it('keeps moderators out of both example endpoints', function () {
    $mod = User::factory()->create(['role' => UserRole::Moderator]);

    $this->actingAs($mod)->postJson('/settings/tags/examples')->assertForbidden();
    $this->actingAs($mod)->postJson('/settings/quick-replies/examples')->assertForbidden();

    expect(Tag::count())->toBe(0)->and(QuickReply::count())->toBe(0);
});

it('uses shortcuts the saved-reply form itself accepts', function () {
    foreach (StarterExamples::QUICK_REPLIES as $reply) {
        expect(preg_match('/^[\pL\pN_-]+$/u', $reply['shortcut']))->toBe(1);
    }
});
