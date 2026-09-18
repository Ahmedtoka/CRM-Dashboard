<?php

namespace App\Bot\Flow;

use App\Models\BotIntent;

/**
 * Required details for `collect_then_handover` intents (spec §2.1). Tokens come
 * from `bot_intents.required_details`: "a|b" is satisfied by either, "x?" is
 * optional. order_ref / phone / email come from understood entities; photo
 * tokens (photos, product_photo, tag_photo, invoice) from an image in the
 * burst, or from a photo remembered while this intent was waiting for details. Free-text details the understanding cannot extract (name,
 * visit_date, method, description…) count as given once the customer was
 * asked and replied.
 */
class DetailsCollector
{
    private const EXTRACTED = ['order_ref', 'phone', 'email'];

    private const PHOTO_TOKENS = ['photos', 'product_photo', 'tag_photo', 'invoice'];

    /** Free-text details asked for and counted as given once the customer replied. */
    private const FREE_TEXT = ['name', 'visit_date', 'method', 'description', 'product'];

    /**
     * Every token a `required_details` entry may name (alone, as "a|b", or "x?").
     * The intent settings page validates against this list.
     */
    public const KNOWN_TOKENS = [...self::EXTRACTED, ...self::PHOTO_TOKENS, ...self::FREE_TEXT];

    /**
     * @param  array<string, mixed>  $entities  this burst's entities (+ photos => 'yes' when it had an image)
     * @param  array<string, mixed>  $state  conversations.bot_state (collected, asks)
     * @return list<string> unsatisfied tokens, as written in required_details
     */
    public function missing(BotIntent $i, array $entities, array $state): array
    {
        // A remembered photo only counts for the collect intent that asked for it (reply flow v2);
        // otherwise photos must be in this burst's entities.
        $collected = (array) ($state['collected'] ?? []);

        if (($state['awaiting_intent'] ?? null) !== $i->key) {
            unset($collected['photos']);
        }

        // Empty entities of this burst (order_ref => null) must not wipe a detail given earlier.
        $filled = fn ($v) => $v !== null && $v !== '' && $v !== false;
        $have = array_merge(array_filter($collected, $filled), array_filter($entities, $filled));
        $asked = (int) ($state['asks'][$i->key] ?? 0) > 0;
        $missing = [];

        foreach ((array) ($i->required_details ?? []) as $token) {
            $token = trim((string) $token);

            if ($token === '' || str_ends_with($token, '?')) {
                continue;
            }

            $satisfied = collect(explode('|', $token))->contains(fn (string $alt) => $this->satisfied(trim($alt), $have, $asked));

            if (! $satisfied) {
                $missing[] = $token;
            }
        }

        return $missing;
    }

    /**
     * @param  array<string, mixed>  $state
     * @param  array<string, mixed>  $entities
     * @param  bool  $keepPhotos  keep an image of this burst (and one already remembered) for a pending collect
     * @return array<string, mixed> the state with non-null entities merged into `collected`
     */
    public function remember(array $state, array $entities, bool $keepPhotos = false): array
    {
        $collected = (array) ($state['collected'] ?? []);

        if (! $keepPhotos) {
            unset($entities['photos'], $collected['photos']);
        }

        $state['collected'] = array_merge(
            $collected,
            array_filter($entities, fn ($v) => $v !== null && $v !== ''),
        );

        return $state;
    }

    private function satisfied(string $alt, array $have, bool $asked): bool
    {
        if (in_array($alt, self::PHOTO_TOKENS, true)) {
            return isset($have['photos']);
        }

        if (in_array($alt, self::EXTRACTED, true)) {
            return isset($have[$alt]);
        }

        return $asked || isset($have[$alt]);
    }
}
