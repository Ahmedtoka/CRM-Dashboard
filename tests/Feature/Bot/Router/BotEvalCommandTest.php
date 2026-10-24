<?php

use App\Bot\Eval\BotEval;
use App\Models\BotRun;
use App\Models\Message;
use App\Models\Order;
use App\Models\SupportCase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

beforeEach(fn () => Event::fake());

it('runs every scenario offline: no network, nothing kept, every multi-return and safety scenario passing', function () {
    $report = app(BotEval::class)->run(base_path('tests/Fixtures/bot-eval/scenarios.yaml'));

    $failed = collect($report['scenarios'])->reject->ok->map(fn ($r) => $r['id'].'/'.$r['router'].': '.implode('; ', $r['last']['failures']))->values()->all();

    // The 44 of bot-hybrid.md on hybrid, the round-b1 complaints (45–71) and round 4 (72–81), most on both routers.
    expect($report['summary']['scenarios'])->toBe(117)
        ->and($report['summary']['multi_return_pass_rate'])->toBe(100.0)
        ->and($report['summary']['safety_pass_rate'])->toBe(100.0)
        ->and($report['summary']['pass_rate'])->toBeGreaterThanOrEqual(90.0, implode("\n", $failed))
        // Rolled back: no order, case, message or bot run of the eval is left behind.
        ->and(Order::count() + SupportCase::count() + Message::count() + BotRun::count())->toBe(0);
});

it('passes every round-b1 complaint scenario on both routers (stale taps, the close rule, one answer, handover balance)', function () {
    $report = app(BotEval::class)->run(base_path('tests/Fixtures/bot-eval/scenarios.yaml'), only: range(45, 71));
    $failed = collect($report['scenarios'])->reject->ok->map(fn ($r) => $r['id'].'/'.$r['router'].': '.implode('; ', $r['last']['failures']))->values()->all();

    expect($failed)->toBe([])
        ->and(collect($report['scenarios'])->pluck('router')->unique()->sort()->values()->all())->toBe(['hybrid', 'legacy']);
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

it('--live lets the Messages API calls go out for real (and nothing else)', function () {
    Http::preventStrayRequests();
    config(['crm.drivers.ai' => 'claude', 'crm.anthropic.key' => 'k']);
    Log::spy();

    app(BotEval::class)->run(base_path('tests/Fixtures/bot-eval/scenarios.yaml'), live: true, only: [14]);

    // A real request was attempted (the test forbids it, so it surfaces as a stray request).
    Log::shouldHaveReceived('info')->withArgs(fn ($message, $context = []) => $message === 'router.planner_failed' && str_contains((string) ($context['error'] ?? ''), 'api.anthropic.com'));
});

it('passes every round-4 scenario on both routers (after a rated close, consecutive taps, the script, the handover brief)', function () {
    $report = app(BotEval::class)->run(base_path('tests/Fixtures/bot-eval/scenarios.yaml'), only: range(72, 81));
    $failed = collect($report['scenarios'])->reject->ok->map(fn ($r) => $r['id'].'/'.$r['router'].': '.implode('; ', $r['last']['failures']))->values()->all();

    expect($failed)->toBe([])
        ->and(collect($report['scenarios'])->pluck('router')->unique()->sort()->values()->all())->toBe(['hybrid', 'legacy']);
});
