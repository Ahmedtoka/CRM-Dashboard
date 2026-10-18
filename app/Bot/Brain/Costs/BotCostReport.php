<?php

namespace App\Bot\Brain\Costs;

use App\Bot\Ai\ModelPricing;
use App\Bot\Ai\SonnetBudget;
use App\Models\BotSetting;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * «التكلفة» (b2, 2026-10-19): what the bot's model calls cost, from the bot's own records —
 * bot_runs.cost_usd (every live turn), bot_shadow_plans.cost_usd (the planner trying in the
 * background) and the test console's spend (its runs are rolled back, so it keeps a day counter).
 * Days are Cairo days. Anthropic has no API for the prepaid balance: the owner types it, and the
 * projection subtracts what was recorded since.
 */
class BotCostReport
{
    public const TZ = 'Africa/Cairo';

    /** Projected days at or below this warn in the bell. */
    public const WARN_DAYS = 3;

    /**
     * A typical free-text turn when there is not enough history to measure one (documented in the
     * guide): two agent rounds of ~5,000 cached system tokens + ~600 fresh input + ~150 output, and
     * for hybrid a planner call of ~1,500 cached + ~300 input + ~60 output, with 85% of turns on
     * tier 1 (Haiku) and 15% on tier 2 (Sonnet).
     */
    private const TYPICAL_ROUND = ['in' => 600, 'out' => 150, 'cache_read' => 5000];

    private const TYPICAL_PLANNER = ['in' => 300, 'out' => 60, 'cache_read' => 1500];

    private const TIER2_SHARE = 0.15;

    private const MIN_SAMPLES = 5;

    /** @return array<string, mixed> */
    public function build(?CarbonImmutable $now = null): array
    {
        $now ??= CarbonImmutable::now();
        $today = $now->setTimezone(self::TZ)->startOfDay();
        $week = $today->subDays(6);
        $month = $today->startOfMonth();
        $last30 = $today->subDays(29);

        $avg7 = $this->spend($week, $now) / 7;

        return [
            'spend' => [
                'today' => $this->spend($today, $now),
                'week' => $this->spend($week, $now),
                'month' => $this->spend($month, $now),
                'avg_day_7' => round($avg7, 4),
            ],
            'daily' => $this->daily($last30, $now),
            'by_model' => $this->byModel($last30, $now),
            'conversations' => $this->perConversation($last30, $now),
            'cache' => $this->cache($last30, $now),
            'balance' => $this->balance($avg7, $now),
            'sonnet' => $this->sonnet(),
            'scenarios' => $this->scenarios($week, $now),
            'console_today' => self::consoleSpentOn($today),
        ];
    }

    /** USD recorded between two moments: live turns + shadow planner + test console. */
    public function spend(CarbonImmutable $from, CarbonImmutable $to): float
    {
        $runs = (float) DB::table('bot_runs')->whereBetween('created_at', [$from->utc(), $to->utc()])->sum('cost_usd');
        $shadow = Schema::hasTable('bot_shadow_plans')
            ? (float) DB::table('bot_shadow_plans')->whereBetween('created_at', [$from->utc(), $to->utc()])->sum('cost_usd')
            : 0.0;

        $console = 0.0;
        for ($d = $from->setTimezone(self::TZ)->startOfDay(); $d->lte($to); $d = $d->addDay()) {
            $console += self::consoleSpentOn($d);
        }

        return round($runs + $shadow + $console, 4);
    }

    /** The test console's spend, kept per Cairo day (its runs are rolled back). */
    public static function addConsoleSpend(float $usd): void
    {
        $micro = (int) round($usd * 1_000_000);

        if ($micro <= 0) {
            return;
        }

        $key = self::consoleKey(CarbonImmutable::now(self::TZ));
        Cache::add($key, 0, now()->addDays(40));
        Cache::increment($key, $micro);
    }

    public static function consoleSpentOn(CarbonImmutable $day): float
    {
        return ((int) Cache::get(self::consoleKey($day), 0)) / 1_000_000;
    }

    private static function consoleKey(CarbonImmutable $day): string
    {
        return 'bot:console-spend:'.$day->setTimezone(self::TZ)->toDateString();
    }

    /** @return list<array{date: string, usd: float}> */
    private function daily(CarbonImmutable $from, CarbonImmutable $now): array
    {
        $rows = DB::table('bot_runs')->whereBetween('created_at', [$from->utc(), $now->utc()])->where('cost_usd', '>', 0)->get(['created_at', 'cost_usd']);

        if (Schema::hasTable('bot_shadow_plans')) {
            $rows = $rows->concat(DB::table('bot_shadow_plans')->whereBetween('created_at', [$from->utc(), $now->utc()])->where('cost_usd', '>', 0)->get(['created_at', 'cost_usd']));
        }

        $byDay = [];

        foreach ($rows as $r) {
            $day = CarbonImmutable::parse($r->created_at, 'UTC')->setTimezone(self::TZ)->toDateString();
            $byDay[$day] = ($byDay[$day] ?? 0) + (float) $r->cost_usd;
        }

        $out = [];
        for ($d = $from; $d->lte($now); $d = $d->addDay()) {
            $out[] = ['date' => $d->toDateString(), 'usd' => round(($byDay[$d->toDateString()] ?? 0) + self::consoleSpentOn($d), 4)];
        }

        return $out;
    }

    /** @return list<array{model: ?string, tier: ?string, runs: int, usd: float}> */
    private function byModel(CarbonImmutable $from, CarbonImmutable $now): array
    {
        $rows = DB::table('bot_runs')
            ->whereBetween('created_at', [$from->utc(), $now->utc()])
            ->where('cost_usd', '>', 0)
            ->groupBy('model', 'tier')
            ->selectRaw('model, tier, count(*) as runs, sum(cost_usd) as usd')
            ->orderByDesc('usd')
            ->get()
            ->map(fn ($r) => ['model' => $r->model, 'tier' => $r->tier, 'runs' => (int) $r->runs, 'usd' => round((float) $r->usd, 4)])
            ->all();

        if (Schema::hasTable('bot_shadow_plans')) {
            $shadow = DB::table('bot_shadow_plans')->whereBetween('created_at', [$from->utc(), $now->utc()])->where('cost_usd', '>', 0)
                ->selectRaw('count(*) as runs, sum(cost_usd) as usd, max(model) as model')->first();

            if ((int) ($shadow->runs ?? 0) > 0) {
                $rows[] = ['model' => $shadow->model, 'tier' => 'shadow', 'runs' => (int) $shadow->runs, 'usd' => round((float) $shadow->usd, 4)];
            }
        }

        return $rows;
    }

    /** @return array{count: int, usd: float, per_conversation: float, per_1000: float} */
    private function perConversation(CarbonImmutable $from, CarbonImmutable $now): array
    {
        $row = DB::table('bot_runs')->whereBetween('created_at', [$from->utc(), $now->utc()])->whereNotNull('conversation_id')
            ->selectRaw('count(distinct conversation_id) as n, sum(cost_usd) as usd')->first();
        $n = (int) ($row->n ?? 0);
        $usd = (float) ($row->usd ?? 0);
        $per = $n > 0 ? $usd / $n : 0.0;

        return ['count' => $n, 'usd' => round($usd, 4), 'per_conversation' => round($per, 5), 'per_1000' => round($per * 1000, 2)];
    }

    /** @return array{read: int, input: int, share: float} share of input tokens served from the prompt cache */
    private function cache(CarbonImmutable $from, CarbonImmutable $now): array
    {
        $row = DB::table('bot_runs')->whereBetween('created_at', [$from->utc(), $now->utc()])
            ->selectRaw('sum(coalesce(cache_read_tokens, 0)) as r, sum(input_tokens) as i')->first();
        $read = (int) ($row->r ?? 0);
        $input = (int) ($row->i ?? 0);

        return ['read' => $read, 'input' => $input, 'share' => $input > 0 ? round(min(1, $read / $input), 3) : 0.0];
    }

    /** @return array<string, mixed> */
    private function balance(float $avg7, CarbonImmutable $now): array
    {
        $s = BotSetting::current();

        if ($s->anthropic_balance_usd === null || $s->anthropic_balance_at === null) {
            return ['set' => false];
        }

        $at = CarbonImmutable::parse($s->anthropic_balance_at);
        $spent = $at->lt($now) ? $this->spend($at, $now) : 0.0;
        $left = max(0.0, (float) $s->anthropic_balance_usd - $spent);
        $days = $avg7 > 0 ? $left / $avg7 : null;

        return [
            'set' => true,
            'entered_usd' => (float) $s->anthropic_balance_usd,
            'entered_at' => $at->toIso8601String(),
            'spent_since' => round($spent, 4),
            'left_usd' => round($left, 2),
            'days_left' => $days !== null ? round($days, 1) : null,
            'runs_out_on' => $days !== null ? $now->setTimezone(self::TZ)->addDays((int) floor($days))->toDateString() : null,
            'warn' => $days !== null && $days < self::WARN_DAYS,
        ];
    }

    /** @return array{spent_today: float, cap: float, hit: bool} */
    private function sonnet(): array
    {
        $budget = app(SonnetBudget::class);
        $cap = (float) config('crm.bot.router.daily_sonnet_cap_usd', 5);

        return ['spent_today' => round($budget->spentToday(), 4), 'cap' => $cap, 'hit' => $budget->exceeded()];
    }

    /**
     * What a month would cost with every free-text turn on the legacy agent (Sonnet) vs the hybrid
     * router (planner + Haiku, Sonnet for the hard ones), at the last 7 days' volume of model turns.
     * Each per-turn cost is measured from the last 30 days when there are enough turns of that kind,
     * else estimated from the typical turn above.
     *
     * @return array<string, mixed>
     */
    private function scenarios(CarbonImmutable $week, CarbonImmutable $now): array
    {
        $turns = DB::table('bot_runs')->whereBetween('created_at', [$week->utc(), $now->utc()])->where('cost_usd', '>', 0)->count();
        $monthTurns = (int) round($turns * 30 / 7);
        $from = $now->subDays(30)->utc();

        $legacy = DB::table('bot_runs')->where('created_at', '>=', $from)->where('tier', 'legacy')->where('cost_usd', '>', 0)
            ->selectRaw('count(*) as n, avg(cost_usd) as a')->first();
        $hybrid = DB::table('bot_runs')->where('created_at', '>=', $from)->where('engine', 'hybrid')->where('cost_usd', '>', 0)
            ->selectRaw('count(*) as n, avg(cost_usd) as a')->first();

        $legacyTurn = (int) $legacy->n >= self::MIN_SAMPLES ? (float) $legacy->a : $this->typicalTurn((string) config('crm.bot.agent.model', 'claude-sonnet-5-5'));
        $haiku = (string) config('crm.bot.router.tiers.1.model', 'claude-haiku-5-5');
        $sonnet = (string) config('crm.bot.router.tiers.2.model', 'claude-sonnet-5-5');
        $planner = $this->cost((string) config('crm.bot.router.planner.model', 'claude-haiku-5-5'), self::TYPICAL_PLANNER);
        $hybridTurn = (int) $hybrid->n >= self::MIN_SAMPLES
            ? (float) $hybrid->a
            : $planner + (1 - self::TIER2_SHARE) * $this->typicalTurn($haiku) + self::TIER2_SHARE * $this->typicalTurn($sonnet);

        return [
            'month_turns' => $monthTurns,
            'legacy' => ['per_turn' => round($legacyTurn, 5), 'month' => round($legacyTurn * $monthTurns, 2), 'measured' => (int) $legacy->n >= self::MIN_SAMPLES],
            'hybrid' => ['per_turn' => round($hybridTurn, 5), 'month' => round($hybridTurn * $monthTurns, 2), 'measured' => (int) $hybrid->n >= self::MIN_SAMPLES],
        ];
    }

    private function typicalTurn(string $model): float
    {
        return 2 * $this->cost($model, self::TYPICAL_ROUND);
    }

    /** @param  array{in: int, out: int, cache_read: int}  $tokens */
    private function cost(string $model, array $tokens): float
    {
        return ModelPricing::cost($model, $tokens['in'], $tokens['out'], $tokens['cache_read']);
    }
}
