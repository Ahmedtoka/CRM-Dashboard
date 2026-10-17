<?php

namespace App\Bot\Router;

use App\Bot\Flows\EntityExtractor;
use App\Models\BotFlow;

/**
 * One message as a list of tasks (bot-hybrid.md §3.3), from the TurnPlanner (Haiku) or the
 * keyword fallback, normalised by code so the model never has the last word on facts:
 *
 *   - an order number the planner gives that is not literally in her words is dropped (no invented refs);
 *   - her order numbers the planner missed go to the one directed task that has none;
 *   - a return and an exchange of the same order are one task (`mixed`, each piece with its action — owner B4);
 *   - the same request for two orders is two tasks (owner B6).
 *
 * A task: {kind, order_refs, items: [{text, qty, action, exchange_to}], governorate, place, product_query, question, confidence}.
 */
final class TaskPlan
{
    public const KINDS = ['return', 'exchange', 'return_or_exchange', 'order_status', 'cancel', 'edit', 'complaint', 'branches', 'shipping_fee', 'product', 'policy', 'human', 'smalltalk', 'other'];

    /** Kinds answered by a guided flow (the orchestrator), not by the model's reply. */
    public const FLOWS = ['return' => 'return_exchange', 'exchange' => 'return_exchange', 'return_or_exchange' => 'return_exchange', 'mixed' => 'return_exchange', 'cancel' => 'cancel_edit', 'edit' => 'cancel_edit', 'complaint' => 'complaint'];

    private const RETURN_FAMILY = ['return', 'exchange', 'return_or_exchange', 'mixed'];

    /** @param  list<array<string, mixed>>  $tasks */
    public function __construct(
        public readonly array $tasks,
        public readonly string $sentiment = 'neutral',
        public readonly bool $wantsHuman = false,
        public readonly bool $continuesPrevious = false,
        public readonly bool $policyEdge = false,
        public readonly ?string $clarify = null,
        public readonly string $source = 'model',
    ) {}

    /** @param  array<string, mixed>  $raw  the planner's JSON */
    public static function fromArray(array $raw, string $text, string $source = 'model'): self
    {
        $theirs = EntityExtractor::orderRefsInContext($text);
        $tasks = [];

        foreach ((array) ($raw['tasks'] ?? []) as $t) {
            if (! is_array($t)) {
                continue;
            }

            $kind = in_array($t['kind'] ?? null, self::KINDS, true) ? (string) $t['kind'] : 'other';
            $refs = array_values(array_intersect(array_map(fn ($r) => ltrim(trim((string) $r), '#'), (array) ($t['order_refs'] ?? [])), $theirs));

            $tasks[] = [
                'kind' => $kind,
                'order_refs' => array_values(array_unique($refs)),
                'items' => self::items((array) ($t['items'] ?? [])),
                'governorate' => self::str($t['governorate'] ?? null),
                'place' => self::str($t['place'] ?? null),
                'product_query' => self::str($t['product_query'] ?? null),
                'question' => self::str($t['question'] ?? null),
                'confidence' => max(0.0, min(1.0, (float) ($t['confidence'] ?? 0.5))),
            ];
        }

        $sentiment = in_array($raw['sentiment'] ?? null, ['positive', 'neutral', 'negative', 'angry'], true) ? $raw['sentiment'] : 'neutral';

        return new self(
            self::normalise($tasks, $theirs),
            $sentiment,
            (bool) ($raw['wants_human'] ?? false),
            (bool) ($raw['continues_previous'] ?? false),
            (bool) ($raw['policy_edge'] ?? false),
            self::str($raw['clarify'] ?? null),
            $source,
        );
    }

    /** The lowest task confidence (1 for a plan without tasks). */
    public function confidence(): float
    {
        return $this->tasks === [] ? 1.0 : (float) min(array_column($this->tasks, 'confidence'));
    }

    /** @return list<string> */
    public function kinds(): array
    {
        return array_values(array_unique(array_column($this->tasks, 'kind')));
    }

    /** @return list<array<string, mixed>> tasks a guided flow handles (only flows the owner has switched on) */
    public function directed(): array
    {
        return array_values(array_filter($this->tasks, fn ($t) => self::hasFlow($t['kind'])));
    }

    /** @return list<array<string, mixed>> tasks the reply answers (small talk aside; a switched-off flow's request too) */
    public function immediate(): array
    {
        return array_values(array_filter($this->tasks, fn ($t) => ! self::hasFlow($t['kind']) && ! in_array($t['kind'], ['smalltalk', 'human'], true)));
    }

    /** The kind is handled by a guided flow that is active (review #8: never «start» a switched-off flow). */
    public static function hasFlow(string $kind): bool
    {
        return isset(self::FLOWS[$kind]) && BotFlow::active(self::FLOWS[$kind]) !== null;
    }

    public function hasOrder(): bool
    {
        return collect($this->tasks)->contains(fn ($t) => $t['order_refs'] !== [] || $t['kind'] === 'order_status');
    }

    /** A JSON-able view for the bot run / shadow log. */
    public function toArray(): array
    {
        return ['tasks' => $this->tasks, 'sentiment' => $this->sentiment, 'wants_human' => $this->wantsHuman, 'continues_previous' => $this->continuesPrevious, 'policy_edge' => $this->policyEdge, 'clarify' => $this->clarify, 'source' => $this->source];
    }

    /**
     * @param  list<array<string, mixed>>  $tasks
     * @param  list<string>  $theirs  the order numbers literally in her message
     * @return list<array<string, mixed>>
     */
    private static function normalise(array $tasks, array $theirs): array
    {
        // Her numbers the planner did not place go to the only directed task without any.
        $placed = array_merge(...array_map(fn ($t) => $t['order_refs'], $tasks ?: [['order_refs' => []]]));
        $missing = array_values(array_diff($theirs, $placed));
        $bare = array_keys(array_filter($tasks, fn ($t) => isset(self::FLOWS[$t['kind']]) && $t['order_refs'] === []));

        if ($missing !== [] && count($bare) === 1) {
            $tasks[$bare[0]]['order_refs'] = $missing;
        }

        $out = [];
        $returns = [];

        foreach ($tasks as $t) {
            // A piece of a plain return/exchange carries that action, so a later merge keeps it.
            if (in_array($t['kind'], ['return', 'exchange'], true)) {
                $t['items'] = array_map(fn ($i) => ['action' => $i['action'] ?? $t['kind']] + $i, $t['items']);
            }

            $refs = $t['order_refs'] !== [] ? $t['order_refs'] : [null];

            foreach ($refs as $ref) {
                $one = ['order_refs' => $ref !== null ? [$ref] : []] + $t;

                if (! in_array($t['kind'], self::RETURN_FAMILY, true)) {
                    // The same directed request for several orders is one task per order (B6).
                    $out[] = isset(self::FLOWS[$t['kind']]) ? $one : $t;

                    if (! isset(self::FLOWS[$t['kind']])) {
                        break;
                    }

                    continue;
                }

                $key = $ref ?? '';

                if (! isset($returns[$key])) {
                    $returns[$key] = count($out);
                    $out[] = $one;

                    continue;
                }

                // A return and an exchange of the same order: one request (owner B4).
                $i = $returns[$key];
                $out[$i]['items'] = [...$out[$i]['items'], ...$one['items']];
                // «return_or_exchange» says nothing new next to a known one; mixed only for return + exchange (review #20).
                $kinds = array_values(array_unique(array_diff([$out[$i]['kind'], $one['kind']], ['return_or_exchange'])));
                $out[$i]['kind'] = match (count($kinds)) {
                    0 => 'return_or_exchange',
                    1 => $kinds[0],
                    default => 'mixed',
                };
                $out[$i]['confidence'] = min($out[$i]['confidence'], $one['confidence']);
            }
        }

        foreach ($out as $i => $t) {
            if (in_array($t['kind'], self::RETURN_FAMILY, true)) {
                $actions = array_values(array_unique(array_filter(array_column($t['items'], 'action'))));
                $out[$i]['kind'] = count($actions) > 1 ? 'mixed' : $t['kind'];
            }
        }

        return array_values($out);
    }

    /** @return list<array{text:string, qty:?int, action:?string, exchange_to:?string}> */
    private static function items(array $items): array
    {
        $out = [];

        foreach ($items as $i) {
            if (! is_array($i) || self::str($i['text'] ?? null) === null) {
                continue;
            }

            $out[] = [
                'text' => (string) self::str($i['text']),
                'qty' => is_numeric($i['qty'] ?? null) ? max(1, (int) $i['qty']) : null,
                'action' => in_array($i['action'] ?? null, ['return', 'exchange'], true) ? $i['action'] : null,
                'exchange_to' => self::str($i['exchange_to'] ?? null),
            ];
        }

        return $out;
    }

    private static function str(mixed $v): ?string
    {
        return is_string($v) && trim($v) !== '' ? mb_substr(trim($v), 0, 300) : null;
    }
}
