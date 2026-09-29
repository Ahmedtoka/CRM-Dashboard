<?php

use App\Models\QueueDecision;
use App\Models\QueueEntry;
use App\Models\QueueSetting;
use App\Models\Shift;
use App\Models\ShiftMember;
use App\Models\User;
use App\Queue\Commands\QueueTick;
use App\Queue\Jobs\SendQueueMessage;
use App\Queue\QueueRouter;
use App\Queue\ShiftService;
use App\Queue\WaitEstimator;
use App\Queue\WindowLifecycle;
use Illuminate\Console\Scheduling\Event as ScheduledEvent;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    Carbon::setTestNow(Carbon::parse('2026-10-05 12:00', 'Africa/Cairo'));
});

function tickSettings(array $attrs = []): QueueSetting
{
    return QueueSetting::factory()->create($attrs + ['id' => 1, 'enabled' => true]);
}

function tickMember(Shift $shift, array $attrs = []): ShiftMember
{
    $u = User::factory()->create(['last_seen_at' => now()]);
    foreach (['facebook', 'instagram', 'whatsapp', 'tiktok'] as $p) {
        $u->userPlatforms()->create(['platform' => $p]);
    }

    return ShiftMember::factory()->for($shift)->create($attrs + ['user_id' => $u->id]);
}

/** Mocks the four services; `$order` collects the steps as they run. */
function tickSpies(array &$order, ?string $throwing = null): void
{
    $step = function (string $name, mixed $result = null) use (&$order, $throwing) {
        return function () use (&$order, $name, $result, $throwing) {
            $order[] = $name;

            if ($name === $throwing) {
                throw new RuntimeException('step '.$name.' failed');
            }

            return $result;
        };
    };

    test()->mock(ShiftService::class, function ($m) use ($step) {
        $m->shouldReceive('transition')->once()->andReturnUsing($step('transition'));
        $m->shouldReceive('tickMembers')->once()->andReturnUsing($step('tickMembers'));
    });
    test()->mock(WindowLifecycle::class, function ($m) use ($step) {
        $m->shouldReceive('tickSilence')->once()->andReturnUsing($step('tickSilence'));
        $m->shouldReceive('tickReplies')->once()->andReturnUsing($step('tickReplies'));
        $m->shouldReceive('confirmDue')->once()->andReturnUsing($step('confirmDue', 0));
    });
    test()->mock(WaitEstimator::class, function ($m) use ($step) {
        $m->shouldReceive('tickLounge')->once()->andReturnUsing($step('tickLounge'));
    });
    test()->mock(QueueRouter::class, function ($m) use ($step) {
        $m->shouldReceive('run')->once()->with('التيك الدوري')->andReturnUsing($step('router', 0));
    });
}

it('runs silence, members, transitions, waiting messages and the router in one tick', function () {
    tickSettings();
    $shift = Shift::factory()->create();
    tickMember($shift);
    $e = QueueEntry::factory()->create(['enqueued_at' => now()->subMinutes(1)]);

    $this->artisan('queue:tick')->assertSuccessful();

    expect($e->fresh()->status)->toBe('active');
});

it('runs every step once, in order', function () {
    tickSettings();
    QueueEntry::factory()->create();
    QueueEntry::factory()->create(['priority' => 'overnight']);
    $order = [];
    tickSpies($order);

    $this->artisan('queue:tick')->assertSuccessful();

    // The router runs before the countdown: a customer served now gets no «باقي» message.
    expect($order)->toBe(['transition', 'tickMembers', 'tickSilence', 'tickReplies', 'confirmDue', 'router', 'tickLounge']);
});

it('keeps going when a step throws, and reports it', function (string $throwing) {
    Exceptions::fake();
    tickSettings();
    QueueEntry::factory()->count(2)->create();
    $order = [];
    tickSpies($order, $throwing);

    $this->artisan('queue:tick')->assertSuccessful();

    expect($order)->toBe(['transition', 'tickMembers', 'tickSilence', 'tickReplies', 'confirmDue', 'router', 'tickLounge']);
    Exceptions::assertReported(fn (RuntimeException $e) => $e->getMessage() === 'step '.$throwing.' failed');
    // The lock is given back, so the next tick runs.
    expect(Cache::lock('queue:tick', 5)->get())->toBeTrue();
})->with(['transition', 'tickMembers', 'tickSilence', 'tickReplies', 'confirmDue', 'router', 'tickLounge']);

it('does nothing while the queue is switched off', function () {
    tickSettings(['enabled' => false]);
    $shift = Shift::factory()->create();
    tickMember($shift);
    $e = QueueEntry::factory()->create();
    foreach ([ShiftService::class, WindowLifecycle::class, WaitEstimator::class, QueueRouter::class] as $class) {
        $this->mock($class, fn ($m) => $m->shouldNotReceive('transition', 'tickMembers', 'tickSilence', 'tickReplies', 'confirmDue', 'tickLounge', 'tickWaiting', 'run'));
    }

    $this->artisan('queue:tick')->assertSuccessful();

    expect($e->fresh()->status)->toBe('waiting')->and(QueueDecision::count())->toBe(0);
});

it('leaves at once when another tick is still running', function () {
    tickSettings();
    $shift = Shift::factory()->create();
    tickMember($shift);
    $e = QueueEntry::factory()->create();
    $held = Cache::lock('queue:tick', QueueTick::LOCK_SECONDS);
    expect($held->get())->toBeTrue();

    $this->artisan('queue:tick')->expectsOutputToContain('skipped')->assertSuccessful();
    expect($e->fresh()->status)->toBe('waiting');

    $held->release();
    $this->artisan('queue:tick')->assertSuccessful();
    expect($e->fresh()->status)->toBe('active');
});

it('is scheduled every 30 seconds, without overlapping, on one server', function () {
    $events = collect(app(Schedule::class)->events())->filter(fn (ScheduledEvent $e) => str_contains((string) $e->command, 'queue:tick'))->values();

    expect($events)->toHaveCount(1);
    $event = $events->first();
    expect($event->expression)->toBe('* * * * *')
        ->and($event->repeatSeconds)->toBe(30)
        ->and($event->withoutOverlapping)->toBeTrue()
        ->and($event->expiresAt)->toBe(2)
        ->and($event->onOneServer)->toBeTrue()
        ->and($event->runInBackground)->toBeFalse();
});

it('confirms the closes whose confirm window passed (sweep for a lost job)', function () {
    tickSettings(['close_confirm_minutes' => 30]);
    $due = QueueEntry::factory()->create(['status' => 'closed', 'close_reason' => 'inquiry', 'closed_at' => now()->subMinutes(31)]);
    $early = QueueEntry::factory()->create(['status' => 'closed', 'close_reason' => 'problem', 'closed_at' => now()->subMinutes(10)]);

    $this->artisan('queue:tick')->assertSuccessful();

    expect($due->fresh()->confirmed_at)->not->toBeNull()->and($early->fresh()->confirmed_at)->toBeNull();
});

it('sends a countdown message once, however many ticks see the same estimate', function () {
    Queue::fake([SendQueueMessage::class]);
    tickSettings(['windows_per_moderator' => 1, 'eta_default_handle_seconds' => 600]);
    $shift = Shift::factory()->create();
    $m = tickMember($shift, ['status' => 'busy']);
    QueueEntry::factory()->create(['shift_id' => $shift->id, 'shift_member_id' => $m->id, 'assigned_user_id' => $m->user_id, 'status' => 'active', 'window_no' => 1, 'delivered_at' => now()->subSeconds(310)]);
    $e = QueueEntry::factory()->create(['enqueued_at' => now()->subSeconds(310), 'waiting_messages' => []]);
    // A second tick working from a copy it read before the first one saved the flags.
    $stale = QueueEntry::query()->with('conversation')->find($e->id);

    $this->artisan('queue:tick')->assertSuccessful();
    app(WaitEstimator::class)->tickWaiting($stale);
    $this->artisan('queue:tick')->assertSuccessful();

    Queue::assertPushed(SendQueueMessage::class, fn (SendQueueMessage $job) => $job->scriptKey === 'queue_left_5' && $job->entryId === $e->id);
    expect(Queue::pushed(SendQueueMessage::class, fn (SendQueueMessage $job) => $job->scriptKey === 'queue_left_5'))->toHaveCount(1)
        ->and($e->fresh()->waiting_messages)->toMatchArray(['5' => true])
        ->and($e->fresh()->status)->toBe('waiting');
});

it('sends no countdown to a customer who left the lounge after the tick read her', function () {
    Queue::fake([SendQueueMessage::class]);
    tickSettings(['windows_per_moderator' => 1, 'eta_default_handle_seconds' => 600]);
    $shift = Shift::factory()->create();
    $m = tickMember($shift, ['status' => 'busy']);
    QueueEntry::factory()->create(['shift_id' => $shift->id, 'shift_member_id' => $m->id, 'assigned_user_id' => $m->user_id, 'status' => 'active', 'window_no' => 1, 'delivered_at' => now()->subSeconds(310)]);
    $e = QueueEntry::factory()->create(['enqueued_at' => now()->subSeconds(310), 'waiting_messages' => []]);
    $stale = QueueEntry::query()->with('conversation')->find($e->id);
    QueueEntry::query()->whereKey($e->id)->update(['status' => 'cancelled']);

    app(WaitEstimator::class)->tickWaiting($stale);

    Queue::assertNotPushed(SendQueueMessage::class);
    expect($e->fresh()->waiting_messages)->toBe([]);
});

it('deletes decision lines older than a week, once an hour', function () {
    tickSettings();
    $old = QueueDecision::create(['trigger' => 'old', 'lines' => [], 'created_at' => now()->subDays(8)]);
    $recent = QueueDecision::create(['trigger' => 'recent', 'lines' => [], 'created_at' => now()->subDays(6)]);

    $this->artisan('queue:tick')->assertSuccessful();
    expect(QueueDecision::find($old->id))->toBeNull()->and(QueueDecision::find($recent->id))->not->toBeNull();

    $again = QueueDecision::create(['trigger' => 'old again', 'lines' => [], 'created_at' => now()->subDays(8)]);
    Carbon::setTestNow(now()->addSeconds(30));
    $this->artisan('queue:tick')->assertSuccessful();
    expect(QueueDecision::find($again->id))->not->toBeNull();

    Carbon::setTestNow(now()->addHour());
    $this->artisan('queue:tick')->assertSuccessful();
    expect(QueueDecision::find($again->id))->toBeNull();
});

// ───── review I5: several thresholds in one tick ─────

it('sends only the lowest countdown message when the estimate falls through several thresholds at once', function () {
    Queue::fake([SendQueueMessage::class]);
    tickSettings(['windows_per_moderator' => 1, 'eta_default_handle_seconds' => 600]);
    $shift = Shift::factory()->create();
    $m = tickMember($shift, ['status' => 'busy']);
    QueueEntry::factory()->create(['shift_id' => $shift->id, 'shift_member_id' => $m->id, 'assigned_user_id' => $m->user_id, 'status' => 'active', 'window_no' => 1, 'delivered_at' => now()->subSeconds(200)]);
    $e = QueueEntry::factory()->create(['enqueued_at' => now()->subSeconds(200), 'waiting_messages' => []]);

    app(WaitEstimator::class)->tickLounge();   // 400 s left: nothing yet
    Queue::assertNotPushed(SendQueueMessage::class);
    expect($e->fresh()->eta_seconds)->toBe(400);

    Carbon::setTestNow(now()->addSeconds(350));   // 50 s left: past 5, 3 and 1 in one step
    User::query()->update(['last_seen_at' => now()]); // her heartbeat: she is still logged in
    app(WaitEstimator::class)->tickLounge();

    Queue::assertPushed(SendQueueMessage::class, 1);
    Queue::assertPushed(SendQueueMessage::class, fn (SendQueueMessage $job) => $job->scriptKey === 'queue_left_1' && $job->entryId === $e->id);
    expect($e->fresh()->waiting_messages)->toMatchArray(['5' => true, '3' => true, '1' => true])
        ->and($e->fresh()->eta_seconds)->toBe(50);
});

it('marks the passed thresholds and sends the lower one on the everyday two-step drop', function () {
    Queue::fake([SendQueueMessage::class]);
    tickSettings(['windows_per_moderator' => 1, 'eta_default_handle_seconds' => 600]);
    $shift = Shift::factory()->create();
    $m = tickMember($shift, ['status' => 'busy']);
    QueueEntry::factory()->create(['shift_id' => $shift->id, 'shift_member_id' => $m->id, 'assigned_user_id' => $m->user_id, 'status' => 'active', 'window_no' => 1, 'delivered_at' => now()->subSeconds(290)]);
    $e = QueueEntry::factory()->create(['enqueued_at' => now()->subSeconds(290), 'waiting_messages' => []]);

    app(WaitEstimator::class)->tickLounge();   // 310 s
    Carbon::setTestNow(now()->addSeconds(140));   // 170 s
    User::query()->update(['last_seen_at' => now()]);
    app(WaitEstimator::class)->tickLounge();

    expect(Queue::pushed(SendQueueMessage::class)->map(fn (SendQueueMessage $job) => $job->scriptKey)->all())->toBe(['queue_left_3'])
        ->and($e->fresh()->waiting_messages)->toMatchArray(['5' => true, '3' => true]);
});

// ───── review I6: tick cost ─────

it('keeps a tick with a full lounge to a fixed number of queries, whatever the size of the lounge', function () {
    tickSettings(['windows_per_moderator' => 2]);
    $shift = Shift::factory()->create();

    foreach (range(1, 3) as $i) {
        $m = tickMember($shift, ['status' => 'busy', 'break_at' => now()->addHours(3)]);

        foreach ([1, 2] as $w) {
            QueueEntry::factory()->create(['shift_id' => $shift->id, 'shift_member_id' => $m->id, 'assigned_user_id' => $m->user_id, 'status' => 'active', 'window_no' => $w, 'delivered_at' => now()->subSeconds(60 * $w)]);
        }
    }

    $measure = function (int $waiting): int {
        QueueEntry::factory()->count($waiting)->create(['enqueued_at' => now()->subMinutes(2)]);
        $this->artisan('queue:tick')->assertSuccessful();   // first tick: flags and estimates written
        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->artisan('queue:tick')->assertSuccessful();
        $count = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $count;
    };

    $ten = $measure(10);
    $fifty = $measure(40);   // 50 waiting now

    expect(QueueEntry::where('status', 'waiting')->count())->toBe(50)
        ->and($fifty)->toBeLessThan(45)   // 30 at the time of writing
        ->and($fifty - $ten)->toBeLessThanOrEqual(2);
});

it('keeps estimating the other customers when one estimate throws', function () {
    Queue::fake([SendQueueMessage::class]);
    Exceptions::fake();
    // Estimates 50 s and 300 s: both customers are due a countdown message.
    tickSettings(['windows_per_moderator' => 1, 'eta_default_handle_seconds' => 250]);
    $shift = Shift::factory()->create();
    $m = tickMember($shift, ['status' => 'busy']);
    QueueEntry::factory()->create(['shift_id' => $shift->id, 'shift_member_id' => $m->id, 'assigned_user_id' => $m->user_id, 'status' => 'active', 'window_no' => 1, 'delivered_at' => now()->subSeconds(200)]);
    $bad = QueueEntry::factory()->create(['enqueued_at' => now()->subSeconds(20), 'waiting_messages' => []]);
    $good = QueueEntry::factory()->create(['enqueued_at' => now()->subSeconds(10), 'waiting_messages' => []]);
    QueueEntry::updating(function (QueueEntry $e) use ($bad) {
        if ($e->id === $bad->id) {
            throw new RuntimeException('bad row');
        }
    });

    app(WaitEstimator::class)->tickLounge();

    Exceptions::assertReported(RuntimeException::class);
    expect($good->fresh()->waiting_messages)->toMatchArray(['5' => true])->and($bad->fresh()->waiting_messages)->toBe([]);
    Queue::assertPushed(SendQueueMessage::class, fn (SendQueueMessage $job) => $job->entryId === $good->id && $job->scriptKey === 'queue_left_5');
});

// ───── flow revision §2: no countdown without an estimate ─────

it('sends no countdown and keeps no estimate while nobody is logged in', function () {
    Queue::fake([SendQueueMessage::class]);
    tickSettings(['windows_per_moderator' => 1, 'eta_default_handle_seconds' => 600]);
    $shift = Shift::factory()->create();
    $m = tickMember($shift, ['status' => 'busy']);
    QueueEntry::factory()->create(['shift_id' => $shift->id, 'shift_member_id' => $m->id, 'assigned_user_id' => $m->user_id, 'status' => 'active', 'window_no' => 1, 'delivered_at' => now()->subSeconds(590)]);
    $e = QueueEntry::factory()->create(['enqueued_at' => now()->subSeconds(590), 'waiting_messages' => [], 'eta_seconds' => 10]);
    $m->user->forceFill(['last_seen_at' => now()->subMinutes(10)])->save(); // she closed the CRM

    app(WaitEstimator::class)->tickLounge();

    Queue::assertNotPushed(SendQueueMessage::class);
    expect($e->fresh()->eta_seconds)->toBeNull()->and($e->fresh()->waiting_messages)->toBe([]);
});
