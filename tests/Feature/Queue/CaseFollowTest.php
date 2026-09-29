<?php

use App\Http\Resources\ConversationResource;
use App\Http\Resources\QueueEntryResource;
use App\Models\Conversation;
use App\Models\QueueEntry;
use App\Models\QueueSetting;
use App\Models\Shift;
use App\Models\ShiftMember;
use App\Models\SupportCase;
use App\Models\User;
use App\Queue\Data\HandoverContext;
use App\Queue\QueueRouter;
use App\Queue\QueueService;
use Illuminate\Support\Carbon;

beforeEach(function () {
    Carbon::setTestNow(Carbon::parse('2026-10-05 12:00', 'Africa/Cairo'));
    QueueSetting::factory()->create(['id' => 1, 'enabled' => true]);
});

/** A moderator on the shift since an hour ago, logged in, who may serve every platform. */
function caseDesk(Shift $shift, array $attrs = []): ShiftMember
{
    $u = User::factory()->create(['last_seen_at' => now()]);
    foreach (['facebook', 'instagram', 'whatsapp', 'tiktok'] as $p) {
        $u->userPlatforms()->create(['platform' => $p]);
    }

    return ShiftMember::factory()->for($shift)->create(['user_id' => $u->id, 'joined_at' => now()->subHour()] + $attrs);
}

/** A customer who just wrote (her reply window is open). */
function caseConv(): Conversation
{
    return Conversation::factory()->create(['last_customer_message_at' => now()]);
}

/** Her support case, opened by `$by` (null: collected by the bot) and still being worked on. */
function caseOpenedBy(Conversation $c, ?User $by, array $attrs = []): SupportCase
{
    return SupportCase::factory()->create($attrs + ['conversation_id' => $c->id, 'customer_id' => $c->customer_id, 'status' => 'in_progress', 'opened_by_id' => $by?->id]);
}

function caseHandover(): HandoverContext
{
    return new HandoverContext('human_request', 'human_request', 'medium', null, [], 'unknown');
}

it('sends a customer with an open case to the moderator who opened it, and says so on the entry and the conversation', function () {
    $shift = Shift::factory()->create();
    $owner = caseDesk($shift, ['status' => 'busy']);
    QueueEntry::factory()->create(['shift_member_id' => $owner->id, 'assigned_user_id' => $owner->user_id, 'status' => 'active', 'window_no' => 1]);   // the owner is busier than her colleague
    caseDesk($shift);
    $c = caseConv();
    $case = caseOpenedBy($c, $owner->user);

    $e = app(QueueService::class)->enqueue($c, caseHandover())->fresh();

    expect($e->open_case_id)->toBe($case->id)->and($e->assigned_user_id)->toBe($owner->user_id)->and($e->rule)->toContain('كيس مفتوح #'.$case->id)
        ->and(QueueEntryResource::data($e)['open_case_id'])->toBe($case->id)
        ->and((new ConversationResource($c->fresh()))->resolve(request())['queue_entry']['open_case_id'])->toBe($case->id);
});

it('gives her to anyone when the case owner is not logged in', function () {
    $shift = Shift::factory()->create();
    $owner = caseDesk($shift);
    $other = caseDesk($shift);
    $owner->user->forceFill(['last_seen_at' => now()->subMinutes(10)])->save();
    $c = caseConv();
    caseOpenedBy($c, $owner->user);

    $e = app(QueueService::class)->enqueue($c, caseHandover())->fresh();

    expect($e->assigned_user_id)->toBe($other->user_id)->and($e->open_case_id)->not->toBeNull();
});

it('ignores closed and resolved cases, has no owner for a case the bot collected, and follows the switch', function () {
    $shift = Shift::factory()->create();
    $owner = caseDesk($shift);
    $owner->user->forceFill(['last_seen_at' => now()->subHour()])->save();   // nobody routes: the entries stay as enqueued
    $svc = app(QueueService::class);

    $closed = caseConv();
    caseOpenedBy($closed, $owner->user, ['status' => 'closed']);
    $resolved = caseConv();
    caseOpenedBy($resolved, $owner->user, ['resolved_at' => now()]);
    $bot = caseConv();
    $botCase = caseOpenedBy($bot, null);
    $entries = collect([$closed, $resolved, $bot])->map(fn (Conversation $c) => $svc->enqueue($c, caseHandover())->fresh());

    QueueSetting::current()->update(['case_follow_owner' => false]);
    $off = caseConv();
    $offCase = caseOpenedBy($off, $owner->user);
    $entries->push($svc->enqueue($off, caseHandover())->fresh());

    expect($entries->pluck('open_case_id')->all())->toBe([null, null, $botCase->id, $offCase->id])
        ->and($entries->pluck('reserved_user_id')->all())->toBe([null, null, null, null]);
});

it('prefers the case owner, then the same moderator, then anyone for a customer who comes back', function (string $online, string $expected) {
    $shift = Shift::factory()->create();
    $desks = ['owner' => caseDesk($shift), 'same' => caseDesk($shift), 'anyone' => caseDesk($shift)];
    $c = caseConv();
    caseOpenedBy($c, $desks['owner']->user);
    QueueEntry::factory()->create([
        'conversation_id' => $c->id, 'customer_id' => $c->customer_id, 'status' => 'closed', 'close_reason' => 'auto',
        'assigned_user_id' => $desks['same']->user_id, 'closed_at' => now()->subMinutes(5),
    ]);
    $c->forceFill(['handler' => 'human', 'return_priority_until' => now()->addHour()])->save();

    foreach ($desks as $name => $desk) {
        if (! in_array($name, explode(',', $online), true)) {
            $desk->user->forceFill(['last_seen_at' => now()->subHour()])->save();
        }
    }

    expect(app(QueueService::class)->customerReturned($c->fresh()))->toBeTrue();

    $e = QueueEntry::where('conversation_id', $c->id)->latest('id')->first();
    expect($e->priority)->toBe('returning')->and($e->assigned_user_id)->toBe($desks[$expected]->user_id);
})->with([
    'the owner when she is free' => ['owner,same,anyone', 'owner'],
    'else the same moderator' => ['same,anyone', 'same'],
    'else anyone' => ['anyone', 'anyone'],
]);

it('does not hold an overnight customer for a busy case owner', function () {
    $shift = Shift::factory()->create();
    $owner = caseDesk($shift, ['windows_cap' => 1, 'status' => 'busy']);
    QueueEntry::factory()->create(['shift_member_id' => $owner->id, 'assigned_user_id' => $owner->user_id, 'status' => 'active', 'window_no' => 1]);
    $other = caseDesk($shift);
    $c = caseConv();
    $case = caseOpenedBy($c, $owner->user);
    $night = QueueEntry::factory()->create(['conversation_id' => $c->id, 'priority' => 'overnight', 'reserved_user_id' => $owner->user_id, 'open_case_id' => $case->id]);

    app(QueueRouter::class)->run('t');

    expect($night->fresh()->assigned_user_id)->toBe($other->user_id);
});

it('never gives her back to the moderator who did not reply, even when she opened the case', function () {
    $shift = Shift::factory()->create();
    $owner = caseDesk($shift);
    $other = caseDesk($shift);
    $c = caseConv();
    $case = caseOpenedBy($c, $owner->user);
    $e = QueueEntry::factory()->create([
        'conversation_id' => $c->id, 'priority' => 'live', 'reserved_user_id' => $owner->user_id, 'open_case_id' => $case->id, 'excluded_user_id' => $owner->user_id,
    ]);

    app(QueueRouter::class)->run('t');

    expect($e->fresh()->assigned_user_id)->toBe($other->user_id);
});

it('does not send a transferred customer back to the moderator she was taken from', function () {
    $shift = Shift::factory()->create();
    $from = caseDesk($shift);
    $other = caseDesk($shift);
    $c = caseConv();
    $case = caseOpenedBy($c, null);
    QueueEntry::factory()->create(['shift_member_id' => $from->id, 'assigned_user_id' => $from->user_id, 'status' => 'active', 'window_no' => 1]);   // busier than her colleague: only a wrong preference would pick her
    $old = QueueEntry::factory()->create(['conversation_id' => $c->id, 'status' => 'closed', 'close_reason' => 'transfer', 'assigned_user_id' => $from->user_id, 'closed_at' => now()]);
    $e = QueueEntry::factory()->create(['conversation_id' => $c->id, 'priority' => 'returning', 'open_case_id' => $case->id, 'reopened_from_entry_id' => $old->id]);

    app(QueueRouter::class)->run('t');

    expect($e->fresh()->assigned_user_id)->toBe($other->user_id);
});
