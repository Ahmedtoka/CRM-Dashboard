<?php

use App\Ads\Alerts\Jobs\EvaluateAccountAlerts;
use App\Ads\Sync\SyncAdAccount;
use App\Models\AdsAlert;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Queue;
use Tests\Support\AlertWorld as W;

beforeEach(fn () => W::freeze());

function asEvent(string $needle)
{
    return collect(app(Schedule::class)->events())->first(fn ($e) => str_contains((string) $e->command, $needle));
}

it('schedules hourly at :30, daily at 08:30 and the digest at 09:00 Cairo', function () {
    expect(asEvent('ads:alerts --scope=hourly')->expression)->toBe('30 * * * *')
        ->and(asEvent('ads:alerts --scope=daily')->expression)->toBe('30 8 * * *')
        ->and(asEvent('ads:alerts-digest')->expression)->toBe('0 9 * * *')
        ->and(asEvent('ads:alerts-digest')->timezone)->toBe('Africa/Cairo');
});

it('dispatches one job per active account on the ads sync queue', function () {
    Queue::fake();
    $a = W::account();
    $b = W::account();
    W::account(['is_active' => false]);

    $this->artisan('ads:alerts', ['--scope' => 'hourly'])->assertSuccessful();

    Queue::assertPushedOn(SyncAdAccount::queueName(), EvaluateAccountAlerts::class);
    Queue::assertPushed(EvaluateAccountAlerts::class, 2);
    Queue::assertPushed(EvaluateAccountAlerts::class, fn (EvaluateAccountAlerts $j) => $j->accountId === $a->id && $j->schedule === 'hourly' && $j->uniqueId() === "ads-alerts-{$a->id}-hourly");
});

it('evaluates inline with --sync and wakes snoozed alerts first', function () {
    $acc = W::account();
    W::link(W::ad($acc), W::product([0]));
    $snoozed = AdsAlert::factory()->create(['state' => 'snoozed', 'snoozed_until' => now()->subMinute()]);

    $this->artisan('ads:alerts', ['--scope' => 'hourly', '--account' => [$acc->id], '--sync' => true])->assertSuccessful();

    expect(AdsAlert::where('rule_id', 'all.out_of_stock')->exists())->toBeTrue()->and($snoozed->fresh()->state)->toBe('open');
});

it('refuses an unknown scope', function () {
    $this->artisan('ads:alerts', ['--scope' => 'weekly'])->assertExitCode(2);
});

it('wakes snoozed alerts before building the 09:00 digest', function () {
    $snoozed = AdsAlert::factory()->create(['state' => 'snoozed', 'snoozed_until' => now()->subMinute()]);

    $this->artisan('ads:alerts-digest')->assertSuccessful();

    expect($snoozed->fresh()->state)->toBe('open');
});

it('skips the hourly run at 08:30 when the daily run covers every rule', function () {
    $hourly = asEvent('ads:alerts --scope=hourly');

    W::freeze('2026-10-06 08:30:00');
    expect($hourly->filtersPass(app()))->toBeFalse();
    W::freeze('2026-10-06 09:30:00');
    expect($hourly->filtersPass(app()))->toBeTrue();
});
