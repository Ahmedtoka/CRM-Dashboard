<?php

use App\Models\Message;
use App\Models\QueueEntry;
use App\Models\QueueSetting;
use App\Models\Shift;
use App\Models\ShiftMember;
use App\Models\SupportCase;
use App\Models\User;
use App\Queue\WindowLifecycle;
use Illuminate\Support\Carbon;

// Close-cycle hotfixes C1 #4, #5, #7 (docs/round-2026-10-17/close-cycle.md §5.5).

const CH_THANKS = 'سعدنا بخدمتك يا فندم، لو احتجتي أي حاجة تانية ابعتيلنا في أي وقت.';

beforeEach(function () {
    Carbon::setTestNow(Carbon::parse('2026-10-05 12:00', 'Africa/Cairo'));
    QueueSetting::factory()->create(['id' => 1, 'enabled' => true]);
});

/** An open window of a logged-in moderator on the open shift; the customer just wrote. */
function chWindow(array $attrs = []): array
{
    $shift = Shift::query()->first() ?? Shift::factory()->create();
    $u = User::factory()->create(['role' => 'moderator', 'last_seen_at' => now()]);
    $u->userPlatforms()->create(['platform' => 'facebook']);
    $m = ShiftMember::factory()->for($shift)->create(['user_id' => $u->id, 'status' => 'busy']);
    $e = QueueEntry::factory()->create($attrs + [
        'shift_id' => $shift->id, 'shift_member_id' => $m->id, 'assigned_user_id' => $u->id, 'status' => 'active', 'window_no' => 1,
        'delivered_at' => now(), 'enqueued_at' => now()->subMinutes(2), 'last_customer_message_at' => now(),
    ]);
    $e->conversation->update(['assignee_id' => $u->id, 'queue_entry_id' => $e->id, 'handler' => 'human', 'needs_human' => true, 'last_customer_message_at' => now(), 'platform' => 'facebook']);

    return [$e->fresh(), $u, $m];
}

function chBotTexts(QueueEntry $e): array
{
    return Message::query()->where('conversation_id', $e->conversation_id)->where('sender_type', 'bot')->orderBy('id')->pluck('body')->all();
}

// ───── #4 (D-21): no second case for the same problem ─────

it('links her open case on a «كيس» close instead of opening a second one', function () {
    [$e, $u] = chWindow();
    $open = SupportCase::factory()->create(['conversation_id' => $e->conversation_id, 'customer_id' => $e->customer_id, 'type' => 'return', 'queue_entry_id' => null]);
    $e->forceFill(['open_case_id' => $open->id])->save();

    app(WindowLifecycle::class)->close($e->fresh(), 'case', $u, ['case_type' => 'complaint']);

    expect(SupportCase::count())->toBe(1)
        ->and($e->fresh()->support_case_id)->toBe($open->id)
        ->and($e->fresh()->close_reason)->toBe('case')
        ->and(chBotTexts($e))->toBe([CH_THANKS]);
});

it('links her open case found on her customer record, opened after she was queued', function () {
    [$e, $u] = chWindow();
    $open = SupportCase::factory()->create(['customer_id' => $e->conversation->customer_id, 'status' => 'in_progress']);

    app(WindowLifecycle::class)->close($e, 'case', $u, ['case_type' => 'complaint']);

    expect(SupportCase::count())->toBe(1)->and($e->fresh()->support_case_id)->toBe($open->id);
});

it('still opens a new case when her earlier one is resolved or closed', function () {
    [$e, $u] = chWindow();
    SupportCase::factory()->create(['customer_id' => $e->conversation->customer_id, 'status' => 'resolved', 'resolved_at' => now()->subDay()]);
    SupportCase::factory()->create(['customer_id' => $e->conversation->customer_id, 'status' => 'closed']);

    app(WindowLifecycle::class)->close($e, 'case', $u, ['case_type' => 'complaint']);

    $new = SupportCase::query()->latest('id')->first();
    expect(SupportCase::count())->toBe(3)->and($e->fresh()->support_case_id)->toBe($new->id)
        ->and(chBotTexts($e)[0])->toContain('فتحنالك طلب رقم '.$new->id);
});
