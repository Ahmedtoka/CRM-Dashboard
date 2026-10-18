<?php

use App\Inbox\OutboundService;
use App\Models\ConversationOutcome;
use App\Models\Message;
use App\Models\QueueEntry;
use App\Models\QueueSetting;
use App\Models\ScoreEvent;
use App\Models\Shift;
use App\Models\ShiftMember;
use App\Models\User;
use App\Queue\Jobs\SendClosingMessage;
use App\Queue\WindowLifecycle;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Bus;

// Close cycle §4.5-4.6 and §7.2: «تراجع», the amend after an auto-close (H-09), the revive on her
// reply (H-08) and the claim when a moderator answers a bot chat or a waiting customer.

beforeEach(function () {
    Carbon::setTestNow(Carbon::parse('2026-10-05 12:00', 'Africa/Cairo'));
    QueueSetting::factory()->create(['id' => 1, 'enabled' => true]);
});

function ucWindow(array $attrs = []): array
{
    $shift = Shift::query()->first() ?? Shift::factory()->create();
    $u = User::factory()->create(['role' => 'moderator', 'last_seen_at' => now()]);
    $u->userPlatforms()->create(['platform' => 'facebook']);
    $m = ShiftMember::factory()->for($shift)->create(['user_id' => $u->id, 'status' => 'busy']);
    $e = QueueEntry::factory()->create($attrs + [
        'shift_id' => $shift->id, 'shift_member_id' => $m->id, 'assigned_user_id' => $u->id, 'status' => 'active', 'window_no' => 1,
        'delivered_at' => now()->subMinutes(3), 'enqueued_at' => now()->subMinutes(5), 'first_reply_at' => now()->subMinutes(2),
        'last_agent_message_at' => now()->subMinute(), 'last_customer_message_at' => now()->subMinutes(2),
    ]);
    $e->conversation->update(['assignee_id' => $u->id, 'queue_entry_id' => $e->id, 'handler' => 'human', 'platform' => 'facebook', 'last_customer_message_at' => now()->subMinutes(2)]);

    return [$e->fresh(), $u, $m];
}

function ucFinish(QueueEntry $e, User $u, array $body = [])
{
    return test()->actingAs($u)->postJson("/inbox/conversations/{$e->conversation_id}/finish", $body + ['entry_id' => $e->id, 'outcome' => 'price']);
}

it('undoes «خلصت»: the same window open again, no closing message, no points, no outcome', function () {
    Bus::fake([SendClosingMessage::class]);
    [$e, $u, $m] = ucWindow();
    ucFinish($e, $u)->assertOk();
    expect(ScoreEvent::where('queue_entry_id', $e->id)->where('kind', 'base')->sum('points'))->toBeGreaterThan(0);

    Carbon::setTestNow(now()->addSeconds(5));
    $this->actingAs($u)->postJson("/inbox/conversations/{$e->conversation_id}/finish/undo")->assertOk()->assertJsonPath('data.entry.status', 'active');

    $e->refresh();
    expect($e->status)->toBe('active')->and($e->end_mode)->toBeNull()->and($e->closed_at)->toBeNull()
        ->and($e->conversation->handler->value)->toBe('human')->and($e->conversation->assignee_id)->toBe($u->id)
        ->and($e->conversation->status->value)->toBe('open')
        ->and(ConversationOutcome::where('queue_entry_id', $e->id)->exists())->toBeFalse()
        ->and((int) ScoreEvent::where('queue_entry_id', $e->id)->sum('points'))->toBe(0)
        ->and($m->fresh()->status)->toBe('busy');

    // The queued closing message finds the window open and says nothing.
    $job = Bus::dispatched(SendClosingMessage::class)->first();
    $job->handle(app(OutboundService::class), app(\App\Queue\QueueScripts::class), app(\App\Queue\RatingService::class));
    expect(Message::where('conversation_id', $e->conversation_id)->where('sender_type', 'bot')->count())->toBe(0);
});

it('pays again, once, when the undone window is closed again (RC-4)', function () {
    Bus::fake([SendClosingMessage::class]);
    [$e, $u] = ucWindow();
    ucFinish($e, $u)->assertOk();
    $this->actingAs($u)->postJson("/inbox/conversations/{$e->conversation_id}/finish/undo")->assertOk();
    ucFinish($e, $u)->assertOk();

    $base = ScoreEvent::where('queue_entry_id', $e->id)->where('kind', 'base')->whereNull('reverses_id')->whereNull('reversed_by_id')->get();
    expect($base)->toHaveCount(1)->and($base[0]->unique_key)->toBe('base:'.$e->id.':2');
});

it('refuses the undo after UNDO_SECONDS, after her message, or once the closing message went', function (string $case) {
    Bus::fake([SendClosingMessage::class]);
    [$e, $u] = ucWindow();
    ucFinish($e, $u)->assertOk();

    match ($case) {
        'late' => Carbon::setTestNow(now()->addSeconds(16)),
        'customer' => $e->forceFill(['customer_back_at' => now()])->save(),
        'sent' => $e->forceFill(['closing_claimed_at' => now()])->save(),
    };

    $this->actingAs($u)->postJson("/inbox/conversations/{$e->conversation_id}/finish/undo")->assertStatus(409)->assertJsonPath('code', 'undo_expired');
    expect($e->fresh()->status)->toBe('closed');
})->with(['late', 'customer', 'sent']);

it('writes her outcome on the episode the tick auto-closed a moment ago (H-09)', function () {
    [$e, $u] = ucWindow(['last_agent_message_at' => now()->subMinutes(11), 'last_customer_message_at' => now()->subMinutes(12)]);
    $e->conversation->update(['last_customer_message_at' => now()->subMinutes(12)]);
    app(WindowLifecycle::class)->tickSilence();
    expect($e->fresh()->close_reason)->toBe('auto')
        ->and(ConversationOutcome::where('queue_entry_id', $e->id)->value('outcome'))->toBe('unknown');

    ucFinish($e, $u, ['outcome' => 'size_out'])->assertOk()->assertJsonPath('data.path', 'amend')->assertJsonPath('data.skipped_reason', 'auto_closed');

    expect(ConversationOutcome::where('queue_entry_id', $e->id)->value('outcome'))->toBe('size_out')
        ->and(Message::where('conversation_id', $e->conversation_id)->where('sender_type', 'bot')->count())->toBe(0);
});

it('lets her set the outcome of an auto-closed window later the same day (Q6)', function () {
    [$e, $u] = ucWindow(['last_agent_message_at' => now()->subMinutes(11), 'last_customer_message_at' => now()->subMinutes(12)]);
    $e->conversation->update(['last_customer_message_at' => now()->subMinutes(12)]);
    app(WindowLifecycle::class)->tickSilence();
    Carbon::setTestNow(now()->addHours(2));

    $this->actingAs($u)->getJson('/queue/me/auto-closed')->assertOk()->assertJsonPath('data.0.entry_id', $e->id);
    $this->actingAs($u)->postJson("/inbox/conversations/{$e->conversation_id}/outcome", ['outcome' => 'shipping'])->assertOk();
    $this->actingAs($u)->postJson("/inbox/conversations/{$e->conversation_id}/outcome", ['outcome' => 'price'])->assertStatus(409);

    expect(ConversationOutcome::where('queue_entry_id', $e->id)->value('outcome'))->toBe('shipping');
});

it('takes the window back when the assignee replies seconds after the auto-close (H-08)', function () {
    [$e, $u] = ucWindow(['last_agent_message_at' => now()->subMinutes(11), 'last_customer_message_at' => now()->subMinutes(12)]);
    $e->conversation->update(['last_customer_message_at' => now()->subMinutes(12), 'last_message_at' => now()]);
    app(WindowLifecycle::class)->tickSilence();
    Carbon::setTestNow(now()->addSeconds(40));

    app(OutboundService::class)->sendHuman($e->conversation->fresh(), $u, 'لسه معاكي، بشوفلك المقاس');

    expect($e->fresh()->status)->toBe('active')->and(QueueEntry::where('conversation_id', $e->conversation_id)->count())->toBe(1)
        ->and($e->conversation->fresh()->handler->value)->toBe('human');
});

it('claims a bot chat into a quiet manual window when a moderator on shift answers it (§7.2)', function () {
    [$e, $u] = ucWindow();
    $e->forceFill(['status' => 'closed', 'close_reason' => 'inquiry', 'end_mode' => 'thanks', 'closed_at' => now()->subHours(3)])->save();
    $c = $e->conversation;
    $c->update(['handler' => 'bot', 'queue_entry_id' => $e->id, 'assignee_id' => null, 'last_customer_message_at' => now()->subMinute()]);

    app(OutboundService::class)->sendHuman($c->fresh(), $u, 'أهلاً يا فندم، أقدر أساعدك؟');

    $claim = QueueEntry::where('conversation_id', $c->id)->latest('id')->first();
    expect($claim->id)->not->toBe($e->id)->and($claim->status)->toBe('active')->and($claim->priority)->toBe('manual')->and($claim->rule)->toBe('claim')
        ->and($claim->assigned_user_id)->toBe($u->id)->and($c->fresh()->queue_entry_id)->toBe($claim->id)
        ->and(Message::where('conversation_id', $c->id)->where('sender_type', 'bot')->count())->toBe(0);

    // A second reply is the same window.
    app(OutboundService::class)->sendHuman($c->fresh(), $u, 'تحت أمرك');
    expect(QueueEntry::where('conversation_id', $c->id)->count())->toBe(2);
});

it('earns no speed bonus and no base on a claim the customer never answered (RC-8)', function () {
    [$e, $u] = ucWindow();
    $e->forceFill(['status' => 'closed', 'close_reason' => 'inquiry', 'end_mode' => 'thanks', 'closed_at' => now()->subHours(3)])->save();
    $c = $e->conversation;
    $c->update(['handler' => 'bot', 'assignee_id' => null, 'last_customer_message_at' => now()->subMinute()]);
    app(OutboundService::class)->sendHuman($c->fresh(), $u, 'أهلاً');
    $claim = QueueEntry::where('conversation_id', $c->id)->latest('id')->first();

    ucFinish($claim, $u)->assertOk();

    expect(ScoreEvent::where('queue_entry_id', $claim->id)->count())->toBe(0);
});

it('gives a waiting customer to the moderator who answered her, with no «دورك جه» (H-10)', function () {
    [, $u] = ucWindow();
    $w = QueueEntry::factory()->create(['status' => 'waiting', 'priority' => 'live', 'enqueued_at' => now()->subMinute()]);
    $w->conversation->update(['handler' => 'human', 'queue_entry_id' => $w->id, 'platform' => 'facebook', 'last_customer_message_at' => now()]);

    app(OutboundService::class)->sendHuman($w->conversation->fresh(), $u, 'معاكي');

    $w->refresh();
    expect($w->status)->toBe('active')->and($w->assigned_user_id)->toBe($u->id)->and($w->awaiting_reply_since)->toBeNull()
        ->and(Message::where('conversation_id', $w->conversation_id)->where('sender_type', 'bot')->count())->toBe(0);
});
