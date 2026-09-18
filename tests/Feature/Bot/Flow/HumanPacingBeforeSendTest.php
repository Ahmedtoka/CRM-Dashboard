<?php

use App\Bot\Flow\HumanPacing;
use App\Bot\Flow\ReplyScheduler;
use App\Models\Conversation;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Sleep;

it('turns typing on, then waits only the remaining delay', function () {
    Sleep::fake();
    $events = [];

    $scheduler = Mockery::mock(ReplyScheduler::class);
    $scheduler->shouldReceive('typing')->once()->withArgs(fn ($c, $on) => $on === true)
        ->andReturnUsing(function () use (&$events) {
            $events[] = 'typing';
        });
    app()->instance(ReplyScheduler::class, $scheduler);
    Sleep::whenFakingSleep(function ($duration) use (&$events) {
        $events[] = 'sleep:'.(int) $duration->totalMilliseconds;
    });

    // 100 chars × 35 ms = 3500 ms, 1000 ms already spent → 2500 ms left.
    (new HumanPacing)->beforeSend(new Conversation, str_repeat('ا', 100), 35, 1000);

    expect($events)->toBe(['typing', 'sleep:2500']);
    Sleep::assertSleptTimes(1);
});

it('never waits when the time spent already covers the delay, and swallows typing failures quietly', function () {
    Sleep::fake();
    Exceptions::fake();

    $scheduler = Mockery::mock(ReplyScheduler::class);
    $scheduler->shouldReceive('typing')->once()->andThrow(new RuntimeException('graph down'));
    app()->instance(ReplyScheduler::class, $scheduler);

    (new HumanPacing)->beforeSend(new Conversation, 'اه', 35, 5000);

    Sleep::assertNeverSlept();
    Exceptions::assertNothingReported();
});
