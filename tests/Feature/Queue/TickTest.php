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
        $m->shouldReceive('confirmDue')->once()->andReturnUsing($step('confirmDue', 0));
    });
    test()->mock(WaitEstimator::class, function ($m) use ($step) {
        $m->shouldReceive('tickWaiting')->andReturnUsing($step('tickWaiting'));
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

    // The overnight ticket gets no countdown: one tickWaiting for the one live customer.
    expect($order)->toBe(['transition', 'tickMembers', 'tickSilence', 'confirmDue', 'tickWaiting', 'router']);
});

it('keeps going when a step throws, and reports it', function (string $throwing) {
    Exceptions::fake();
    tickSettings();
    QueueEntry::factory()->count(2)->create();
    $order = [];
    tickSpies($order, $throwing);

    $this->artisan('queue:tick')->assertSuccessful();

    expect($order)->toBe(['transition', 'tickMembers', 'tickSilence', 'confirmDue', 'tickWaiting', 'tickWaiting', 'router']);
    Exceptions::assertReported(fn (RuntimeException $e) => $e->getMessage() === 'step '.$throwing.' failed');
    // The lock is given back, so the next tick runs.
    expect(Cache::lock('queue:tick', 5)->get())->toBeTrue();
})->with(['transition', 'tickMembers', 'tickSilence', 'confirmDue', 'tickWaiting', 'router']);

it('does nothing while the queue is switched off', function () {
    tickSettings(['enabled' => false]);
    $shift = Shift::factory()->create();
    tickMember($shift);
    $e = QueueEntry::factory()->create();
    foreach ([ShiftService::class, WindowLifecycle::class, WaitEstimator::class, QueueRouter::class] as $class) {
        $this->mock($class, fn ($m) => $m->shouldNotReceive('transition', 'tickMembers', 'tickSilence', 'confirmDue', 'tickWaiting', 'run'));
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
