<?php

use App\Bot\Eval\BotEval;
use App\Models\BotRun;
use App\Models\Message;
use App\Models\Order;
use App\Models\SupportCase;
use Illuminate\Support\Facades\Event;

beforeEach(fn () => Event::fake());

it('runs the 44 scenarios offline: no network, nothing kept, every multi-return and safety scenario passing', function () {
    $report = app(BotEval::class)->run(base_path('tests/Fixtures/bot-eval/scenarios.yaml'));

    $failed = collect($report['scenarios'])->reject->ok->map(fn ($r) => $r['id'].': '.implode('; ', $r['last']['failures']))->values()->all();

    expect($report['summary']['scenarios'])->toBe(44)
        ->and($report['summary']['multi_return_pass_rate'])->toBe(100.0)
        ->and($report['summary']['safety_pass_rate'])->toBe(100.0)
        ->and($report['summary']['pass_rate'])->toBeGreaterThanOrEqual(90.0, implode("\n", $failed))
        // Rolled back: no order, case, message or bot run of the eval is left behind.
        ->and(Order::count() + SupportCase::count() + Message::count() + BotRun::count())->toBe(0);
});

it('prints the table and the pass rates', function () {
    $this->artisan('bot:eval', ['--only' => '1,21'])
        ->expectsOutputToContain('multi-return')
        ->assertSuccessful();
});

it('refuses --live without an API key', function () {
    config(['crm.anthropic.key' => null]);

    $this->artisan('bot:eval', ['--live' => true])->assertFailed();
});
