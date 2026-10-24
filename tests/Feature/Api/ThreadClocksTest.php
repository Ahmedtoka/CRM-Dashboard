<?php

use App\Models\QueueEntry;
use App\Models\QueueSetting;
use App\Models\Shift;
use App\Models\ShiftMember;
use App\Models\User;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\Sanctum;

/*
 * Mobile r2: the two moderator clocks (owner 2026-10-19) reach the app in two places —
 * her own windows on GET queue/me (QueueEntryResource) and, additively, the thread's
 * `conversation.queue_entry` on GET conversations/{id} for any open window she may read.
 */

beforeEach(function () {
    Carbon::setTestNow(Carbon::parse('2026-10-24 12:00', 'Africa/Cairo'));
    QueueSetting::factory()->create(['id' => 1, 'enabled' => true, 'silence_close_seconds' => 600, 'silence_warn_seconds' => 120]);
});

function tcModerator(array $attrs = []): User
{
    $u = User::factory()->create($attrs + ['role' => 'moderator', 'last_seen_at' => now()]);
    foreach (['facebook', 'instagram', 'whatsapp', 'tiktok'] as $platform) {
        $u->userPlatforms()->create(['platform' => $platform]);
    }

    return $u;
}

/** @return array{0: User, 1: QueueEntry} her active window */
function tcWindow(array $entry = []): array
{
    $shift = Shift::query()->where('status', 'open')->first() ?? Shift::factory()->create();
    $u = tcModerator();
    $m = ShiftMember::factory()->for($shift)->create(['user_id' => $u->id, 'status' => 'busy']);
    $e = QueueEntry::factory()->create($entry + [
        'shift_id' => $shift->id, 'shift_member_id' => $m->id, 'assigned_user_id' => $u->id, 'status' => 'active', 'window_no' => 1,
        'called_at' => now()->subMinutes(5), 'delivered_at' => now()->subMinutes(5),
    ]);
    $e->conversation->update(['assignee_id' => $u->id, 'assigned_at' => now(), 'queue_entry_id' => $e->id, 'handler' => 'human', 'platform' => 'facebook']);

    return [$u, $e->fresh()];
}

const TC_CLOCK_KEYS = ['awaiting_reply_since', 'reply_overdue', 'handoff_left_seconds', 'silence_left_seconds', 'awaiting'];

it('queue/me entries carry the clock fields', function () {
    [$u] = tcWindow(['awaiting_reply_since' => now()->subSeconds(90)]);
    Sanctum::actingAs($u);

    $entry = $this->getJson('/api/v1/queue/me')->assertOk()->json('data.entries.0');

    expect($entry)->toHaveKeys(TC_CLOCK_KEYS)
        ->and($entry['awaiting'])->toBe('agent')
        ->and($entry['awaiting_reply_since'])->toBe(now()->subSeconds(90)->toIso8601String())
        ->and($entry['reply_overdue'])->toBeFalse()
        ->and($entry['silence_left_seconds'])->toBeNull();
});

it('the thread shows «مستنية ردك»: awaiting agent, no silence clock', function () {
    [$u, $e] = tcWindow(['awaiting_reply_since' => now()->subSeconds(45)]);
    Sanctum::actingAs($u);

    $qe = $this->getJson("/api/v1/conversations/{$e->conversation_id}")->assertOk()->json('conversation.queue_entry');

    expect($qe)->toHaveKeys(['id', 'ticket', 'window_no', 'status', ...TC_CLOCK_KEYS])
        ->and($qe['id'])->toBe($e->id)
        ->and($qe['awaiting'])->toBe('agent')
        ->and($qe['awaiting_reply_since'])->toBe(now()->subSeconds(45)->toIso8601String())
        ->and($qe['reply_overdue'])->toBeFalse()
        ->and($qe['silence_left_seconds'])->toBeNull()
        ->and($qe['handoff_left_seconds'])->toBeInt();
});

it('the thread shows «مستنيين ردها · قفل تلقائي بعد»: awaiting customer with the silence countdown', function () {
    [$u, $e] = tcWindow(['awaiting_reply_since' => null, 'last_agent_message_at' => now()->subSeconds(100), 'first_reply_at' => now()->subSeconds(100)]);
    Sanctum::actingAs($u);

    $qe = $this->getJson("/api/v1/conversations/{$e->conversation_id}")->assertOk()->json('conversation.queue_entry');

    expect($qe['awaiting'])->toBe('customer')
        ->and($qe['awaiting_reply_since'])->toBeNull()
        ->and($qe['handoff_left_seconds'])->toBeNull()
        ->and($qe['silence_left_seconds'])->toBe(500);
});

it('the thread marks the overdue window once the apology went out', function () {
    [$u, $e] = tcWindow(['awaiting_reply_since' => now()->subMinutes(4), 'apology_sent_at' => now()->subMinute()]);
    Sanctum::actingAs($u);

    $qe = $this->getJson("/api/v1/conversations/{$e->conversation_id}")->assertOk()->json('conversation.queue_entry');

    expect($qe['reply_overdue'])->toBeTrue()->and($qe['awaiting'])->toBe('agent');
});

it('a supervisor reading a colleague\'s window gets the same clocks', function () {
    [, $e] = tcWindow(['awaiting_reply_since' => now()->subSeconds(30)]);
    $sup = User::factory()->create(['role' => 'supervisor', 'last_seen_at' => now()]);
    Sanctum::actingAs($sup);

    $qe = $this->getJson("/api/v1/conversations/{$e->conversation_id}")->assertOk()->json('conversation.queue_entry');

    expect($qe['awaiting'])->toBe('agent')->and($qe['awaiting_reply_since'])->not->toBeNull();
});

it('a closed ticket keeps queue_entry null and the rest of the thread intact', function () {
    [$u, $e] = tcWindow();
    $e->update(['status' => 'closed', 'closed_at' => now()]);
    Sanctum::actingAs($u);

    $res = $this->getJson("/api/v1/conversations/{$e->conversation_id}")->assertOk();

    expect($res->json('conversation.queue_entry'))->toBeNull()
        ->and($res->json())->toHaveKeys(['conversation', 'messages', 'notes', 'customer', 'participants', 'cases', 'window', 'lock']);
});
