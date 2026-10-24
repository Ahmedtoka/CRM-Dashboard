<?php

namespace App\Bot\Router;

use App\Bot\Ai\SonnetBudget;
use App\Bot\ArabicNormalizer;
use App\Models\Conversation;

/**
 * Which tier answers a planned turn (bot-hybrid.md §3.5): tier 1 (Haiku) by default, tier 2
 * (Sonnet) when any of E1–E6 holds, a person straight away for more than `max_tasks` requests
 * (owner B5), when she asks for one, or when she is angry and threatens.
 *
 *   E1  3+ tasks, or 2+ kinds with a complaint among them
 *   E2  angry (or negative with a threat word) about an order
 *   E3  a policy edge (14 days, discount, charged twice, missing/wrong piece, late refund)
 *   E4  the planner is unsure (< 0.6), or it already had to ask what she means
 *   E5  (at runtime) tier 1 called `escalate`, refused, or failed a guard
 *   E6  she asks the same thing again
 *
 * Past the day's Sonnet cap (owner B3) tier 2 becomes tier 1.
 */
class EscalationPolicy
{
    /** Normalized threat words: anger with one of these goes straight to a person (high). */
    private const THREATS = ['فضيحه', 'هشتكي', 'هشتكيكم', 'حماية المستهلك', 'محامي', 'قضيه', 'بوست', 'هفضحكم'];

    private const TOUCHY = ['حقي', 'نصب', 'حرامي', 'زفت'];

    public function __construct(private readonly ArabicNormalizer $normalizer = new ArabicNormalizer) {}

    /** @return array{tier: '1'|'2'|'handover', reason: ?string, priority: string} */
    public function decide(TaskPlan $plan, Conversation $c, string $text): array
    {
        $norm = $this->normalizer->normalize($text);
        $state = ($c->bot_state ?? [])['router'] ?? [];

        if (count($plan->tasks) > max(1, (int) config('crm.bot.router.max_tasks', 4))) {
            return ['tier' => 'handover', 'reason' => 'too_many_tasks', 'priority' => 'medium'];
        }

        if ($plan->wantsHuman) {
            return ['tier' => 'handover', 'reason' => 'human_request', 'priority' => 'medium'];
        }

        if ($plan->sentiment === 'angry' && $this->has($norm, self::THREATS)) {
            return ['tier' => 'handover', 'reason' => 'angry_threat', 'priority' => 'high'];
        }

        $kinds = $plan->kinds();
        $reason = match (true) {
            count($plan->tasks) >= 3 || (count($kinds) >= 2 && in_array('complaint', $kinds, true)) => 'E1',
            ($plan->sentiment === 'angry' || ($plan->sentiment === 'negative' && $this->has($norm, self::TOUCHY))) && $plan->hasOrder() => 'E2',
            $plan->policyEdge => 'E3',
            $plan->confidence() < 0.6 || ($plan->clarify !== null && (int) ($state['clarified'] ?? 0) > 0) => 'E4',
            ($state['last_text'] ?? null) === $norm && $norm !== '' => 'E6',
            default => null,
        };

        if ($reason === null) {
            return ['tier' => '1', 'reason' => null, 'priority' => 'medium'];
        }

        if (app(SonnetBudget::class)->exceeded()) {
            return ['tier' => '1', 'reason' => $reason.':budget', 'priority' => 'medium'];
        }

        return ['tier' => '2', 'reason' => $reason, 'priority' => 'medium'];
    }

    /** Remembers what she asked (E6) and whether the planner had to ask what she means (E4). */
    public function remember(Conversation $c, TaskPlan $plan, string $text): void
    {
        $state = $c->bot_state ?? [];
        $router = (array) ($state['router'] ?? []);
        $router['last_text'] = $this->normalizer->normalize($text);
        $router['clarified'] = $plan->clarify !== null ? (int) ($router['clarified'] ?? 0) + 1 : 0;
        // Round 4: her mood as the planner read it, for the handover brief («مزاجها»).
        $router['sentiment'] = $plan->sentiment;
        $state['router'] = $router;
        $c->forceFill(['bot_state' => $state])->save();
    }

    /** @param  list<string>  $words */
    private function has(string $norm, array $words): bool
    {
        foreach ($words as $w) {
            if (str_contains($norm, $this->normalizer->normalize($w))) {
                return true;
            }
        }

        return false;
    }
}
