<?php

use App\Ads\Health\QueueHeartbeat;
use App\Ads\Sync\SyncAdAccount;
use Illuminate\Console\Scheduling\CallbackEvent;
use Illuminate\Console\Scheduling\Schedule;

it('gives every queue heartbeat its own schedule mutex', function () {
    $beats = collect(app(Schedule::class)->events())->filter(fn ($e) => $e instanceof CallbackEvent && str_starts_with((string) $e->description, 'ads:queue-heartbeat:'));

    $queues = array_values(array_unique(['default', 'commercelong', SyncAdAccount::queueName()]));
    expect($beats)->toHaveCount(count($queues))
        ->and($beats->map->mutexName()->unique())->toHaveCount(count($queues))
        ->and($beats->pluck('description')->sort()->values()->all())->toBe(collect($queues)->map(fn ($q) => "ads:queue-heartbeat:{$q}")->sort()->values()->all());
    expect(class_exists(QueueHeartbeat::class))->toBeTrue();
});

it('refreshes creatives at 05:50 Cairo, after Arena', function () {
    $e = collect(app(Schedule::class)->events())->first(fn ($e) => str_contains((string) $e->command, 'ads:refresh-creatives'));

    expect($e->expression)->toBe('50 5 * * *')->and($e->timezone)->toBe('Africa/Cairo');
});
