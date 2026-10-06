<?php

use App\Enums\MessageDirection;
use App\Enums\SenderType;
use App\Models\ConversationOutcome;
use App\Models\Message;
use App\Models\Order;
use App\Models\QueueEntry;
use App\Models\QueueSetting;
use App\Models\Shift;
use App\Models\ShiftMember;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Bus;

beforeEach(function () {
    Carbon::setTestNow(Carbon::parse('2026-10-08 12:00', 'Africa/Cairo'));
    QueueSetting::factory()->create(['id' => 1, 'enabled' => true]);
    Bus::fake();
});

/** @return array{0: QueueEntry, 1: User} */
function s3Desk(array $entry = []): array
{
    $shift = Shift::factory()->create();
    $u = User::factory()->create(['role' => 'moderator', 'last_seen_at' => now()]);
    $m = ShiftMember::factory()->for($shift)->create(['user_id' => $u->id, 'status' => 'busy']);
    $e = QueueEntry::factory()->create($entry + ['shift_id' => $shift->id, 'shift_member_id' => $m->id, 'assigned_user_id' => $u->id, 'status' => 'active', 'window_no' => 1, 'called_at' => now(), 'delivered_at' => now()]);
    $e->conversation->update(['assignee_id' => $u->id, 'assigned_at' => now(), 'queue_entry_id' => $e->id, 'handler' => 'human']);
    Message::factory()->for($e->conversation)->create(['direction' => MessageDirection::In, 'sender_type' => SenderType::Customer]);

    return [$e, $u];
}

it('refuses her close without an outcome and keeps the window open', function () {
    [$e, $u] = s3Desk();

    $this->actingAs($u)->postJson("/queue/entries/{$e->id}/close", ['reason' => 'inquiry'])
        ->assertUnprocessable()->assertJsonValidationErrors(['outcome' => __('errors.outcome.required')]);

    expect($e->fresh()->status)->toBe('active')->and(ConversationOutcome::count())->toBe(0);
});

it('closes with the picked outcome', function () {
    [$e, $u] = s3Desk();

    $this->actingAs($u)->postJson("/queue/entries/{$e->id}/close", ['reason' => 'problem', 'outcome' => 'shipping'])->assertOk();

    expect($e->fresh()->status)->toBe('closed')->and(ConversationOutcome::sole()->outcome)->toBe('shipping');
});

it('needs a short note for other and refuses an outcome a person cannot pick', function () {
    [$e, $u] = s3Desk();

    $this->actingAs($u)->postJson("/queue/entries/{$e->id}/close", ['reason' => 'inquiry', 'outcome' => 'other'])
        ->assertUnprocessable()->assertJsonValidationErrors('outcome_note');
    $this->actingAs($u)->postJson("/queue/entries/{$e->id}/close", ['reason' => 'inquiry', 'outcome' => 'ordered'])
        ->assertUnprocessable()->assertJsonValidationErrors('outcome');
    $this->actingAs($u)->postJson("/queue/entries/{$e->id}/close", ['reason' => 'inquiry', 'outcome' => 'other', 'outcome_note' => 'عايزة تقسيط'])->assertOk();

    expect(ConversationOutcome::sole()->note)->toBe('عايزة تقسيط');
});

it('closes without an outcome when it is automatic', function (string $why) {
    [$e, $u] = s3Desk($why === 'service' ? ['bot_summary' => ['category' => 'return', 'lines' => []]] : []);
    if ($why === 'ordered') {
        Order::factory()->create(['conversation_id' => $e->conversation_id, 'customer_id' => $e->conversation->customer_id, 'status' => 'confirmed']);
    }

    $this->actingAs($u)->postJson("/queue/entries/{$e->id}/close", ['reason' => 'inquiry'])->assertOk();

    expect(ConversationOutcome::sole()->outcome)->toBe($why)->and(ConversationOutcome::sole()->source)->toBe('auto');
})->with(['ordered', 'service']);

it('applies the same rule to a supervisor closing on her behalf', function () {
    [$e] = s3Desk();
    $boss = User::factory()->create(['role' => 'supervisor']);

    $this->actingAs($boss)->postJson("/queue/entries/{$e->id}/close", ['reason' => 'inquiry'])->assertUnprocessable();
    $this->actingAs($boss)->postJson("/queue/entries/{$e->id}/close", ['reason' => 'inquiry', 'outcome' => 'browsing'])->assertOk();

    expect(ConversationOutcome::sole()->set_by_id)->toBe($boss->id);
});
