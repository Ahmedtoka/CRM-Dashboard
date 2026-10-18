<?php

use App\Http\Resources\QueueEntryResource;
use App\Http\Resources\ShiftMemberResource;
use App\Models\ConversationOutcome;
use App\Models\Message;
use App\Models\QueueEntry;
use App\Models\QueueSetting;
use App\Models\ScoreEvent;
use App\Models\Shift;
use App\Models\ShiftMember;
use App\Models\SupportCase;
use App\Models\User;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\Sanctum;

/*
 * Close cycle §5.2: the installed moderator app (feature/moderator-app) keeps calling
 * queue/entries/{id}/close, conversations/{id}/resolve, return-to-bot and reopen. They now run
 * through ConversationCycle and must answer exactly as before; the desk counters
 * (ShiftMemberResource.today, by close_reason) still compute.
 */

beforeEach(function () {
    Carbon::setTestNow(Carbon::parse('2026-10-05 12:00', 'Africa/Cairo'));
    QueueSetting::factory()->create(['id' => 1, 'enabled' => true]);
});

/** @return array{0: User, 1: ShiftMember, 2: QueueEntry} */
function cwDesk(array $entry = [], ?User $u = null): array
{
    $shift = Shift::query()->where('status', 'open')->first() ?? Shift::factory()->create();
    $u ??= User::factory()->create(['role' => 'moderator', 'last_seen_at' => now()]);
    if (! $u->userPlatforms()->exists()) {
        $u->userPlatforms()->create(['platform' => 'facebook']);
    }
    $m = ShiftMember::query()->where('user_id', $u->id)->first() ?? ShiftMember::factory()->for($shift)->create(['user_id' => $u->id, 'status' => 'busy']);
    $e = QueueEntry::factory()->create($entry + [
        'shift_id' => $shift->id, 'shift_member_id' => $m->id, 'assigned_user_id' => $u->id, 'status' => 'active', 'window_no' => 1,
        'called_at' => now()->subMinutes(3), 'delivered_at' => now()->subMinutes(3), 'first_reply_at' => now()->subMinutes(2), 'last_agent_message_at' => now()->subMinute(),
    ]);
    $e->conversation->update(['assignee_id' => $u->id, 'assigned_at' => now(), 'queue_entry_id' => $e->id, 'handler' => 'human', 'platform' => 'facebook', 'last_customer_message_at' => now()->subMinutes(2)]);

    return [$u, $m, $e->fresh()];
}

function cwBotCount(QueueEntry $e): int
{
    return Message::query()->where('conversation_id', $e->conversation_id)->where('sender_type', 'bot')->count();
}

it('answers the old close with exactly the old keys', function () {
    [$u, , $e] = cwDesk();
    Sanctum::actingAs($u);

    $res = $this->postJson("/api/v1/queue/entries/{$e->id}/close", ['reason' => 'problem', 'outcome' => 'price'])->assertOk();

    $expected = array_values(array_unique(array_merge(array_keys(QueueEntryResource::data($e->fresh()->load('conversation.customer'))), ['close_reason', 'support_case_id'])));
    expect(array_keys($res->json('data')))->toEqualCanonicalizing($expected)
        ->and($res->json('data.close_reason'))->toBe('problem')->and($res->json('data.status'))->toBe('closed')
        ->and($e->fresh()->end_mode)->toBe('thanks')->and(cwBotCount($e))->toBe(1);
});

it('links her open case on the old «كيس» close, same shape (C1)', function () {
    [$u, , $e] = cwDesk();
    $open = SupportCase::factory()->create(['customer_id' => $e->conversation->customer_id, 'status' => 'new']);
    Sanctum::actingAs($u);

    $this->postJson("/api/v1/queue/entries/{$e->id}/close", ['reason' => 'case', 'case_type' => 'complaint', 'outcome' => 'service'])
        ->assertOk()->assertJsonPath('data.close_reason', 'case')->assertJsonPath('data.support_case_id', $open->id);

    expect(SupportCase::count())->toBe(1);
});

it('still counts the desk by close reason after every old endpoint', function () {
    [$u, $m, $e1] = cwDesk();
    Sanctum::actingAs($u);
    $this->postJson("/api/v1/queue/entries/{$e1->id}/close", ['reason' => 'inquiry', 'outcome' => 'price'])->assertOk();
    [, , $e2] = cwDesk(['window_no' => 2], $u);
    $this->postJson("/api/v1/queue/entries/{$e2->id}/close", ['reason' => 'problem', 'outcome' => 'service'])->assertOk();
    [, , $e3] = cwDesk(['window_no' => 3], $u);
    $this->postJson("/api/v1/queue/entries/{$e3->id}/close", ['reason' => 'case', 'case_type' => 'complaint', 'outcome' => 'service'])->assertOk();
    [, , $e4] = cwDesk(['window_no' => 4], $u);
    $this->postJson("/api/v1/conversations/{$e4->conversation_id}/return-to-bot")->assertOk();
    [, , $e5] = cwDesk(['window_no' => 5], $u);
    $this->postJson("/api/v1/conversations/{$e5->conversation_id}/resolve")->assertOk();

    $today = (new ShiftMemberResource($m->fresh()))->resolve()['today'];
    expect($today['inquiry'])->toBe(3)->and($today['problem'])->toBe(1)->and($today['case'])->toBe(1);
});

it('turns the app\'s «رجوع للبوت» on a window into a real close: outcome, points, no message (D-10)', function () {
    [$u, , $e] = cwDesk();
    Sanctum::actingAs($u);

    $res = $this->postJson("/api/v1/conversations/{$e->conversation_id}/return-to-bot")->assertOk();

    $e->refresh();
    expect($res->json('data.id'))->toBe($e->conversation_id)
        ->and($e->close_reason)->toBe('inquiry')->and($e->end_mode)->toBe('bot')
        ->and(ConversationOutcome::where('queue_entry_id', $e->id)->value('outcome'))->toBe('unknown')
        ->and(ScoreEvent::where('queue_entry_id', $e->id)->where('kind', 'base')->exists())->toBeTrue()
        ->and(cwBotCount($e))->toBe(0)->and($e->conversation->handler->value)->toBe('bot');
});

it('turns a supervisor\'s «حل» on a colleague\'s window into her close with the thanks (D-11)', function () {
    [, , $e] = cwDesk();
    $boss = User::factory()->create(['role' => 'supervisor']);
    Sanctum::actingAs($boss);

    $this->postJson("/api/v1/conversations/{$e->conversation_id}/resolve", ['outcome' => 'price'])->assertOk()->assertJsonPath('data.status', 'resolved');

    $e->refresh();
    expect($e->closing_skipped_reason)->toBeNull()->and($e->close_reason)->toBe('inquiry')->and($e->closed_by_id)->toBe($boss->id)->and(cwBotCount($e))->toBe(1)
        ->and(ScoreEvent::where('queue_entry_id', $e->id)->where('kind', 'base')->value('user_id'))->toBe($e->assigned_user_id);
});

it('records unknown for the app\'s «حل» with no body (D16)', function () {
    [$u, , $e] = cwDesk();
    Sanctum::actingAs($u);

    $this->postJson("/api/v1/conversations/{$e->conversation_id}/resolve")->assertOk();

    expect(ConversationOutcome::where('queue_entry_id', $e->id)->value('outcome'))->toBe('unknown');
});

it('closes a waiting customer\'s window from the app without the closing message', function () {
    [$u, , $e] = cwDesk(['awaiting_reply_since' => now()->subSeconds(20)]);
    Sanctum::actingAs($u);

    $this->postJson("/api/v1/queue/entries/{$e->id}/close", ['reason' => 'inquiry', 'outcome' => 'price'])->assertOk();

    expect($e->fresh()->status)->toBe('closed')->and(cwBotCount($e))->toBe(0)->and($e->fresh()->closing_skipped_reason)->toBe('waiting');
});

it('keeps window_not_open for the app on a window the tick just auto-closed (RC-11)', function () {
    [$u, , $e] = cwDesk();
    $e->forceFill(['status' => 'closed', 'close_reason' => 'auto', 'closed_at' => now()->subSeconds(5)])->save();
    Sanctum::actingAs($u);

    $this->postJson("/api/v1/queue/entries/{$e->id}/close", ['reason' => 'inquiry', 'outcome' => 'price'])
        ->assertStatus(409)->assertJsonPath('message', __('errors.queue.window_not_open'));
});

it('keeps the old resolve on a chat with no window: silent, as before (H-18)', function () {
    [$u, , $e] = cwDesk();
    $e->forceFill(['status' => 'closed', 'close_reason' => 'inquiry', 'closed_at' => now()->subHour()])->save();
    $e->conversation->update(['handler' => 'human']);
    Sanctum::actingAs($u);

    $this->postJson("/api/v1/conversations/{$e->conversation_id}/resolve")->assertOk()->assertJsonPath('data.status', 'resolved');

    expect(cwBotCount($e))->toBe(0);
});

it('keeps reopen with its body, marked deprecated', function () {
    [$u, , $e] = cwDesk();
    $e->conversation->update(['status' => 'resolved']);
    Sanctum::actingAs($u);

    $this->postJson("/api/v1/conversations/{$e->conversation_id}/reopen")->assertOk()
        ->assertHeader('Deprecation', 'true')->assertJsonPath('data.status', 'open');
});
