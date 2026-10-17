<?php

use App\Models\QueueDay;
use App\Models\QueueEntry;
use App\Models\QueueSetting;
use App\Models\ScoreEvent;
use App\Models\User;
use App\Queue\QueueService;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Carbon;

beforeEach(function () {
    Carbon::setTestNow(Carbon::parse('2026-10-06 00:15', 'Africa/Cairo'));
    QueueSetting::factory()->create(['id' => 1, 'enabled' => true]);
});

it('repairs the points a lost event left out and archives yesterday into queue_days', function () {
    $u = User::factory()->create();
    $at = Carbon::parse('2026-10-05 15:00', 'Africa/Cairo');
    // Written straight to the table: no WindowClosed event ran, so no points were paid.
    $e = QueueEntry::factory()->create([
        'assigned_user_id' => $u->id, 'status' => 'closed', 'close_reason' => 'inquiry', 'business_date' => '2026-10-05',
        'delivered_at' => $at, 'first_reply_at' => $at->copy()->addSeconds(30), 'closed_at' => $at->copy()->addMinutes(5), 'review_stars' => 4,
    ]);
    QueueEntry::factory()->create(['assigned_user_id' => $u->id, 'status' => 'closed', 'close_reason' => 'no_reply', 'business_date' => '2026-10-05', 'delivered_at' => $at]);
    QueueEntry::factory()->create(['assigned_user_id' => $u->id, 'status' => 'closed', 'close_reason' => 'inquiry', 'business_date' => '2026-10-05', 'is_test' => true]);

    $this->artisan('queue:rollup')->assertSuccessful();
    $this->artisan('queue:rollup', ['date' => '2026-10-05'])->assertSuccessful(); // again: nothing twice

    expect(ScoreEvent::query()->where('user_id', $u->id)->orderBy('kind')->pluck('points', 'kind')->all())
        ->toBe(['base' => 8, 'no_reply' => -1, 'review' => 5, 'speed' => 6]);

    $day = QueueDay::query()->get()->first(fn (QueueDay $d) => $d->date->toDateString() === '2026-10-05');
    expect($day)->not->toBeNull()
        ->and($day->summary['rows'][0]['user']['id'])->toBe($u->id)
        ->and($day->summary['rows'][0]['total'])->toBe(18)
        ->and($day->summary['kpis']['windows'])->toBe(2)
        ->and($day->summary['generated_at'])->toBeString();
});

it('keeps the day ticket counter when it archives a day that already has one', function () {
    app(QueueService::class)->nextTicket('2026-10-05');
    app(QueueService::class)->nextTicket('2026-10-05');

    $this->artisan('queue:rollup', ['date' => '2026-10-05'])->assertSuccessful();

    expect(QueueDay::count())->toBe(1)->and(QueueDay::first()->next_ticket)->toBe(3)->and(QueueDay::first()->summary)->toBeArray();
});

it('is scheduled after Cairo midnight', function () {
    $events = collect(app(Schedule::class)->events())->filter(fn ($e) => str_contains((string) $e->command, 'queue:rollup'));

    expect($events)->toHaveCount(1)->and($events->first()->expression)->toBe('20 0 * * *')->and($events->first()->timezone)->toBe('Africa/Cairo');
});
