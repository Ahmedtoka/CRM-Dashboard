<?php

use App\Models\QueueDecision;
use App\Models\QueueEntry;
use App\Models\QueueSetting;
use App\Models\Shift;
use App\Models\ShiftMember;
use App\Models\SupportCase;
use App\Models\User;
use App\Models\UserNotification;
use App\Queue\QueueRouter;
use Illuminate\Support\Carbon;

/**
 * Distribution audit (round 2026-10-24, «التوزيع والدور»): one shift, three moderators, ten
 * arrivals, and every rule the owner named — least load, a returning customer to her moderator,
 * the excluded moderator, the windows cap, a checked-in desk whose moderator is not logged in,
 * the overnight backlog and the escalation lane. Each `it` is one scenario; the cases that were
 * found unfair or stuck by this file are marked «fix».
 */
beforeEach(function () {
    Carbon::setTestNow(Carbon::parse('2026-10-05 12:00', 'Africa/Cairo'));
    QueueSetting::factory()->create(['id' => 1, 'enabled' => true, 'windows_per_moderator' => 3]);
});

/** A checked-in, logged-in moderator on `$shift` who may serve `$platforms`. */
function simModerator(Shift $shift, string $name, array $platforms = ['facebook', 'instagram', 'whatsapp', 'tiktok'], array $attrs = []): ShiftMember
{
    $u = User::factory()->create(['name' => $name, 'last_seen_at' => now()]);
    foreach ($platforms as $p) {
        $u->userPlatforms()->create(['platform' => $p]);
    }

    return ShiftMember::factory()->for($shift)->create(['user_id' => $u->id] + $attrs);
}

/** A customer in the lounge; tickets count up, and each arrives a second after the one before. */
function simArrival(array $attrs = [], ?string $platform = null): QueueEntry
{
    static $n = 0;
    $n++;
    $e = QueueEntry::factory()->create($attrs + ['ticket_no' => $n, 'enqueued_at' => now()->subSeconds(600 - $n)]);

    if ($platform !== null) {
        $e->conversation->update(['platform' => $platform]);
    }

    return $e;
}

/** Her open windows now, from the routed entries. */
function simLoad(ShiftMember $m): int
{
    return QueueEntry::query()->where('assigned_user_id', $m->user_id)->whereIn('status', QueueEntry::OPEN_STATUSES)->count();
}

it('spreads ten live arrivals over three moderators by load, oldest first, and the tenth waits for a window', function () {
    $shift = Shift::factory()->create();
    $a = simModerator($shift, 'آية');
    $b = simModerator($shift, 'بسمة');
    $c = simModerator($shift, 'جنى');
    $arrivals = collect(range(1, 10))->map(fn () => simArrival());

    expect(app(QueueRouter::class)->run('شيفت تجريبي'))->toBe(9);

    // 3 × 3 windows: everyone full, the tenth (youngest) customer is the one who waits.
    expect(simLoad($a))->toBe(3)->and(simLoad($b))->toBe(3)->and(simLoad($c))->toBe(3)
        ->and($arrivals->last()->fresh()->status)->toBe('waiting')
        ->and($arrivals->take(9)->every(fn (QueueEntry $e) => $e->fresh()->status === 'active'))->toBeTrue();

    // Nobody got a second customer while a colleague still had none: tickets 1-3 went to three
    // different desks, so did 4-6 and 7-9 (least load, then fewest this shift).
    foreach ([[1, 2, 3], [4, 5, 6], [7, 8, 9]] as $round) {
        $desks = $arrivals->filter(fn (QueueEntry $e) => in_array($e->ticket_no, $round, true))->map(fn (QueueEntry $e) => $e->fresh()->assigned_user_id)->unique();
        expect($desks)->toHaveCount(3);
    }

    // A window frees up: the waiting customer goes to the desk that now has the least load.
    QueueEntry::query()->where('assigned_user_id', $b->user_id)->first()->forceFill(['status' => 'closed', 'closed_at' => now()])->save();
    expect(app(QueueRouter::class)->run('قفل شباك'))->toBe(1)
        ->and($arrivals->last()->fresh()->assigned_user_id)->toBe($b->user_id);
});

it('gives a returning customer to her own moderator while she has a window, else to the least loaded colleague', function () {
    $shift = Shift::factory()->create();
    $a = simModerator($shift, 'آية');
    $b = simModerator($shift, 'بسمة');
    $c = simModerator($shift, 'جنى');
    QueueEntry::factory()->count(2)->create(['assigned_user_id' => $a->user_id, 'shift_member_id' => $a->id, 'status' => 'active']);
    QueueEntry::factory()->create(['assigned_user_id' => $b->user_id, 'shift_member_id' => $b->id, 'status' => 'active']);

    // A is the most loaded (2 of 3) and still gets her returning customer: she knows the story.
    $back = simArrival(['priority' => 'returning', 'reserved_user_id' => $a->user_id]);
    app(QueueRouter::class)->run('t');
    expect($back->fresh()->assigned_user_id)->toBe($a->user_id)->and($back->fresh()->rule)->toContain('نفس الموظفة');

    // A is now full: the next returning customer of hers goes to the least loaded desk (C, 0 open), not B.
    $back2 = simArrival(['priority' => 'returning', 'reserved_user_id' => $a->user_id]);
    app(QueueRouter::class)->run('t');
    expect($back2->fresh()->assigned_user_id)->toBe($c->user_id)->and($back2->fresh()->rule)->toContain('الأقل حملاً');
});

it('never gives a customer back to the moderator she was taken from for not replying, even when she is the least loaded', function () {
    $shift = Shift::factory()->create();
    $a = simModerator($shift, 'آية');
    $b = simModerator($shift, 'بسمة');
    $c = simModerator($shift, 'جنى');
    QueueEntry::factory()->count(2)->create(['assigned_user_id' => $b->user_id, 'shift_member_id' => $b->id, 'status' => 'active']);
    QueueEntry::factory()->count(2)->create(['assigned_user_id' => $c->user_id, 'shift_member_id' => $c->id, 'status' => 'active']);

    $e = simArrival(['priority' => 'returning', 'excluded_user_id' => $a->user_id, 'reserved_user_id' => $a->user_id]);
    app(QueueRouter::class)->run('t');
    expect($e->fresh()->assigned_user_id)->not->toBe($a->user_id)->and($e->fresh()->status)->toBe('active');

    // The same for a live customer: A (0 open) is skipped for B or C (2 open each).
    $live = simArrival(['excluded_user_id' => $a->user_id]);
    app(QueueRouter::class)->run('t');
    expect($live->fresh()->assigned_user_id)->not->toBe($a->user_id)->and($live->fresh()->status)->toBe('active');
});

it('caps every desk at windows_per_moderator and lets a desk\'s own cap win over it', function () {
    QueueSetting::query()->whereKey(1)->update(['windows_per_moderator' => 2]);
    $shift = Shift::factory()->create();
    $a = simModerator($shift, 'آية');
    $b = simModerator($shift, 'بسمة', attrs: ['windows_cap' => 1]);
    $c = simModerator($shift, 'جنى');
    collect(range(1, 7))->each(fn () => simArrival());

    expect(app(QueueRouter::class)->run('t'))->toBe(5)
        ->and(simLoad($a))->toBe(2)->and(simLoad($b))->toBe(1)->and(simLoad($c))->toBe(2)
        ->and(QueueEntry::query()->where('status', 'waiting')->count())->toBe(2);
});

it('gives nothing to a checked-in desk whose moderator is not logged in, and her reserved night customers go to the others', function () {
    $shift = Shift::factory()->create();
    $a = simModerator($shift, 'آية');
    $b = simModerator($shift, 'بسمة');
    $gone = simModerator($shift, 'جنى');
    $gone->user->forceFill(['last_seen_at' => now()->subMinutes(10)])->save();

    $night = simArrival(['priority' => 'overnight', 'reserved_user_id' => $gone->user_id, 'enqueued_at' => now()->subHours(8)]);
    $live = collect(range(1, 6))->map(fn () => simArrival());

    expect(app(QueueRouter::class)->run('t'))->toBe(6)
        ->and(simLoad($gone))->toBe(0)->and(simLoad($a))->toBe(3)->and(simLoad($b))->toBe(3)
        ->and($night->fresh()->status)->toBe('waiting');

    // The desks are full with live customers; once one frees, the night customer is anyone's.
    QueueEntry::query()->where('assigned_user_id', $a->user_id)->first()->forceFill(['status' => 'closed', 'closed_at' => now()])->save();
    app(QueueRouter::class)->run('t');
    expect($night->fresh()->assigned_user_id)->toBe($a->user_id)->and($night->fresh()->rule)->toContain('موظفتها مش متاحة');
});

it('does not hold a case customer in the lounge for her busy case owner', function () {
    $shift = Shift::factory()->create();
    $owner = simModerator($shift, 'آية');
    $b = simModerator($shift, 'بسمة');
    QueueEntry::factory()->count(3)->create(['assigned_user_id' => $owner->user_id, 'shift_member_id' => $owner->id, 'status' => 'active']);
    $case = SupportCase::factory()->create(['opened_by_id' => $owner->user_id, 'status' => 'new']);

    $e = simArrival(['open_case_id' => $case->id, 'reserved_user_id' => $owner->user_id]);
    app(QueueRouter::class)->run('t');
    expect($e->fresh()->assigned_user_id)->toBe($b->user_id);
});

// fix: the backlog was sorted by ticket number, which restarts every business day — after a day
// off, Saturday's #1 was served before Friday's #2.
it('serves the overnight backlog in arrival order when it spans two business days', function () {
    $shift = Shift::factory()->create();
    simModerator($shift, 'آية', attrs: ['windows_cap' => 2]);
    $fri1 = simArrival(['priority' => 'overnight', 'ticket_no' => 1, 'business_date' => '2026-10-03', 'enqueued_at' => now()->subDays(2)->subHours(2)]);
    $fri2 = simArrival(['priority' => 'overnight', 'ticket_no' => 2, 'business_date' => '2026-10-03', 'enqueued_at' => now()->subDays(2)->subHour()]);
    $sat1 = simArrival(['priority' => 'overnight', 'ticket_no' => 1, 'business_date' => '2026-10-04', 'enqueued_at' => now()->subDay()]);

    expect(app(QueueRouter::class)->run('t'))->toBe(2)
        ->and($fri1->fresh()->status)->toBe('active')->and($fri2->fresh()->status)->toBe('active')
        ->and($sat1->fresh()->status)->toBe('waiting');
});

// fix: an escalation waited for the full leader although a supervisor on the shift had a free window.
it('gives an escalation to a free supervisor on the shift when the leader\'s desk is full', function () {
    $leader = User::factory()->create(['role' => 'supervisor', 'last_seen_at' => now()]);
    $shift = Shift::factory()->create(['leader_user_id' => $leader->id]);
    $lm = ShiftMember::factory()->for($shift)->create(['user_id' => $leader->id, 'windows_cap' => 1]);
    QueueEntry::factory()->create(['assigned_user_id' => $leader->id, 'shift_member_id' => $lm->id, 'status' => 'active', 'priority' => 'escalation']);
    $sup = User::factory()->create(['role' => 'supervisor', 'last_seen_at' => now()]);
    ShiftMember::factory()->for($shift)->create(['user_id' => $sup->id]);
    simModerator($shift, 'آية');

    $e = simArrival(['priority' => 'escalation']);
    expect(app(QueueRouter::class)->run('t'))->toBe(1)
        ->and($e->fresh()->assigned_user_id)->toBe($sup->id)
        ->and(UserNotification::query()->where('type', 'queue.escalation_waiting')->exists())->toBeFalse();
});

// fix: a customer nobody on the shift may serve (her platform) sat in the lounge with the generic
// «مفيش شباك فاضي» line and nobody was told; the live copy showed live tickets waiting 29 hours
// and 6 days (entries 21 and 23) with no alert to anybody.
it('tells the supervisors once when a waiting customer has no desk that may serve her platform', function () {
    $shift = Shift::factory()->create();
    simModerator($shift, 'آية', ['facebook']);
    simModerator($shift, 'بسمة', ['facebook', 'instagram']);
    $tiktok = simArrival([], 'tiktok');
    $fb = simArrival([], 'facebook');

    expect(app(QueueRouter::class)->run('t'))->toBe(1)
        ->and($fb->fresh()->status)->toBe('active')->and($tiktok->fresh()->status)->toBe('waiting');

    $lines = implode(' ', QueueDecision::latest('id')->first()->lines);
    expect($lines)->toContain('#'.$tiktok->ticket_no)->toContain('TikTok');

    // The shift leader (a supervisor) hears once; a second pass inside 15 minutes does not repeat it.
    $alerts = fn () => UserNotification::query()->where('type', 'queue.waiting_no_desk')->where('user_id', $shift->leader_user_id)->count();
    expect($alerts())->toBe(1);
    app(QueueRouter::class)->run('t');
    expect($alerts())->toBe(1);

    // She is not alerted about a customer somebody could take: the facebook customer waits for a window, nothing more.
    expect(UserNotification::query()->where('type', 'queue.waiting_no_desk')->get()->every(fn ($n) => ($n->data['ticket'] ?? null) === $tiktok->ticket_no))->toBeTrue();
});

it('tells the supervisors once when a customer waits during the shift and nobody at all is logged in', function () {
    $shift = Shift::factory()->create();
    $m = simModerator($shift, 'آية');
    $m->user->forceFill(['last_seen_at' => now()->subMinutes(10)])->save();
    $e = simArrival();

    expect(app(QueueRouter::class)->run('t'))->toBe(0);
    $alert = UserNotification::query()->where('type', 'queue.waiting_no_desk')->where('user_id', $shift->leader_user_id)->first();
    expect($alert)->not->toBeNull()->and($alert->data['ticket'])->toBe($e->ticket_no)->and($alert->data['reason'])->toBe('nobody');
    app(QueueRouter::class)->run('t');
    expect(UserNotification::query()->where('type', 'queue.waiting_no_desk')->where('user_id', $shift->leader_user_id)->count())->toBe(1);
});
