<?php

use App\Models\ConversationOutcome;
use App\Models\Message;
use App\Models\QueueEntry;
use App\Models\QueueSetting;
use App\Models\ScoreEvent;
use App\Models\Shift;
use App\Models\ShiftMember;
use App\Models\SupportCase;
use App\Models\User;
use App\Queue\Jobs\SendClosingMessage;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Bus;

// Close cycle §4-5 (docs/round-2026-10-17/close-cycle-FINAL.md): one «خلصت».

const FN_THANKS = 'سعدنا بخدمتك يا فندم، لو احتجتي أي حاجة تانية ابعتيلنا في أي وقت.';
const FN_ASK = 'ممكن تقيّمي خدمتنا من 1 لـ 5؟ (5 = ممتازة)';

beforeEach(function () {
    Carbon::setTestNow(Carbon::parse('2026-10-05 12:00', 'Africa/Cairo'));
    QueueSetting::factory()->create(['id' => 1, 'enabled' => true]);
});

/** An open window her moderator answered a minute ago; the customer is not waiting. */
function fnWindow(array $attrs = []): array
{
    $shift = Shift::query()->first() ?? Shift::factory()->create();
    $u = User::factory()->create(['role' => 'moderator', 'last_seen_at' => now()]);
    $u->userPlatforms()->create(['platform' => 'facebook']);
    $m = ShiftMember::factory()->for($shift)->create(['user_id' => $u->id, 'status' => 'busy']);
    $e = QueueEntry::factory()->create($attrs + [
        'shift_id' => $shift->id, 'shift_member_id' => $m->id, 'assigned_user_id' => $u->id, 'status' => 'active', 'window_no' => 1,
        'delivered_at' => now()->subMinutes(3), 'enqueued_at' => now()->subMinutes(5), 'first_reply_at' => now()->subMinutes(2),
        'last_agent_message_at' => now()->subMinute(), 'last_customer_message_at' => now()->subMinutes(2), 'awaiting_reply_since' => null,
    ]);
    $e->conversation->update(['assignee_id' => $u->id, 'queue_entry_id' => $e->id, 'handler' => 'human', 'needs_human' => false,
        'last_customer_message_at' => now()->subMinutes(2), 'platform' => 'facebook']);

    return [$e->fresh(), $u, $m];
}

function fnBot(QueueEntry $e): array
{
    return Message::query()->where('conversation_id', $e->conversation_id)->where('sender_type', 'bot')->orderBy('id')->get(['body', 'buttons'])->all();
}

it('closes her window with one closing message that carries the rating buttons (TC-01)', function () {
    [$e, $u] = fnWindow();

    $res = $this->actingAs($u)->postJson("/inbox/conversations/{$e->conversation_id}/finish", ['entry_id' => $e->id, 'outcome' => 'price'])
        ->assertOk()->assertJsonPath('data.end_mode', 'thanks')->assertJsonPath('data.closing', 'queued');

    $e->refresh();
    $c = $e->conversation;
    $bot = fnBot($e);
    expect($e->status)->toBe('closed')->and($e->close_reason)->toBe('inquiry')->and($e->end_mode)->toBe('thanks')
        ->and($c->handler->value)->toBe('bot')->and($c->status->value)->toBe('resolved')
        ->and($c->return_priority_until?->toIso8601String())->toBe(now()->addMinutes(30)->toIso8601String())
        ->and($bot)->toHaveCount(1)
        ->and($bot[0]->body)->toBe(FN_THANKS."\n\n".FN_ASK)
        ->and(collect($bot[0]->buttons)->pluck('title')->all())->toBe(['1', '2', '3', '4', '5'])
        ->and($e->review_requested_at)->not->toBeNull()
        ->and(ConversationOutcome::where('conversation_id', $e->conversation_id)->value('outcome'))->toBe('price')
        ->and($res->json('data.undo_until'))->not->toBeNull();
});

it('sends the thanks alone when the window lasted under 30 seconds or she was never answered (Q2)', function (array $attrs) {
    [$e, $u] = fnWindow($attrs);

    $this->actingAs($u)->postJson("/inbox/conversations/{$e->conversation_id}/finish", ['entry_id' => $e->id, 'outcome' => 'price'])->assertOk();

    expect(collect(fnBot($e))->pluck('body')->all())->toBe([FN_THANKS])->and($e->fresh()->review_requested_at)->toBeNull();
})->with([
    'fast' => [['delivered_at' => now()->subSeconds(10)]],
    'never answered' => [['first_reply_at' => null]],
]);

it('refuses with no outcome and nothing automatic, and changes nothing (TC-02)', function () {
    [$e, $u] = fnWindow();

    $this->actingAs($u)->postJson("/inbox/conversations/{$e->conversation_id}/finish", ['entry_id' => $e->id])
        ->assertUnprocessable()->assertJsonValidationErrors('outcome');

    expect($e->fresh()->status)->toBe('active');
});

it('warns while she waits for a reply, and closes with no message once confirmed (TC-03)', function () {
    [$e, $u] = fnWindow(['awaiting_reply_since' => now()->subSeconds(10)]);
    $e->conversation->messages()->create(['platform' => 'facebook', 'direction' => 'in', 'sender_type' => 'customer', 'body' => 'طب والمقاس L موجود؟', 'status' => 'received']);

    $this->actingAs($u)->postJson("/inbox/conversations/{$e->conversation_id}/finish", ['entry_id' => $e->id, 'outcome' => 'price'])
        ->assertStatus(409)->assertJsonPath('code', 'customer_waiting')->assertJsonPath('last_message', 'طب والمقاس L موجود؟');
    expect($e->fresh()->status)->toBe('active');

    $this->actingAs($u)->postJson("/inbox/conversations/{$e->conversation_id}/finish", ['entry_id' => $e->id, 'outcome' => 'price', 'confirm_waiting' => true])
        ->assertOk()->assertJsonPath('data.closing', 'skipped');

    expect($e->fresh()->status)->toBe('closed')->and(fnBot($e))->toBe([])->and($e->fresh()->closing_skipped_reason)->toBe('waiting');
});

it('sends the thanks anyway when she chose to while the customer waits', function () {
    [$e, $u] = fnWindow(['awaiting_reply_since' => now()->subSeconds(10)]);

    $this->actingAs($u)->postJson("/inbox/conversations/{$e->conversation_id}/finish", ['entry_id' => $e->id, 'outcome' => 'price', 'confirm_waiting' => true, 'send_closing' => true])->assertOk();

    expect(fnBot($e))->toHaveCount(1);
});

it('hands her to the bot with no message and no return window (TC-12)', function () {
    [$e, $u] = fnWindow();

    $this->actingAs($u)->postJson("/inbox/conversations/{$e->conversation_id}/finish", ['entry_id' => $e->id, 'outcome' => 'browsing', 'after' => 'bot'])
        ->assertOk()->assertJsonPath('data.end_mode', 'bot');

    $e->refresh();
    expect($e->end_mode)->toBe('bot')->and($e->close_reason)->toBe('inquiry')->and(fnBot($e))->toBe([])
        ->and($e->conversation->handler->value)->toBe('bot')->and($e->conversation->return_priority_until)->toBeNull()
        ->and(ScoreEvent::where('queue_entry_id', $e->id)->where('kind', 'base')->exists())->toBeTrue();
});

it('counts a service chat as a problem (D-15)', function () {
    [$e, $u] = fnWindow();

    $this->actingAs($u)->postJson("/inbox/conversations/{$e->conversation_id}/finish", ['entry_id' => $e->id, 'outcome' => 'service'])->assertOk();

    expect($e->fresh()->close_reason)->toBe('problem');
});

it('refuses a colleague\'s window to a moderator, and lets a supervisor close it for her', function () {
    [$e] = fnWindow();
    $other = User::factory()->create(['role' => 'moderator']);
    $other->userPlatforms()->create(['platform' => 'facebook']);
    $boss = User::factory()->create(['role' => 'supervisor']);

    $this->actingAs($other)->postJson("/inbox/conversations/{$e->conversation_id}/finish", ['entry_id' => $e->id, 'outcome' => 'price'])
        ->assertForbidden();
    $this->actingAs($boss)->postJson("/inbox/conversations/{$e->conversation_id}/finish", ['entry_id' => $e->id, 'outcome' => 'price'])->assertOk();

    expect($e->fresh()->closed_by_id)->toBe($boss->id)
        ->and(ScoreEvent::where('queue_entry_id', $e->id)->where('kind', 'base')->value('user_id'))->toBe($e->assigned_user_id)
        ->and(fnBot($e))->toHaveCount(1);
});

it('refuses a stale «خلصت» on a request she made since (RC-1)', function () {
    [$e, $u] = fnWindow();
    $e->forceFill(['status' => 'closed', 'close_reason' => 'auto', 'closed_at' => now()->subMinutes(5)])->save();
    $new = QueueEntry::factory()->create(['conversation_id' => $e->conversation_id, 'status' => 'waiting', 'priority' => 'returning', 'ticket_no' => 77]);

    $this->actingAs($u)->postJson("/inbox/conversations/{$e->conversation_id}/finish", ['entry_id' => $e->id, 'outcome' => 'price'])
        ->assertStatus(409)->assertJsonPath('code', 'stale_state');

    expect($new->fresh()->status)->toBe('waiting');
});

it('takes a customer out of the lounge with the short message, back to the bot (Q15)', function () {
    $u = User::factory()->create(['role' => 'moderator']);
    $u->userPlatforms()->create(['platform' => 'facebook']);
    $e = QueueEntry::factory()->create(['status' => 'waiting', 'priority' => 'live']);
    $e->conversation->update(['handler' => 'human', 'needs_human' => true, 'queue_entry_id' => $e->id, 'platform' => 'facebook', 'last_customer_message_at' => now()]);

    $this->actingAs($u)->postJson("/inbox/conversations/{$e->conversation_id}/finish", ['entry_id' => $e->id, 'outcome' => 'browsing'])
        ->assertOk()->assertJsonPath('data.path', 'lounge');

    expect($e->fresh()->status)->toBe('cancelled')->and($e->conversation->fresh()->handler->value)->toBe('bot')
        ->and(collect(fnBot($e))->pluck('body')->all())->toBe(['لغينا تذكرتك يا فندم، ابعتيلنا في أي وقت لو احتجتي حاجة.']);
});

it('ends a chat a person holds with no window: thanks alone, back to the bot (Q14)', function () {
    QueueSetting::query()->update(['enabled' => false]);
    $u = User::factory()->create(['role' => 'moderator']);
    $u->userPlatforms()->create(['platform' => 'facebook']);
    $e = QueueEntry::factory()->create();
    $c = $e->conversation;
    $e->delete();
    $c->update(['handler' => 'human', 'platform' => 'facebook', 'last_customer_message_at' => now(), 'queue_entry_id' => null]);
    $c->messages()->create(['platform' => 'facebook', 'direction' => 'in', 'sender_type' => 'customer', 'body' => 'عايزة الفستان', 'status' => 'received', 'created_at' => now()->subMinutes(3)]);
    $c->messages()->create(['platform' => 'facebook', 'direction' => 'out', 'sender_type' => 'user', 'user_id' => $u->id, 'body' => 'اتفضلي', 'status' => 'sent', 'created_at' => now()->subMinutes(2)]);

    $this->actingAs($u)->postJson("/inbox/conversations/{$c->id}/finish", ['entry_id' => null, 'outcome' => 'price'])
        ->assertOk()->assertJsonPath('data.path', 'none')->assertJsonPath('data.closing', 'queued');

    $c->refresh();
    expect($c->handler->value)->toBe('bot')->and($c->status->value)->toBe('resolved')
        ->and(Message::where('conversation_id', $c->id)->where('sender_type', 'bot')->pluck('body')->all())->toBe([FN_THANKS]);
    unset($e);
});

it('puts the case number inside the one closing message', function () {
    [$e, $u] = fnWindow();

    $this->actingAs($u)->postJson("/inbox/conversations/{$e->conversation_id}/finish", ['entry_id' => $e->id, 'outcome' => 'service', 'open_case' => 'complaint'])->assertOk();

    $case = SupportCase::query()->latest('id')->first();
    $bot = collect(fnBot($e))->pluck('body')->all();
    expect($e->fresh()->close_reason)->toBe('case')->and($bot)->toHaveCount(1)
        ->and($bot[0])->toContain('فتحنالك طلب رقم '.$case->id)->and($bot[0])->toEndWith(FN_THANKS);
});

it('refuses a second case when one is open (case_exists)', function () {
    [$e, $u] = fnWindow();
    $open = SupportCase::factory()->create(['customer_id' => $e->conversation->customer_id, 'status' => 'new']);

    $this->actingAs($u)->postJson("/inbox/conversations/{$e->conversation_id}/finish", ['entry_id' => $e->id, 'outcome' => 'service', 'open_case' => 'complaint'])
        ->assertStatus(422)->assertJsonPath('code', 'case_exists')->assertJsonPath('case_id', $open->id);
});

it('waits DELAY_SECONDS before the closing message', function () {
    Bus::fake([SendClosingMessage::class]);
    [$e, $u] = fnWindow();

    $this->actingAs($u)->postJson("/inbox/conversations/{$e->conversation_id}/finish", ['entry_id' => $e->id, 'outcome' => 'price'])->assertOk();

    Bus::assertDispatched(SendClosingMessage::class, fn (SendClosingMessage $j) => $j->entryId === $e->id
        && $j->delay !== null && (int) now()->diffInSeconds($j->delay) === SendClosingMessage::DELAY_SECONDS);
});

it('serves the same «خلصت» on the API', function () {
    [$e, $u] = fnWindow();
    \Laravel\Sanctum\Sanctum::actingAs($u);

    $this->postJson("/api/v1/conversations/{$e->conversation_id}/finish", ['entry_id' => $e->id, 'outcome' => 'price'])
        ->assertOk()->assertJsonPath('data.entry.close_reason', 'inquiry');
});
