<?php

use App\Inbox\OutboundService;
use App\Models\QueueEntry;
use App\Models\QueueSetting;
use App\Models\ScoreEvent;
use App\Models\Shift;
use App\Models\ShiftMember;
use App\Models\User;
use App\Queue\Events\CloseReversed;
use App\Queue\QueueRouter;
use App\Queue\Scoring\ScoreKeeper;
use App\Queue\Scoring\ScoreListener;
use App\Queue\WindowLifecycle;
use Illuminate\Support\Carbon;

beforeEach(function () {
    Carbon::setTestNow(Carbon::parse('2026-10-05 12:00', 'Africa/Cairo'));
    QueueSetting::factory()->create(['id' => 1, 'enabled' => true, 'windows_per_moderator' => 1]);
});

/** A logged-in moderator on a shift who may serve every platform. */
function wpDesk(Shift $shift): ShiftMember
{
    $u = User::factory()->create(['last_seen_at' => now()]);
    foreach (['facebook', 'instagram', 'whatsapp', 'tiktok'] as $p) {
        $u->userPlatforms()->create(['platform' => $p]);
    }

    return ShiftMember::factory()->for($shift)->create(['user_id' => $u->id, 'joined_at' => now()->subHour()]);
}

/** A window the router gives to `$m` now (the customer just wrote). */
function wpWindow(ShiftMember $m, array $attrs = []): QueueEntry
{
    $e = QueueEntry::factory()->create(['enqueued_at' => now()->subMinute(), 'last_customer_message_at' => now()] + $attrs);
    $e->conversation->update(['queue_entry_id' => $e->id, 'handler' => 'human', 'needs_human' => true, 'last_customer_message_at' => now()]);
    expect(app(QueueRouter::class)->assign($e, $m, 'test', 'live'))->toBeTrue();

    return $e->fresh();
}

/** @return list<array{0:string,1:int}> */
function wpLines(int $userId): array
{
    return ScoreEvent::query()->where('user_id', $userId)->orderBy('id')->get()->map(fn (ScoreEvent $l) => [$l->kind, $l->points])->all();
}

function wpAt(int $seconds): void
{
    Carbon::setTestNow(Carbon::parse('2026-10-05 12:00', 'Africa/Cairo')->addSeconds($seconds));
    User::query()->update(['last_seen_at' => now()]);
}

it('pays the base and the fast-reply bonus at her final close', function () {
    $m = wpDesk(Shift::factory()->create());
    $e = wpWindow($m);
    wpAt(40);
    app(OutboundService::class)->sendHuman($e->conversation->fresh(), $m->user, 'أهلاً بيكي، معاكي');
    wpAt(300);
    app(WindowLifecycle::class)->close($e->fresh(), 'inquiry', $m->user);

    expect(wpLines($m->user_id))->toBe([['base', 8], ['speed', 6]])
        ->and(ScoreEvent::query()->value('queue_entry_id'))->toBe($e->id)
        ->and(ScoreEvent::query()->value('business_date'))->toBe('2026-10-05');
});

it('pays the slower bonus within speed_ok and none after it; a supervisor closing for her still pays her', function () {
    $shift = Shift::factory()->create();
    $a = wpDesk($shift);
    $b = wpDesk($shift);
    $boss = User::factory()->create(['role' => 'supervisor']);
    $e1 = wpWindow($a);
    $e2 = wpWindow($b);
    wpAt(120);
    app(OutboundService::class)->sendHuman($e1->conversation->fresh(), $a->user, 'معاكي يا فندم');
    wpAt(200);
    app(OutboundService::class)->sendHuman($e2->conversation->fresh(), $b->user, 'معاكي يا فندم');
    app(WindowLifecycle::class)->close($e1->fresh(), 'problem', $boss);
    app(WindowLifecycle::class)->close($e2->fresh(), 'problem', $b->user);

    expect(wpLines($a->user_id))->toBe([['base', 12], ['speed', 3]])
        ->and(wpLines($b->user_id))->toBe([['base', 12]])
        ->and(wpLines($boss->id))->toBe([]);
});

it('pays nothing for an escalation and nothing on a test chat', function () {
    $shift = Shift::factory()->create();
    $a = wpDesk($shift);
    $e = wpWindow($a);
    wpAt(30);
    app(OutboundService::class)->sendHuman($e->conversation->fresh(), $a->user, 'ثواني هسأل');
    app(WindowLifecycle::class)->escalate($e->fresh(), $a->user);

    $b = wpDesk($shift);
    $t = wpWindow($b, ['is_test' => true]);
    app(WindowLifecycle::class)->close($t->fresh(), 'inquiry', $b->user);

    expect(ScoreEvent::count())->toBe(0);
});

it('is idempotent when the close event is handled twice and takes the window back on a reversal', function () {
    $m = wpDesk(Shift::factory()->create());
    $e = wpWindow($m);
    app(WindowLifecycle::class)->close($e->fresh(), 'inquiry', $m->user);
    app(ScoreListener::class)->forEntry($e->fresh());
    expect(wpLines($m->user_id))->toBe([['base', 8]]);

    app(ScoreListener::class)->closeReversed(new CloseReversed($e->fresh()));
    expect(app(ScoreKeeper::class)->dayTotal($m->user_id, '2026-10-05'))->toBe(0);
});

it('takes one point off at a no-reply hand-off', function () {
    $shift = Shift::factory()->create();
    $a = wpDesk($shift);
    $e = wpWindow($a);
    wpDesk($shift); // a free colleague
    wpAt(180);
    app(WindowLifecycle::class)->tickReplies();
    wpAt(300);
    app(WindowLifecycle::class)->tickReplies();

    expect($e->fresh()->close_reason)->toBe('no_reply')
        ->and(wpLines($a->user_id))->toBe([['no_reply', -1]]);
});

it('pays the rating to the rated window on its day', function () {
    $m = wpDesk(Shift::factory()->create());
    $e = wpWindow($m);
    app(WindowLifecycle::class)->close($e->fresh(), 'inquiry', $m->user);
    $e->forceFill(['review_requested_at' => now()])->save();

    app(ScoreListener::class)->rated($e->fresh()->forceFill(['review_stars' => 1]));
    app(ScoreListener::class)->rated($e->fresh()->forceFill(['review_stars' => 1]));

    expect(wpLines($m->user_id))->toBe([['base', 8], ['review', -10]]);
});

it('never repays a close that was reversed', function () {
    $m = wpDesk(Shift::factory()->create());
    $e = wpWindow($m);
    $e->forceFill(['status' => 'closed', 'close_reason' => 'inquiry', 'closed_at' => now(), 'reversed_at' => now()])->save();

    app(ScoreListener::class)->forEntry($e->fresh());

    expect(wpLines($m->user_id))->toBe([]);
});
