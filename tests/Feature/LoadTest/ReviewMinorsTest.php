<?php

use App\Enums\Platform;
use App\Maintenance\FreshStart;
use App\Models\ChannelAccount;
use App\Models\LoadTestRun;
use App\Models\Order;
use App\Simulator\LoadTest\LoadTest;
use App\Simulator\LoadTest\LoadTestChannels;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Queue;

beforeEach(fn () => Carbon::setTestNow(Carbon::parse('2026-10-07 10:00', 'Africa/Cairo')));

it('prints the planned total at start and never sends more than the cap', function () {
    config(['crm.load_test' => true]);
    Queue::fake();

    $this->artisan('crm:load-test', ['action' => 'start', '--every' => 30, '--count' => 1000, '--hours' => 24, '--spread' => 10])
        ->expectsOutputToContain('Planned in all: 5000 new chats')
        ->assertSuccessful();

    $loadTest = app(LoadTest::class);
    for ($m = 30; $m <= 24 * 60; $m += 30) {
        Carbon::setTestNow(Carbon::parse('2026-10-07 10:00', 'Africa/Cairo')->addMinutes($m));
        $loadTest->tick();
    }

    expect(LoadTestRun::active()->openers_sent)->toBe(LoadTest::MAX_OPENERS)
        ->and(LoadTestRun::active()->next_wave_at)->toBeNull();
});

it('only runs the scheduler tick while the gate is on', function () {
    $event = collect(app(Schedule::class)->events())->first(fn ($e) => str_contains((string) $e->command, 'crm:load-test'));

    config(['crm.load_test' => false]);
    expect($event->filtersPass(app()))->toBeFalse();

    config(['crm.load_test' => true]);
    expect($event->filtersPass(app()))->toBeTrue();
});

it('keeps the test channels out of the health check', function () {
    config(['crm.health.token' => 'h-token']);
    ChannelAccount::factory()->create(['platform' => Platform::Facebook, 'status' => 'error', 'driver' => 'live']);
    LoadTestChannels::ensureAll(); // connected, created later: must not mask the real page's error

    $this->getJson('/up/crm', ['X-Health-Token' => 'h-token'])->assertOk()->assertJsonPath('channels.facebook', 'error');
});

it('deletes the test channels and their test orders on fresh start, keeping real channels', function () {
    $real = ChannelAccount::factory()->create(['platform' => Platform::Facebook, 'driver' => 'live']);
    LoadTestChannels::ensureAll();
    Order::factory()->create(['is_load_test' => true]);

    $result = app(FreshStart::class)->wipe();

    expect(ChannelAccount::query()->pluck('id')->all())->toBe([$real->id])
        ->and($result['deleted']['channel_accounts (load test)'])->toBe(3)
        ->and(Order::withLoadTest()->count())->toBe(0);
});
