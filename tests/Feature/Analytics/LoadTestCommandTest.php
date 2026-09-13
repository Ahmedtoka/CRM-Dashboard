<?php

use Illuminate\Support\Facades\Http;

beforeEach(function () {
    // No real sleeps in tests (ruling): bind a no-op sleeper.
    app()->bind('crm.loadtest.sleeper', fn () => function (int $micros): void {});
});

it('sends signed meta webhooks at the requested rate and refuses production', function () {
    config(['crm.meta.app_secret' => 'sec']);
    Http::fake(['staging.test/*' => Http::response('EVENT_RECEIVED', 200)]);

    $this->artisan('crm:loadtest', ['--url' => 'https://staging.test', '--rate' => 5, '--duration' => 2])->assertSuccessful();

    Http::assertSentCount(10);
    Http::assertSent(fn ($r) => str_starts_with($r->header('X-Hub-Signature-256')[0] ?? '', 'sha256='));

    app()->detectEnvironment(fn () => 'production');

    $this->artisan('crm:loadtest', ['--url' => 'https://staging.test', '--rate' => 1, '--duration' => 1])->assertFailed();
});

it('allows production runs when --force is passed', function () {
    config(['crm.meta.app_secret' => 'sec']);
    Http::fake(['staging.test/*' => Http::response('EVENT_RECEIVED', 200)]);
    app()->detectEnvironment(fn () => 'production');

    $this->artisan('crm:loadtest', ['--url' => 'https://staging.test', '--rate' => 2, '--duration' => 1, '--force' => true])
        ->assertSuccessful();

    Http::assertSentCount(2);
});

it('counts non-2xx responses without failing the command', function () {
    config(['crm.meta.app_secret' => 'sec']);
    Http::fake(['staging.test/*' => Http::response('nope', 500)]);

    $this->artisan('crm:loadtest', ['--url' => 'https://staging.test', '--rate' => 3, '--duration' => 1])
        ->assertSuccessful();

    Http::assertSentCount(3);
});

it('paces each second to the remaining time budget instead of a flat 1s sleep', function () {
    $recordedMicros = [];

    app()->bind('crm.loadtest.sleeper', function () use (&$recordedMicros) {
        return function (int $micros) use (&$recordedMicros): void {
            $recordedMicros[] = $micros;
        };
    });

    config(['crm.meta.app_secret' => 'sec']);
    Http::fake(['staging.test/*' => Http::response('EVENT_RECEIVED', 200)]);

    $this->artisan('crm:loadtest', ['--url' => 'https://staging.test', '--rate' => 2, '--duration' => 3])
        ->assertSuccessful();

    // One sleep between each pair of seconds, never after the last second.
    expect($recordedMicros)->toHaveCount(2);

    foreach ($recordedMicros as $micros) {
        // max(0, 1_000_000 - elapsed): never negative, never more than a full second.
        expect($micros)->toBeGreaterThanOrEqual(0)->and($micros)->toBeLessThanOrEqual(1_000_000);
    }
});
