<?php

use App\Enums\MessageDirection;
use App\Enums\MessageStatus;
use App\Enums\Platform;
use App\Enums\SenderType;
use App\Enums\UserRole;
use App\Models\ChannelAccount;
use App\Models\Conversation;
use App\Models\ConversationNote;
use App\Models\Message;
use App\Models\MessageAttachment;
use App\Models\QueueEntry;
use App\Models\QueueSetting;
use App\Models\Tag;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;

/*
 * Task 4a: the thread detail stays within the query budget (spec §1.3: detail ≤ 15),
 * notes are capped, messages page forward with after_id, counts are capped and cached.
 */

beforeEach(function () {
    $this->admin = User::factory()->create(['role' => UserRole::Admin]);
    $this->mod = User::factory()->create(['role' => UserRole::Moderator]);
    $this->mod->userPlatforms()->create(['platform' => Platform::Facebook]);
    $this->account = ChannelAccount::factory()->create(['platform' => Platform::Facebook]);
});

function detailConversation(): Conversation
{
    $t = test();
    $c = Conversation::factory()->for($t->account, 'channelAccount')->create([
        'assignee_id' => $t->mod->id,
        'last_responder_id' => $t->mod->id,
        'first_responder_id' => $t->mod->id,
        'last_customer_message_at' => now()->subMinutes(5),
    ]);
    $c->tags()->attach(Tag::factory()->count(2)->create()->pluck('id'));
    $entry = QueueEntry::factory()->create(['conversation_id' => $c->id, 'status' => 'active', 'assigned_user_id' => $t->mod->id]);
    $c->forceFill(['queue_entry_id' => $entry->id])->save();

    $messages = Message::factory()->count(60)->for($c)->sequence(
        ['direction' => MessageDirection::In, 'sender_type' => SenderType::Customer],
        ['direction' => MessageDirection::Out, 'sender_type' => SenderType::User, 'user_id' => $t->mod->id],
    )->create();
    foreach ($messages->take(-5) as $m) {
        MessageAttachment::factory()->stored()->create(['message_id' => $m->id]);
    }
    ConversationNote::factory()->count(130)->for($c)->create(['user_id' => $t->mod->id]);

    return $c;
}

it('loads the thread detail within 15 queries with notes capped at 100, newest first', function () {
    $c = detailConversation();
    // Steady state: the presence heartbeat (TrackPresence, at most once a minute) is not part of the thread.
    $this->actingAs($this->admin)->getJson('/inbox/conversations?status=nope');

    DB::flushQueryLog();
    DB::enableQueryLog();
    $res = $this->actingAs($this->admin)->getJson("/inbox/conversations/{$c->id}")->assertOk();
    // 22 before Task 4a in this fixture (26 with the first heartbeat); 14 after.
    $queries = count(DB::getQueryLog());
    DB::disableQueryLog();

    $notes = $res->json('notes.*.id');
    $newest = ConversationNote::query()->where('conversation_id', $c->id)->orderByDesc('id')->limit(100)->pluck('id')->all();

    expect($queries)->toBeLessThanOrEqual(15)
        ->and($notes)->toHaveCount(100)
        ->and($notes)->toBe($newest)
        ->and($res->json('messages'))->toHaveCount(50)
        ->and($res->json('conversation.queue_entry.id'))->toBe($c->queue_entry_id)
        ->and($res->json('conversation.tags'))->toHaveCount(2);
});

it('pages forward with after_id: newer only, ascending, max 200 with has_more_after', function () {
    $c = Conversation::factory()->for($this->account, 'channelAccount')->create();
    $old = Message::factory()->count(3)->for($c)->create();
    $anchor = $old->last();
    $newer = Message::factory()->count(250)->for($c)->create();

    $res = $this->actingAs($this->admin)->getJson("/inbox/conversations/{$c->id}/messages?after_id={$anchor->id}")->assertOk();
    expect($res->json('data.*.id'))->toBe($newer->take(200)->pluck('id')->all())
        ->and($res->json('has_more_after'))->toBeTrue();

    $last = $newer[240]->id;
    $res = $this->actingAs($this->admin)->getJson("/inbox/conversations/{$c->id}/messages?after_id={$last}")->assertOk();
    expect($res->json('data.*.id'))->toBe($newer->slice(241)->pluck('id')->values()->all())
        ->and($res->json('has_more_after'))->toBeFalse();
});

it('refuses before_id and after_id together', function () {
    $c = Conversation::factory()->for($this->account, 'channelAccount')->create();

    $this->actingAs($this->admin)->getJson("/inbox/conversations/{$c->id}/messages?after_id=1&before_id=9")->assertStatus(422);
});

it('returns the retried message with its attachments loaded in one query', function () {
    Queue::fake();
    Event::fake();
    $c = Conversation::factory()->for($this->account, 'channelAccount')->create(['last_customer_message_at' => now()->subMinutes(2)]);
    $m = Message::factory()->for($c)->create([
        'direction' => MessageDirection::Out, 'sender_type' => SenderType::User, 'user_id' => $this->mod->id, 'status' => MessageStatus::Failed,
    ]);
    MessageAttachment::factory()->stored()->count(3)->create(['message_id' => $m->id]);

    DB::flushQueryLog();
    DB::enableQueryLog();
    $res = $this->actingAs($this->mod)->postJson("/inbox/messages/{$m->id}/retry")->assertOk();
    $attachmentQueries = collect(DB::getQueryLog())->filter(fn ($q) => str_contains($q['query'], 'message_attachments'))->count();
    DB::disableQueryLog();

    expect($res->json('data.attachments'))->toHaveCount(3)
        ->and($attachmentQueries)->toBe(1);
});

it('returns the documented counts shape, queue null when the queue is off', function () {
    QueueSetting::current()->update(['enabled' => false]);
    Conversation::factory()->for($this->account, 'channelAccount')->create();

    $res = $this->actingAs($this->admin)->getJson('/inbox/conversations/counts')->assertOk();

    expect($res->json())->toHaveKeys(['status', 'queue', 'capped_at'])
        ->and(array_keys($res->json('status')))->toBe(['open', 'waiting', 'with_moderator', 'bot', 'closed'])
        ->and($res->json('status.open'))->toBe(1)
        ->and($res->json('queue'))->toBeNull()
        ->and($res->json('capped_at'))->toBe(999);
});

it('counts queue states when the queue is on, and caches a repeat call for 15 s', function () {
    QueueSetting::current()->update(['enabled' => true]);
    foreach (range(1, 3) as $i) {
        $c = Conversation::factory()->for($this->account, 'channelAccount')->create();
        $e = QueueEntry::factory()->create(['conversation_id' => $c->id, 'status' => 'waiting']);
        $c->forceFill(['queue_entry_id' => $e->id])->save();
    }

    $res = $this->actingAs($this->admin)->getJson('/inbox/conversations/counts?platform=facebook')->assertOk();
    expect($res->json('queue'))->toBe(['waiting' => 3, 'window' => 0, 'overdue' => 0, 'returning' => 0]);

    DB::flushQueryLog();
    DB::enableQueryLog();
    $again = $this->actingAs($this->admin)->getJson('/inbox/conversations/counts?platform=facebook')->assertOk();
    $countQueries = collect(DB::getQueryLog())->filter(fn ($q) => str_contains(strtolower($q['query']), 'count('))->count();
    DB::disableQueryLog();

    expect($again->json())->toBe($res->json())->and($countQueries)->toBe(0);
});

it('scopes counts to the moderator platforms', function () {
    Conversation::factory()->for($this->account, 'channelAccount')->create();
    Conversation::factory()->for(ChannelAccount::factory()->state(['platform' => Platform::Instagram]), 'channelAccount')->create();

    expect($this->actingAs($this->mod)->getJson('/inbox/conversations/counts')->assertOk()->json('status.open'))->toBe(1)
        ->and($this->actingAs($this->admin)->getJson('/inbox/conversations/counts')->assertOk()->json('status.open'))->toBe(2);
});
