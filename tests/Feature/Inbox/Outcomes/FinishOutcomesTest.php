<?php

use App\Enums\MessageDirection;
use App\Enums\SenderType;
use App\Models\ConversationOutcome;
use App\Models\Message;
use App\Models\QueueEntry;
use App\Models\QueueSetting;
use App\Models\Shift;
use App\Models\ShiftMember;
use App\Models\SupportCase;
use App\Models\User;
use App\Queue\Jobs\SendClosingMessage;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Bus;

// finish-api.md §3 (2026-10-24): one pick says what happened; each outcome decides the message, the case and what is recorded.

const FO_THANKS = 'سعدنا بخدمتك يا فندم، لو احتجتي أي حاجة تانية ابعتيلنا في أي وقت.';

beforeEach(function () {
    Carbon::setTestNow(Carbon::parse('2026-10-24 12:00', 'Africa/Cairo'));
    QueueSetting::factory()->create(['id' => 1, 'enabled' => true]);
});

/** Her open window, answered 2 minutes ago, the customer not waiting. */
function foWindow(array $attrs = []): array
{
    $shift = Shift::query()->first() ?? Shift::factory()->create();
    $u = User::factory()->create(['role' => 'moderator', 'last_seen_at' => now()]);
    $u->userPlatforms()->create(['platform' => 'facebook']);
    $m = ShiftMember::factory()->for($shift)->create(['user_id' => $u->id, 'status' => 'busy']);
    $e = QueueEntry::factory()->create($attrs + [
        'shift_id' => $shift->id, 'shift_member_id' => $m->id, 'assigned_user_id' => $u->id, 'status' => 'active', 'window_no' => 1,
        'delivered_at' => now()->subMinutes(5), 'enqueued_at' => now()->subMinutes(6), 'first_reply_at' => now()->subMinutes(4),
        'last_agent_message_at' => now()->subMinutes(2), 'last_customer_message_at' => now()->subMinutes(5), 'awaiting_reply_since' => null,
    ]);
    $c = $e->conversation;
    $c->update(['assignee_id' => $u->id, 'queue_entry_id' => $e->id, 'handler' => 'human', 'needs_human' => false, 'last_customer_message_at' => now()->subMinutes(5), 'platform' => 'facebook']);
    Message::factory()->for($c)->create(['direction' => MessageDirection::In, 'sender_type' => SenderType::Customer, 'body' => 'السلام عليكم', 'created_at' => now()->subMinutes(5)]);
    Message::factory()->for($c)->create(['direction' => MessageDirection::Out, 'sender_type' => SenderType::User, 'user_id' => $u->id, 'body' => 'اتفضلي', 'created_at' => now()->subMinutes(2)]);

    return [$e->fresh(), $u];
}

function foBot(QueueEntry $e): array
{
    return Message::query()->where('conversation_id', $e->conversation_id)->where('sender_type', 'bot')->orderBy('id')->pluck('body')->all();
}

function foFinish(QueueEntry $e, User $u, array $body)
{
    return test()->actingAs($u)->postJson("/inbox/conversations/{$e->conversation_id}/finish", ['entry_id' => $e->id] + $body);
}

it('«ما ردّتش»: closes with no message, keeps the return window, and records the outcome', function () {
    [$e, $u] = foWindow();

    $res = foFinish($e, $u, ['outcome' => 'no_answer'])->assertOk()
        ->assertJsonPath('data.closing', 'skipped')->assertJsonPath('data.skipped_reason', 'no_reply')->assertJsonPath('data.end_mode', 'thanks')
        ->assertJsonPath('data.outcome.key', 'no_answer')->assertJsonPath('data.outcome.source', 'agent');

    $e->refresh();
    expect(foBot($e))->toBe([])->and($e->closing_skipped_reason)->toBe('no_reply')->and($e->close_reason)->toBe('inquiry')
        ->and($e->conversation->return_priority_until)->not->toBeNull()
        ->and($res->json('data.undo_until'))->not->toBeNull();
});

it('«ما ردّتش» still sends the thanks when she asks for it', function () {
    [$e, $u] = foWindow();

    foFinish($e, $u, ['outcome' => 'no_answer', 'send_closing' => true])->assertOk()->assertJsonPath('data.closing', 'queued');

    expect(foBot($e))->toHaveCount(1);
});

it('«سبام»: straight back to the bot, no message, no return window', function () {
    [$e, $u] = foWindow();

    foFinish($e, $u, ['outcome' => 'spam'])->assertOk()->assertJsonPath('data.end_mode', 'bot')->assertJsonPath('data.closing', 'skipped');

    $e->refresh();
    expect(foBot($e))->toBe([])->and($e->conversation->return_priority_until)->toBeNull()->and($e->conversation->handler->value)->toBe('bot')
        ->and(ConversationOutcome::where('queue_entry_id', $e->id)->value('outcome'))->toBe('spam');
});

it('«مرتجع اتسجل» with no case yet opens a return case in her name and says its number with the thanks', function () {
    [$e, $u] = foWindow();

    foFinish($e, $u, ['outcome' => 'return_case'])->assertOk()->assertJsonPath('data.closing', 'queued');

    $case = SupportCase::query()->sole();
    $row = ConversationOutcome::where('queue_entry_id', $e->id)->sole();
    $bot = foBot($e);
    expect($case->type)->toBe('return')->and($case->opened_by_id)->toBe($u->id)->and($case->queue_entry_id)->toBe($e->id)->and($case->status)->toBe('new')
        ->and($e->fresh()->close_reason)->toBe('case')->and($e->fresh()->support_case_id)->toBe($case->id)
        ->and($row->outcome)->toBe('return_case')->and($row->support_case_id)->toBe($case->id)
        ->and($bot)->toHaveCount(1)->and($bot[0])->toContain('فتحنالك طلب رقم '.$case->id)->and($bot[0])->toEndWith(FO_THANKS);
});

it('«شكوى اتسجلت» links her open complaint instead of a second case, and the complaint stays open', function () {
    [$e, $u] = foWindow();
    $open = SupportCase::factory()->create(['conversation_id' => $e->conversation_id, 'customer_id' => $e->conversation->customer_id, 'type' => 'complaint', 'status' => 'new', 'created_at' => now()->subMinutes(3)]);

    foFinish($e, $u, ['outcome' => 'complaint_case'])->assertOk()->assertJsonPath('data.support_case_id', $open->id);

    expect(SupportCase::count())->toBe(1)->and($open->fresh()->status)->toBe('new')
        ->and(ConversationOutcome::where('queue_entry_id', $e->id)->value('support_case_id'))->toBe($open->id)
        ->and($e->fresh()->close_reason)->toBe('case')
        ->and(foBot($e))->toBe([FO_THANKS]); // no case line: nothing new was opened
});

it('«استبدال اتسجل» with «اتحل» ticked resolves her open case in the same close', function () {
    [$e, $u] = foWindow();
    $open = SupportCase::factory()->create(['conversation_id' => $e->conversation_id, 'customer_id' => $e->conversation->customer_id, 'type' => 'exchange', 'status' => 'in_progress', 'created_at' => now()->subMinutes(3)]);

    foFinish($e, $u, ['outcome' => 'exchange_case', 'resolve_case_id' => $open->id])->assertOk();

    expect($open->fresh()->status)->toBe('resolved')->and(SupportCase::count())->toBe(1)
        ->and(ConversationOutcome::where('queue_entry_id', $e->id)->value('outcome'))->toBe('exchange_case');
});

it('an explicit open_case null records the case outcome without opening a case (the moderator said so)', function () {
    [$e, $u] = foWindow();

    foFinish($e, $u, ['outcome' => 'order_change', 'open_case' => null])->assertOk();

    expect(SupportCase::count())->toBe(0)->and($e->fresh()->close_reason)->toBe('problem')
        ->and(ConversationOutcome::where('queue_entry_id', $e->id)->value('outcome'))->toBe('order_change');
});

it('«استفسار عن أوردر» is a problem close with the thanks and the rating', function () {
    [$e, $u] = foWindow();

    foFinish($e, $u, ['outcome' => 'order_inquiry'])->assertOk()->assertJsonPath('data.closing', 'queued');

    expect($e->fresh()->close_reason)->toBe('problem')->and(SupportCase::count())->toBe(0)->and(foBot($e))->toHaveCount(1);
});

it('«اشترت» can be picked with no order in the chat (she bought on the site)', function () {
    [$e, $u] = foWindow();

    foFinish($e, $u, ['outcome' => 'ordered'])->assertOk()->assertJsonPath('data.outcome.key', 'ordered');

    $row = ConversationOutcome::where('queue_entry_id', $e->id)->sole();
    expect($row->source)->toBe('agent')->and($row->order_id)->toBeNull()->and($row->set_by_id)->toBe($u->id);
});

it('records the suggestion the sheet showed and whether she followed it', function () {
    [$e, $u] = foWindow();
    foFinish($e, $u, ['outcome' => 'no_answer', 'suggested_outcome' => 'no_answer'])->assertOk()
        ->assertJsonPath('data.outcome.suggested', 'no_answer')->assertJsonPath('data.outcome.followed', true);

    [$e2, $u2] = foWindow(['window_no' => 2]);
    foFinish($e2, $u2, ['outcome' => 'price', 'suggested_outcome' => 'no_answer'])->assertOk()
        ->assertJsonPath('data.outcome.suggested', 'no_answer')->assertJsonPath('data.outcome.followed', false);

    expect((bool) ConversationOutcome::where('queue_entry_id', $e->id)->value('suggestion_followed'))->toBeTrue()
        ->and((bool) ConversationOutcome::where('queue_entry_id', $e2->id)->value('suggestion_followed'))->toBeFalse()
        ->and(ConversationOutcome::where('queue_entry_id', $e->id)->value('suggested_outcome'))->toBe('no_answer');
});

it('computes the suggestion itself when the request carries none (the installed app)', function () {
    [$e, $u] = foWindow(); // we replied, she never wrote again → no_answer

    foFinish($e, $u, ['outcome' => 'browsing'])->assertOk()->assertJsonPath('data.outcome.suggested', 'no_answer')->assertJsonPath('data.outcome.followed', false);
});

it('serves the suggestion with its reason in the context, and the finish response names the outcome', function () {
    [$e, $u] = foWindow();

    $ctx = $this->actingAs($u)->getJson("/inbox/conversations/{$e->conversation_id}/context")->assertOk()->json('data.outcome');

    expect($ctx['suggested']['outcome'])->toBe('no_answer')->and($ctx['suggested']['why'])->toBe('no_reply')->and($ctx['suggested']['params']['minutes'])->toBe(2);
});

it('still reads the installed app\'s legacy service: the case type\'s outcome, else an order inquiry', function () {
    [$e, $u] = foWindow();
    foFinish($e, $u, ['outcome' => 'service', 'open_case' => 'complaint'])->assertOk();
    expect(ConversationOutcome::where('queue_entry_id', $e->id)->value('outcome'))->toBe('complaint_case');

    [$e2, $u2] = foWindow(['window_no' => 2]);
    foFinish($e2, $u2, ['outcome' => 'service'])->assertOk();
    expect(ConversationOutcome::where('queue_entry_id', $e2->id)->value('outcome'))->toBe('order_inquiry')->and($e2->fresh()->close_reason)->toBe('problem');
});

it('refuses unknown and the removed keys', function () {
    [$e, $u] = foWindow();

    foFinish($e, $u, ['outcome' => 'unknown'])->assertUnprocessable()->assertJsonValidationErrors('outcome');
    foFinish($e, $u, ['outcome' => 'transferred'])->assertUnprocessable()->assertJsonValidationErrors('outcome');
    expect($e->fresh()->status)->toBe('active');
});

it('«تراجع» after a case outcome puts the window back and closes the case the close opened', function () {
    Bus::fake([SendClosingMessage::class]); // the closing message waits DELAY_SECONDS; here nothing runs
    [$e, $u] = foWindow();
    foFinish($e, $u, ['outcome' => 'return_case'])->assertOk();

    $this->actingAs($u)->postJson("/inbox/conversations/{$e->conversation_id}/finish/undo")->assertOk();

    expect($e->fresh()->status)->toBe('active')->and(SupportCase::sole()->status)->toBe('closed')
        ->and(ConversationOutcome::where('queue_entry_id', $e->id)->whereNotNull('ended_at')->exists())->toBeFalse();
});
