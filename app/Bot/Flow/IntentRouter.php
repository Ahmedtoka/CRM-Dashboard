<?php

namespace App\Bot\Flow;

/**
 * Pure routing (spec §2.1): Understanding + catalog + conversation state →
 * TurnPlan. Several intents in one burst are all planned; the strongest
 * handover wins while low intents in the same burst are still answered.
 */
class IntentRouter
{
    private const RANK = ['low' => 1, 'medium' => 2, 'high' => 3];

    /** How many clarifying questions in a row before an unclear conversation goes to a person. */
    public const MAX_CLARIFY = 2;

    /** @param  array<string, mixed>  $botState  conversations.bot_state */
    public function plan(Understanding $u, IntentCatalog $catalog, array $botState, float $minConfidence): TurnPlan
    {
        // 1. Known, confident intents only (first mention wins on duplicates).
        $known = [];

        foreach ($u->intents as $i) {
            $intent = $catalog->find((string) ($i['key'] ?? ''));

            if ($intent === null || (float) ($i['confidence'] ?? 0) < $minConfidence || isset($known[$intent->key])) {
                continue;
            }

            $known[$intent->key] = $intent;
        }

        $angry = $u->sentiment === 'negative' && $u->urgent
            ? $this->handover('high', 'agents', 'angry_or_urgent', 'angry_or_urgent')
            : null;

        // 2. Nothing we can act on: ask up to twice, then let a person decide. An
        //    angry, urgent customer is never asked to clarify.
        if ($known === []) {
            if ($angry !== null) {
                return new TurnPlan([], [], null, $angry, false);
            }

            // Reply flow v2: a legacy `clarified: true` counts as one question already asked.
            $count = (int) ($botState['clarify_count'] ?? (empty($botState['clarified']) ? 0 : 1));

            return $count < self::MAX_CLARIFY
                ? new TurnPlan([], [], null, null, true)
                : new TurnPlan([], [], null, $this->handover('medium', 'agents', 'unclear', 'unclear'), false);
        }

        // 3. Angry + urgent is the first handover candidate.
        $candidates = $angry !== null ? [$angry] : [];

        // 4. Split by route.
        $answers = [];
        $lookups = [];
        $collect = null;

        foreach ($known as $intent) {
            switch ($intent->route) {
                case 'answer':
                    $answers[] = $intent;
                    break;
                case 'lookup':
                    $lookups[] = $intent;
                    break;
                case 'collect_then_handover':
                    if ($collect === null || $this->rank($intent->priority) > $this->rank($collect->priority)) {
                        $collect = $intent;
                    }
                    break;
                case 'handover':
                    $candidates[] = $this->handover((string) $intent->priority, $intent->queue ?: 'agents', (string) $intent->key, 'intent');
                    break;
            }
        }

        // A cancel/edit/exchange/defect/refund request already collects the order details and hands
        // over, so tracking the order in the same burst is subsumed (no second "send the number" ask).
        if ($collect !== null) {
            $lookups = [];
        }

        // 5. The same question again and again: a person should take it.
        if (($botState['last_intents'] ?? null) === $u->keys() && (int) ($botState['repeat_count'] ?? 0) >= 2) {
            $candidates[] = $this->handover('medium', 'agents', 'repeated', 'repeated');
        }

        return new TurnPlan($answers, $lookups, $collect, $this->strongest($candidates), false);
    }

    /** @return array{priority:string, queue:string, category:string, reason:string} */
    private function handover(string $priority, string $queue, string $category, string $reason): array
    {
        return ['priority' => $priority, 'queue' => $queue, 'category' => $category, 'reason' => $reason];
    }

    /** Highest priority wins; ties keep the first. */
    private function strongest(array $candidates): ?array
    {
        $best = null;

        foreach ($candidates as $c) {
            if ($best === null || $this->rank($c['priority']) > $this->rank($best['priority'])) {
                $best = $c;
            }
        }

        return $best;
    }

    private function rank(?string $priority): int
    {
        return self::RANK[$priority ?? 'low'] ?? 1;
    }
}
