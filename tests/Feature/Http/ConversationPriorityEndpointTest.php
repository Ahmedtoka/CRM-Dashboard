<?php

use App\Enums\{UserRole, ConversationPriority};
use App\Events\ConversationUpdated;
use App\Models\{Conversation, User, ActivityLog};
use Illuminate\Support\Facades\Event;

it('lets a moderator mark not spam, allowlists the sender and hides spam from the default list', function () {
    $admin = User::factory()->create(['role' => UserRole::Admin]);
    $spam = Conversation::factory()->create(['priority' => 'spam']);
    $normal = Conversation::factory()->create();
    $ids = fn ($q = '') => collect($this->actingAs($admin)->getJson('/inbox/conversations'.$q)->json('data'))->pluck('id');
    expect($ids())->toContain($normal->id)->not->toContain($spam->id)->and($ids('?filter=spam'))->toContain($spam->id);

    Event::fake([ConversationUpdated::class]);
    $this->actingAs($admin)->postJson("/inbox/conversations/{$spam->id}/priority", ['priority' => 'normal'])->assertOk();

    expect($spam->fresh()->priority)->toBe(ConversationPriority::Normal)
        ->and(ActivityLog::where('action', 'conversation.priority_changed')->exists())->toBeTrue();
    Event::assertDispatched(ConversationUpdated::class, fn (ConversationUpdated $e) => $e->conversation->id === $spam->id
        && $e->conversation->priority === ConversationPriority::Normal);
});
