<?php

use App\Bot\Ai\SonnetBudget;
use App\Bot\Brain\Costs\BotCostReport;
use App\Enums\UserRole;
use App\Models\BotRun;
use App\Models\BotSetting;
use App\Models\Conversation;
use App\Models\User;
use App\Models\UserNotification;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Inertia\Testing\AssertableInertia as Assert;

// «التكلفة» (b2): spend from the bot's own records, split by model/tier, per conversation, the
// cache share, the owner's balance projection and the bell warnings.

beforeEach(function () {
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-10-19 12:00', 'Africa/Cairo'));
    $this->travelTo(CarbonImmutable::now());
});

afterEach(fn () => CarbonImmutable::setTestNow());

function bcRun(float $usd, string $when, array $attrs = []): BotRun
{
    $run = BotRun::factory()->create($attrs + ['cost_usd' => $usd, 'model' => 'claude-haiku-5-5', 'tier' => '1', 'engine' => 'hybrid', 'input_tokens' => 1000, 'cache_read_tokens' => 800]);
    $run->forceFill(['created_at' => CarbonImmutable::parse($when, 'Africa/Cairo')->utc()])->saveQuietly();

    return $run;
}

it('adds up today, the last 7 days and the month in Cairo days, with the shares', function () {
    $c = Conversation::factory()->create();
    bcRun(0.50, '2026-10-19 01:00', ['conversation_id' => $c->id]);           // today (Cairo), still 18 Oct in UTC
    bcRun(1.00, '2026-10-15 10:00', ['conversation_id' => $c->id, 'model' => 'claude-sonnet-5-5', 'tier' => '2']);
    bcRun(2.00, '2026-10-02 10:00', ['conversation_id' => $c->id]);           // this month, not this week
    bcRun(4.00, '2026-09-10 10:00', ['conversation_id' => $c->id]);           // last month, outside the 30 days

    $r = app(BotCostReport::class)->build();

    expect($r['spend']['today'])->toBe(0.5)
        ->and($r['spend']['week'])->toBe(1.5)
        ->and($r['spend']['month'])->toBe(3.5)
        ->and($r['spend']['avg_day_7'])->toBe(round(1.5 / 7, 4))
        ->and($r['by_model'][0])->toMatchArray(['model' => 'claude-haiku-5-5', 'tier' => '1', 'runs' => 2, 'usd' => 2.5])
        ->and($r['conversations']['count'])->toBe(1)
        ->and($r['conversations']['per_1000'])->toBe(3500.0)
        ->and($r['cache']['share'])->toBe(0.8)
        ->and($r['daily'])->toHaveCount(30)
        ->and(end($r['daily']))->toBe(['date' => '2026-10-19', 'usd' => 0.5]);
});

it('projects how many days the entered balance lasts at the 7-day average', function () {
    bcRun(0.70, '2026-10-18 10:00');
    bcRun(0.70, '2026-10-19 10:00');
    BotSetting::current()->update(['anthropic_balance_usd' => 10, 'anthropic_balance_at' => CarbonImmutable::parse('2026-10-19 00:00', 'Africa/Cairo')->utc()]);

    $b = app(BotCostReport::class)->build()['balance'];

    // 0.70 spent since the balance was read; 1.40 / 7 = 0.20 a day → 9.30 / 0.20 = 46.5 days.
    expect($b['set'])->toBeTrue()
        ->and($b['spent_since'])->toBe(0.7)
        ->and($b['left_usd'])->toBe(9.3)
        ->and($b['days_left'])->toBe(46.5)
        ->and($b['runs_out_on'])->toBe('2026-12-04')
        ->and($b['warn'])->toBeFalse();
});

it('never divides by zero: no balance, or no spend this week', function () {
    expect(app(BotCostReport::class)->build()['balance'])->toBe(['set' => false]);

    BotSetting::current()->update(['anthropic_balance_usd' => 100, 'anthropic_balance_at' => now()->subDay()]);
    $b = app(BotCostReport::class)->build()['balance'];

    expect($b['days_left'])->toBeNull()->and($b['warn'])->toBeFalse()->and($b['left_usd'])->toBe(100.0);
});

it('counts the test console spend, kept outside the rolled-back runs', function () {
    BotCostReport::addConsoleSpend(0.25);

    expect(app(BotCostReport::class)->build()['spend']['today'])->toBe(0.25);
});

it('estimates an all-legacy and a hybrid month at the current volume', function () {
    foreach (range(3, 9) as $i) {
        bcRun(0.002, "2026-10-1{$i} 10:00");
    }

    $s = app(BotCostReport::class)->build()['scenarios'];

    expect($s['month_turns'])->toBe(30)
        ->and($s['hybrid']['measured'])->toBeTrue()
        ->and($s['hybrid']['per_turn'])->toBe(0.002)
        ->and($s['legacy']['measured'])->toBeFalse()
        ->and($s['legacy']['month'])->toBeGreaterThan($s['hybrid']['month']);
});

it('warns the admins once a day when the balance runs low or the Sonnet cap is hit', function () {
    $admin = User::factory()->create(['role' => UserRole::Admin]);
    User::factory()->create(['role' => UserRole::Supervisor]);
    bcRun(3.00, '2026-10-19 10:00');
    BotSetting::current()->update(['anthropic_balance_usd' => 4, 'anthropic_balance_at' => now()->subHours(12)]);
    Cache::put(app(SonnetBudget::class)->key(), 6_000_000);

    $this->artisan('bot:cost-watch')->assertSuccessful();
    $this->artisan('bot:cost-watch')->assertSuccessful();

    expect(UserNotification::where('user_id', $admin->id)->pluck('type')->sort()->values()->all())->toBe(['bot.balance_low', 'bot.sonnet_cap'])
        ->and(UserNotification::count())->toBe(2);
});

it('shows the cost page to supervisors and lets only an admin enter the balance', function () {
    $sup = User::factory()->create(['role' => UserRole::Supervisor]);
    $admin = User::factory()->create(['role' => UserRole::Admin]);

    $this->actingAs($sup)->get('/bot/costs')->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('Bot/Costs')->where('canEditBalance', false)->where('nowLocal', '2026-10-19T12:00')->has('report.spend'));
    $this->actingAs($sup)->putJson('/settings/bot-balance', ['balance_usd' => 100])->assertForbidden();

    $this->actingAs($admin)->putJson('/settings/bot-balance', ['balance_usd' => 100, 'balance_at' => '2026-10-19 09:00'])->assertOk();
    expect((float) BotSetting::current()->anthropic_balance_usd)->toBe(100.0)
        ->and(BotSetting::current()->anthropic_balance_at->setTimezone('Africa/Cairo')->format('Y-m-d H:i'))->toBe('2026-10-19 09:00');

    $this->actingAs($admin)->putJson('/settings/bot-balance', ['balance_usd' => null])->assertOk();
    expect(BotSetting::current()->anthropic_balance_usd)->toBeNull();
    $this->actingAs(User::factory()->create(['role' => UserRole::Moderator]))->get('/bot/costs')->assertForbidden();
});

it('counts the cache share per run even where the hybrid router recorded no input tokens', function () {
    bcRun(0.001, '2026-10-19 10:00', ['input_tokens' => 0, 'cache_read_tokens' => 3000, 'cache_write_tokens' => 1000]);
    bcRun(0.001, '2026-10-19 10:05', ['input_tokens' => 5000, 'cache_read_tokens' => 4000]);

    // (3000 + 4000) read of (4000 + 5000) read in all.
    expect(app(BotCostReport::class)->build()['cache']['share'])->toBe(round(7000 / 9000, 3));
});

it('counts the learning reviews and the nightly analyst, paid by the same key', function () {
    $c = Conversation::factory()->create();
    DB::table('bot_learning_notes')->insert(['conversation_id' => $c->id, 'cost_usd' => 0.02, 'created_at' => now(), 'updated_at' => now()] + bcNoteDefaults());
    DB::table('bot_learning_reports')->insert(['report_date' => '2026-10-19', 'summary' => '', 'stats' => '[]', 'model' => 'claude-sonnet-5-5', 'input_tokens' => 100_000, 'output_tokens' => 10_000, 'created_at' => now(), 'updated_at' => now()]);

    // 0.02 + (100k × $2 + 10k × $10) / 1M = 0.02 + 0.3
    expect(app(BotCostReport::class)->build()['spend']['today'])->toBe(0.32);
});

it('takes the balance time as Cairo time and never later than now', function () {
    $admin = User::factory()->create(['role' => UserRole::Admin]);

    $this->actingAs($admin)->putJson('/settings/bot-balance', ['balance_usd' => 50, 'balance_at' => '2026-10-19 14:30'])->assertOk();

    expect(BotSetting::current()->anthropic_balance_at->equalTo(now()))->toBeTrue();
});

/** The NOT NULL columns of a learning note other than its conversation and cost. */
function bcNoteDefaults(): array
{
    $cols = collect(Schema::getColumns('bot_learning_notes'))
        ->filter(fn ($col) => ! $col['nullable'] && $col['default'] === null && ! $col['auto_increment'] && ! in_array($col['name'], ['conversation_id', 'cost_usd', 'created_at', 'updated_at'], true));

    return $cols->mapWithKeys(fn ($col) => [$col['name'] => str_contains((string) $col['type_name'], 'int') ? 0 : ''])->all();
}
