<?php

namespace App\Confirmations;

use App\Confirmations\Contracts\ShopifyOrderTagger;
use App\Models\OrderConfirmation;

/**
 * Writes a confirmation's state to the Shopify order as a tag (spec 2026-10-16, slice W3).
 *
 * A failed write is kept in `pending_tags` as `{"tags": [...], "attempts": n}` with
 * `tag_synced_at` null; the sweep retries it until MAX_ATTEMPTS, then stops and leaves
 * a `last_error` for staff. Tags only ever add: an earlier tag stays on the order.
 */
final class TagSync
{
    /** Sweep runs every 5 minutes: about two hours of retries. */
    public const MAX_ATTEMPTS = 24;

    public function __construct(private readonly ShopifyOrderTagger $tagger) {}

    /** Pushes the current status's tag (or $tag), together with anything still pending. */
    public function push(OrderConfirmation $confirmation, ?string $tag = null): bool
    {
        $tag ??= $confirmation->status->shopifyTag();
        $pending = self::pendingTags($confirmation);

        if ($tag === null && $pending === []) {
            return true;
        }

        $tags = array_values(array_unique([...$pending, ...($tag !== null ? [$tag] : [])]));

        // A new state is a fresh start: the retry budget counts from here.
        return $this->write($confirmation, $tags, 0);
    }

    /** One sweep retry of the pending tags; false when it failed or the cap is reached. */
    public function retry(OrderConfirmation $confirmation): bool
    {
        $tags = self::pendingTags($confirmation);

        if ($tags === []) {
            return true;
        }

        $attempts = self::attempts($confirmation);

        if ($attempts >= self::MAX_ATTEMPTS) {
            return false;
        }

        return $this->write($confirmation, $tags, $attempts);
    }

    /** @return list<string> */
    public static function pendingTags(OrderConfirmation $confirmation): array
    {
        $pending = $confirmation->pending_tags;

        if (! is_array($pending)) {
            return [];
        }

        $tags = array_is_list($pending) ? $pending : (array) ($pending['tags'] ?? []);

        return array_values(array_filter(array_map('strval', $tags), 'filled'));
    }

    private static function attempts(OrderConfirmation $confirmation): int
    {
        $pending = $confirmation->pending_tags;

        return is_array($pending) && ! array_is_list($pending) ? (int) ($pending['attempts'] ?? 0) : 0;
    }

    /** @param  list<string>  $tags */
    private function write(OrderConfirmation $confirmation, array $tags, int $attempts): bool
    {
        $order = $confirmation->order;

        if ($order !== null && $this->tagger->add($order, $tags)) {
            $confirmation->update(['pending_tags' => null, 'tag_synced_at' => now()]);

            return true;
        }

        $attempts++;
        $values = ['pending_tags' => ['tags' => $tags, 'attempts' => $attempts], 'tag_synced_at' => null];

        if ($attempts >= self::MAX_ATTEMPTS) {
            $values['last_error'] = 'Shopify tags not written after '.$attempts.' tries: '.implode(', ', $tags);
        }

        $confirmation->update($values);

        return false;
    }
}
