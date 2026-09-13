<?php

use App\Analytics\ActivityLogger;
use App\Enums\{ConversationStatus, Platform, UserRole};
use App\Models\{ActivityLog, Conversation, ChannelAccount, User};
use Illuminate\Support\Facades\Event;

it('scopes the inbox list by moderator platforms', function () {
    $fb = Conversation::factory()->for(ChannelAccount::factory()->state(['platform'=>Platform::Facebook]), 'channelAccount')->create(['platform'=>Platform::Facebook]);
    $tt = Conversation::factory()->for(ChannelAccount::factory()->state(['platform'=>Platform::TikTok]), 'channelAccount')->create(['platform'=>Platform::TikTok]);
    $mod = User::factory()->create(['role'=>UserRole::Moderator]); $mod->userPlatforms()->create(['platform'=>Platform::Facebook]);
    $ids = collect($this->actingAs($mod)->getJson('/inbox/conversations')->assertOk()->json('data'))->pluck('id');
    expect($ids)->toContain($fb->id)->not->toContain($tt->id);
    $this->actingAs($mod)->getJson("/inbox/conversations/{$tt->id}")->assertForbidden();
});

it('returns 422 when the window is closed', function () {
    $c = Conversation::factory()->for(ChannelAccount::factory()->state(['platform'=>Platform::Instagram]), 'channelAccount')
        ->create(['platform'=>Platform::Instagram, 'last_customer_message_at'=>now()->subDays(9)]);
    $admin = User::factory()->create(['role'=>UserRole::Admin]);
    $this->actingAs($admin)->postJson("/inbox/conversations/{$c->id}/messages", ['body'=>'hi'])->assertStatus(422);
});

it('resolves, logs and reopens a conversation', function () {
    Event::fake();
    $c = Conversation::factory()->for(ChannelAccount::factory()->state(['platform'=>Platform::Facebook]), 'channelAccount')
        ->create(['platform'=>Platform::Facebook, 'unread_count'=>3]);
    $mod = User::factory()->create(['role'=>UserRole::Moderator]); $mod->userPlatforms()->create(['platform'=>Platform::Facebook]);
    $c->forceFill(['locked_by_id'=>$mod->id, 'locked_until'=>now()->addSeconds(30)])->save();

    $this->actingAs($mod)->postJson("/inbox/conversations/{$c->id}/resolve")
        ->assertOk()->assertJsonPath('data.status', 'resolved');

    $fresh = $c->fresh();
    expect($fresh->status)->toBe(ConversationStatus::Resolved)
        ->and($fresh->resolved_by_id)->toBe($mod->id)
        ->and($fresh->resolved_at)->not->toBeNull()
        ->and($fresh->locked_by_id)->toBeNull()
        ->and(ActivityLog::where('action', ActivityLogger::CONVERSATION_RESOLVED)->where('user_id', $mod->id)->where('conversation_id', $c->id)->exists())->toBeTrue();

    $this->actingAs($mod)->postJson("/inbox/conversations/{$c->id}/reopen")->assertOk()->assertJsonPath('data.status', 'open');
    expect($c->fresh()->resolved_at)->toBeNull()
        ->and(ActivityLog::where('action', ActivityLogger::CONVERSATION_REOPENED)->where('user_id', $mod->id)->exists())->toBeTrue();

    $this->actingAs($mod)->postJson("/inbox/conversations/{$c->id}/read")->assertOk()->assertJsonPath('data.unread_count', 0);
});

it('filters waiting conversations oldest first', function () {
    $acc = ChannelAccount::factory()->create(['platform'=>Platform::WhatsApp]);
    $newer = Conversation::factory()->for($acc, 'channelAccount')->create(['last_customer_message_at'=>now()->subMinutes(5), 'last_message_at'=>now()->subMinutes(5)]);
    $older = Conversation::factory()->for($acc, 'channelAccount')->create(['last_customer_message_at'=>now()->subMinutes(50), 'last_message_at'=>now()->subMinutes(50)]);
    $admin = User::factory()->create(['role'=>UserRole::Admin]);

    $ids = $this->actingAs($admin)->getJson('/inbox/conversations?filter=waiting')->assertOk()->json('data.*.id');
    expect($ids)->toBe([$older->id, $newer->id]);
});

it('renders the inbox page for any user', function () {
    $mod = User::factory()->create(['role'=>UserRole::Moderator]);
    $this->actingAs($mod)->get('/inbox')->assertOk()
        ->assertInertia(fn ($page) => $page->component('Inbox')->has('conversations')->has('quickReplies')->has('tags')->has('cities')
            ->where('auth.user.role', 'moderator')->has('platforms', 4));
});
