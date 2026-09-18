<?php

use App\Models\UserNotification;
use Illuminate\Console\Scheduling\Schedule;

it('prunes read notifications after 30 days and unread ones after 90 (final fix wave M4)', function () {
    $readOld = UserNotification::factory()->create(['read_at' => now()->subDays(31), 'created_at' => now()->subDays(40)]);
    $readRecent = UserNotification::factory()->create(['read_at' => now()->subDays(29), 'created_at' => now()->subDays(100)]);
    $unreadOld = UserNotification::factory()->create(['read_at' => null, 'created_at' => now()->subDays(91)]);
    $unreadRecent = UserNotification::factory()->create(['read_at' => null, 'created_at' => now()->subDays(60)]);

    $this->artisan('crm:prune-user-notifications')->assertSuccessful();

    expect(UserNotification::find($readOld->id))->toBeNull()
        ->and(UserNotification::find($unreadOld->id))->toBeNull()
        ->and(UserNotification::find($readRecent->id))->not->toBeNull()
        ->and(UserNotification::find($unreadRecent->id))->not->toBeNull();
});

it('schedules the notification prune daily on one server without overlapping', function () {
    $event = collect(app(Schedule::class)->events())->first(fn ($e) => str_contains((string) $e->command, 'crm:prune-user-notifications'));

    expect($event)->not->toBeNull()
        ->and($event->expression)->toBe('0 0 * * *')
        ->and($event->withoutOverlapping)->toBeTrue()
        ->and($event->onOneServer)->toBeTrue();
});
