<?php

use App\Analytics\MetricsService;
use App\Enums\Platform;
use App\Enums\UserRole;
use App\Models\BotRun;
use App\Models\ChannelAccount;
use App\Models\Conversation;
use App\Models\SupportCase;
use App\Models\User;
use Carbon\CarbonImmutable;

// Reports → Bot "الفلوهات" table (MetricsService::flowUsage): started / finished /
// handovers / cases per guided flow, replayed from bot_runs + support_cases.

function flowConversation(Platform $platform = Platform::WhatsApp): Conversation
{
    $account = ChannelAccount::factory()->create(['platform' => $platform]);

    return Conversation::factory()->create(['channel_account_id' => $account->id, 'platform' => $platform]);
}

function flowRun(Conversation $c, string $at, ?string $intent, string $decision, string $engine = 'flow_engine'): void
{
    BotRun::factory()->create([
        'conversation_id' => $c->id, 'engine' => $engine, 'rule_id' => null, 'intent' => $intent,
        'decision' => $decision, 'created_at' => CarbonImmutable::parse("2026-09-10 {$at}", 'UTC'),
    ]);
}

function flowRows(?Platform $platform = null): array
{
    $m = app(MetricsService::class)->botMetrics(
        CarbonImmutable::parse('2026-09-10 00:00:00', 'UTC'),
        CarbonImmutable::parse('2026-09-10 23:59:59', 'UTC'),
        $platform,
    );

    return collect($m['flows'])->keyBy('key')->all();
}

beforeEach(function () {
    // A: main menu → return/exchange → a question mid-flow → the flow reaches its end and records a case.
    $a = flowConversation();
    flowRun($a, '10:00:00', 'main_menu', 'menu');
    flowRun($a, '10:01:00', 'return_exchange', 'button');
    flowRun($a, '10:02:00', 'return_exchange', 'flow');
    flowRun($a, '10:03:00', null, 'reply', engine: 'flow'); // the agent answered a question; the flow stays active
    flowRun($a, '10:04:00', null, 'flow');
    SupportCase::factory()->returnExchange()->create(['conversation_id' => $a->id, 'created_at' => CarbonImmutable::parse('2026-09-10 10:04:00', 'UTC')]);

    // B: main menu → complaint → handed over from inside the complaint flow.
    $b = flowConversation();
    flowRun($b, '11:00:00', 'main_menu', 'menu');
    flowRun($b, '11:01:00', 'complaint', 'button');
    flowRun($b, '11:02:00', null, 'handover');

    // C: main menu → "talk to a person"; a complaint case opened by the agent, not by the flow.
    $c = flowConversation();
    flowRun($c, '12:00:00', 'main_menu', 'menu');
    flowRun($c, '12:01:00', null, 'handover');
    SupportCase::factory()->create(['conversation_id' => $c->id, 'created_at' => CarbonImmutable::parse('2026-09-10 12:05:00', 'UTC')]);

    // D (Facebook): branches, still waiting; plus a run outside the period that must not count.
    $d = flowConversation(Platform::Facebook);
    flowRun($d, '13:00:00', 'branches', 'button');
    BotRun::factory()->create(['conversation_id' => $d->id, 'engine' => 'flow_engine', 'intent' => 'complaint', 'decision' => 'button', 'created_at' => CarbonImmutable::parse('2026-09-09 13:00:00', 'UTC')]);
});

it('counts started, finished, handed-over and case-recording conversations per flow', function () {
    $rows = flowRows();

    expect($rows['main_menu'])->toMatchArray(['started' => 3, 'finished' => 2, 'handovers' => 1, 'cases' => 0, 'records_cases' => false])
        ->and($rows['return_exchange'])->toMatchArray(['started' => 1, 'finished' => 1, 'handovers' => 0, 'cases' => 1, 'records_cases' => true])
        ->and($rows['complaint'])->toMatchArray(['started' => 1, 'finished' => 0, 'handovers' => 1, 'cases' => 0, 'records_cases' => true])
        ->and($rows['branches'])->toMatchArray(['started' => 1, 'finished' => 0, 'handovers' => 0])
        ->and($rows['main_menu']['title'])->toBe('القائمة الرئيسية')
        ->and($rows['order_tracking']['records_cases'])->toBeTrue() // its status step opens delivery follow-ups
        ->and(array_key_first($rows))->toBe('main_menu'); // most started first
});

it('filters the flows table by platform', function () {
    $rows = flowRows(Platform::Facebook);

    expect($rows['branches']['started'])->toBe(1)
        ->and($rows['main_menu']['started'])->toBe(0)
        ->and($rows['return_exchange']['cases'])->toBe(0);
});

it('shares the flows table on the bot report page', function () {
    $sup = User::factory()->create(['role' => UserRole::Supervisor]);

    $this->actingAs($sup)->get('/reports/bot?from=2026-09-10&to=2026-09-10')->assertOk()
        ->assertInertia(fn ($page) => $page->component('Reports/Bot')->has('metrics.flows'));
});
