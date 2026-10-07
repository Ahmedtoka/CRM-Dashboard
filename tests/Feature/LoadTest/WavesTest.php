<?php

use App\Channels\Jobs\ProcessWebhookEvent;
use App\Enums\Platform;
use App\Models\Conversation;
use App\Models\LoadTestRun;
use App\Models\WebhookEvent;
use App\Simulator\LoadTest\LoadTest;
use App\Simulator\Simulator;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    Carbon::setTestNow(Carbon::parse('2026-10-07 10:00', 'Africa/Cairo'));
    config(['crm.load_test' => true]);
});

/** Delays (seconds from now) of the queued webhook jobs, in push order. */
function wvDelays(): array
{
    return Queue::pushed(ProcessWebhookEvent::class)->map(function (ProcessWebhookEvent $job) {
        $d = $job->delay;

        return $d === null ? 0 : (int) round(now()->diffInSeconds($d, false));
    })->values()->all();
}

it('emits the first wave at start, spread over the spread minutes through the webhook pipeline', function () {
    Queue::fake();

    $this->artisan('crm:load-test', ['action' => 'start', '--every' => 30, '--count' => 4, '--hours' => 3, '--spread' => 10])
        ->expectsOutputToContain('Wave 1')
        ->assertSuccessful();

    $run = LoadTestRun::active();
    expect($run->plan)->toBe(['every' => 30, 'count' => 4, 'hours' => 3, 'spread' => 10])
        ->and($run->waves_done)->toBe(1)
        ->and($run->openers_sent)->toBe(4)
        ->and($run->next_wave_at->equalTo(now()->addMinutes(30)))->toBeTrue()
        ->and(wvDelays())->toBe([0, 150, 300, 450])
        ->and(WebhookEvent::count())->toBe(4);

    foreach (WebhookEvent::all() as $e) {
        expect($e->payload['loadtest'])->toBeTrue()
            ->and($e->payload['load_test']['run'])->toBe($run->id)
            ->and($e->payload['events'][0]['channel_id'])->toStartWith('loadtest-');
    }
});

it('emits one wave every `every` minutes for `hours`, then stops on its own', function () {
    Queue::fake();
    $this->artisan('crm:load-test', ['action' => 'start', '--every' => 30, '--count' => 3, '--hours' => 3, '--spread' => 5])->assertSuccessful();
    $loadTest = app(LoadTest::class);

    $emitted = [];
    for ($minute = 1; $minute <= 240; $minute++) {
        Carbon::setTestNow(Carbon::parse('2026-10-07 10:00', 'Africa/Cairo')->addMinutes($minute));
        if ($loadTest->tick() > 0) {
            $emitted[] = $minute;
        }
    }

    $run = LoadTestRun::active();
    expect($emitted)->toBe([30, 60, 90, 120, 150]) // + the wave at start = 6 waves in 3 hours
        ->and($run->waves_done)->toBe(6)
        ->and($run->openers_sent)->toBe(18)
        ->and($run->next_wave_at)->toBeNull()
        ->and(Queue::pushed(ProcessWebhookEvent::class))->toHaveCount(18);
});

it('catches up one wave after a scheduler gap instead of flooding', function () {
    Queue::fake();
    $this->artisan('crm:load-test', ['action' => 'start', '--every' => 10, '--count' => 2, '--hours' => 2, '--spread' => 0])->assertSuccessful();

    Carbon::setTestNow(now()->addMinutes(35)); // three waves were due
    expect(app(LoadTest::class)->tick())->toBe(2)
        ->and(app(LoadTest::class)->tick())->toBe(0)
        ->and(LoadTestRun::active()->next_wave_at->equalTo(now()->addMinutes(10)))->toBeTrue();
});

it('is a no-op tick without an active plan', function () {
    Queue::fake();

    $this->artisan('crm:load-test', ['action' => 'tick'])->assertSuccessful();

    expect(LoadTestRun::count())->toBe(0)->and(Queue::pushed(ProcessWebhookEvent::class))->toHaveCount(0);
});

it('stops the plan: no more waves, and openers still queued are dropped when they run', function () {
    $this->artisan('crm:load-test', ['action' => 'start', '--every' => 30, '--count' => 3, '--hours' => 3, '--spread' => 0])->assertSuccessful();
    // The queue is sync in tests: the first wave already arrived.
    expect(Conversation::count())->toBe(3);

    // A wave's opener still waiting on the queue when the owner stops the test.
    $run = LoadTestRun::active();
    $late = app(Simulator::class)->queueCustomerMessage(Platform::Facebook, 'late-1', 'هبة', 'بكام؟', loadTest: ['run' => $run->id, 'scenario' => 'price', 'name' => 'هبة', 'customer_key' => 'late-1']);
    expect(Conversation::count())->toBe(4);

    $this->artisan('crm:load-test', ['action' => 'stop'])->expectsOutputToContain('Stopped')->assertSuccessful();
    $another = app(Simulator::class)->queueCustomerMessage(Platform::Facebook, 'late-2', 'هبة', 'بكام؟', loadTest: ['run' => $run->id, 'scenario' => 'price', 'name' => 'هبة', 'customer_key' => 'late-2']);

    Carbon::setTestNow(now()->addMinutes(31));
    expect(app(LoadTest::class)->tick())->toBe(0)
        ->and(Conversation::count())->toBe(4)
        ->and($another->fresh()->status)->toBe('processed')
        ->and($run->fresh()->status)->toBe('stopped')
        ->and($run->fresh()->stopped_at)->not->toBeNull();
});

it('prints the plan, the counters and the test chats', function () {
    $this->artisan('crm:load-test', ['action' => 'seed-yesterday', '--count' => 2])->assertSuccessful();
    $this->artisan('crm:load-test', ['action' => 'start', '--every' => 30, '--count' => 3, '--hours' => 3, '--spread' => 0])->assertSuccessful();
    Conversation::query()->latest('id')->first()->forceFill(['status' => 'resolved', 'resolved_at' => now()])->save();

    $this->artisan('crm:load-test', ['action' => 'status'])
        ->expectsOutputToContain('Run #'.LoadTestRun::active()->id.' active')
        ->expectsOutputToContain('every 30 min, 3 per wave, spread 0 min, for 3 h')
        ->expectsOutputToContain('Waves: 1 of 6')
        ->expectsOutputToContain('Openers sent: 3')
        ->expectsOutputToContain('Backlog seeded: 2')
        ->expectsOutputToContain('Follow-ups sent: 0')
        ->expectsOutputToContain('Test chats: 5 (open 4, closed 1)')
        ->assertSuccessful();
});

it('says so when there is nothing to stop or report', function () {
    $this->artisan('crm:load-test', ['action' => 'status'])->expectsOutputToContain('No active load test')->assertSuccessful();
    $this->artisan('crm:load-test', ['action' => 'stop'])->expectsOutputToContain('No active load test')->assertSuccessful();
});

it('validates the plan', function () {
    $this->artisan('crm:load-test', ['action' => 'start', '--every' => 0])->assertFailed();
    $this->artisan('crm:load-test', ['action' => 'start', '--count' => 0])->assertFailed();
    $this->artisan('crm:load-test', ['action' => 'start', '--every' => 10, '--spread' => 11])->assertFailed();

    expect(LoadTestRun::count())->toBe(0);
});

it('runs the tick every minute on one server without overlapping', function () {
    $event = collect(app(Schedule::class)->events())
        ->first(fn ($e) => str_contains((string) $e->command, 'crm:load-test') && str_contains((string) $e->command, 'tick'));

    expect($event)->not->toBeNull()
        ->and($event->expression)->toBe('* * * * *')
        ->and($event->onOneServer)->toBeTrue()
        ->and($event->withoutOverlapping)->toBeTrue();
});
