<?php

use App\Enums\ConversationStatus;
use App\Enums\Handler;
use App\Enums\MessageDirection;
use App\Enums\SenderType;
use App\Enums\UserRole;
use App\Models\ChannelAccount;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\QueueEntry;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/*
 * UI overhaul Task 5: `queue_state` on every list row — the one state badge per row reads it.
 * Waiting / called / active entries carry it; terminal entries do not; `overdue` follows
 * QueueEntryResource::reply_overdue. It must not cost a query per row.
 */

beforeEach(function () {
    $this->admin = User::factory()->create(['role' => UserRole::Admin]);
    $this->acc = ChannelAccount::factory()->create();
});

function qsConv(array $entry = [], bool $withEntry = true): Conversation
{
    $c = Conversation::factory()->for(test()->acc, 'channelAccount')
        ->create(['status' => ConversationStatus::Open, 'handler' => Handler::Human, 'last_message_at' => now()]);
    if ($withEntry) {
        $e = QueueEntry::factory()->create(array_merge(['conversation_id' => $c->id], $entry));
        $c->forceFill(['queue_entry_id' => $e->id])->save();
    }

    return $c;
}

function qsRow(int $id): ?array
{
    $rows = collect(test()->actingAs(test()->admin)->getJson('/inbox/conversations')->assertOk()->json('data'));

    return $rows->firstWhere('id', $id);
}

it('carries the ticket state for waiting, called and active entries', function () {
    $mod = User::factory()->create(['role' => UserRole::Moderator]);
    $waiting = qsConv(['status' => 'waiting', 'ticket_no' => 12, 'priority' => 'returning']);
    $called = qsConv(['status' => 'called', 'ticket_no' => 13, 'assigned_user_id' => $mod->id]);
    $active = qsConv(['status' => 'active', 'ticket_no' => 14, 'assigned_user_id' => $mod->id]);

    expect(qsRow($waiting->id)['queue_state'])->toBe([
        'status' => 'waiting', 'ticket' => 12, 'priority' => 'returning', 'overdue' => false, 'assigned_user_id' => null,
    ]);
    expect(qsRow($called->id)['queue_state'])->toMatchArray(['status' => 'called', 'ticket' => 13, 'assigned_user_id' => $mod->id]);
    expect(qsRow($active->id)['queue_state'])->toMatchArray(['status' => 'active', 'ticket' => 14, 'overdue' => false]);

    // The existing open-only key is unchanged: a waiting ticket has no queue_entry.
    expect(qsRow($waiting->id)['queue_entry'])->toBeNull();
    expect(qsRow($active->id)['queue_entry'])->not->toBeNull();
});

it('is null for a terminal entry or no entry', function () {
    foreach (['closed', 'abandoned', 'cancelled'] as $status) {
        $c = qsConv(['status' => $status]);
        expect(qsRow($c->id)['queue_state'])->toBeNull();
    }
    $none = qsConv(withEntry: false);
    expect(qsRow($none->id)['queue_state'])->toBeNull();
});

it('marks overdue only with both the awaiting-reply and the apology timestamps', function () {
    $both = qsConv(['status' => 'active', 'awaiting_reply_since' => now()->subMinutes(9), 'apology_sent_at' => now()->subMinute()]);
    $awaitOnly = qsConv(['status' => 'active', 'awaiting_reply_since' => now()->subMinutes(9)]);
    $apologyOnly = qsConv(['status' => 'called', 'apology_sent_at' => now()->subMinute()]);
    // A waiting ticket is never "reply overdue" (same rule as reply_overdue: open entries only).
    $waiting = qsConv(['status' => 'waiting', 'awaiting_reply_since' => now()->subMinutes(9), 'apology_sent_at' => now()->subMinute()]);

    expect(qsRow($both->id)['queue_state']['overdue'])->toBeTrue();
    expect(qsRow($awaitOnly->id)['queue_state']['overdue'])->toBeFalse();
    expect(qsRow($apologyOnly->id)['queue_state']['overdue'])->toBeFalse();
    expect(qsRow($waiting->id)['queue_state']['overdue'])->toBeFalse();
});

it('does not add a query per row', function () {
    $make = fn (int $n) => collect(range(1, $n))->each(fn () => qsConv(['status' => fake()->randomElement(['waiting', 'called', 'active'])]));

    $make(3);
    // Warm up the one-time session/presence middleware queries.
    $this->actingAs($this->admin)->getJson('/inbox/conversations')->assertOk();

    DB::enableQueryLog();
    $this->actingAs($this->admin)->getJson('/inbox/conversations')->assertOk()->assertJsonCount(3, 'data');
    $withThree = count(DB::getQueryLog());

    $make(27);
    DB::flushQueryLog();
    $this->actingAs($this->admin)->getJson('/inbox/conversations')->assertOk()->assertJsonCount(30, 'data');

    expect(count(DB::getQueryLog()))->toBe($withThree);
});

it('says who wrote the preview, for the row\'s «إنتي: » prefix', function () {
    $mine = qsConv(withEntry: false);
    Message::factory()->create(['conversation_id' => $mine->id, 'direction' => MessageDirection::Out, 'sender_type' => SenderType::User]);
    $bot = qsConv(withEntry: false);
    Message::factory()->create(['conversation_id' => $bot->id, 'direction' => MessageDirection::Out, 'sender_type' => SenderType::Bot]);

    expect(qsRow($mine->id)['last_message_sender'])->toBe('user');
    expect(qsRow($bot->id)['last_message_sender'])->toBe('bot');
});
