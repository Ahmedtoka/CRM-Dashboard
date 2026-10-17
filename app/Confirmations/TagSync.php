<?php

namespace App\Confirmations;

use App\Confirmations\Contracts\ShopifyOrderTagger;
use App\Confirmations\Jobs\SyncConfirmationTags;
use App\Confirmations\Shopify\SyncsOrderTags;
use App\Enums\OrderConfirmationStatus;
use App\Models\OrderConfirmation;

/**
 * Writes a confirmation's state to the Shopify order as one `wa-*` tag (spec 2026-10-16,
 * slice W3). The tag of the newest state replaces the others, so an order never carries
 * wa-no-response and wa-confirmed together (still tag-only writes).
 *
 * The wanted tag waits in `pending_tags` as `{"tags": [tag], "attempts": n}` with
 * `tag_synced_at` null until Shopify took it. request() stores it and queues the write
 * (no Shopify call inside a web request or the inbound message path); the sweep retries
 * until MAX_ATTEMPTS, then stops. `last_error` says why the last write failed.
 */
final class TagSync
{
    /** Sweep runs every 5 minutes: about two hours of retries. */
    public const MAX_ATTEMPTS = 24;

    public const QUEUE = 'commerce';

    public function __construct(private readonly ShopifyOrderTagger $tagger) {}

    /** Records the tag for the current status (or $tag) and queues the Shopify write. */
    public function request(OrderConfirmation $confirmation, ?string $tag = null): void
    {
        if ($this->remember($confirmation, $tag)) {
            SyncConfirmationTags::dispatch($confirmation->id)->onQueue(self::QUEUE)->afterCommit();
        }
    }

    /** Records the tag and writes it now (the queued job and tests). */
    public function push(OrderConfirmation $confirmation, ?string $tag = null): bool
    {
        return ! $this->remember($confirmation, $tag) || $this->flush($confirmation);
    }

    /** Writes whatever is pending; false when it failed or the retry cap is reached. */
    public function flush(OrderConfirmation $confirmation): bool
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

    /** One sweep retry of the pending tag. */
    public function retry(OrderConfirmation $confirmation): bool
    {
        return $this->flush($confirmation);
    }

    /** @return list<string> every tag this feature writes */
    public static function allTags(): array
    {
        return array_values(array_unique(array_filter(array_map(
            fn (OrderConfirmationStatus $s) => $s->shopifyTag(),
            OrderConfirmationStatus::cases(),
        ))));
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

    /** Stores the newest wanted tag; false when the state has no tag. A new state restarts the retry budget. */
    private function remember(OrderConfirmation $confirmation, ?string $tag): bool
    {
        $tag ??= $confirmation->status->shopifyTag();

        if ($tag === null) {
            return false;
        }

        $confirmation->update(['pending_tags' => ['tags' => [$tag], 'attempts' => 0], 'tag_synced_at' => null]);

        return true;
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
        // The newest wanted tag wins; a legacy list of several keeps only its last one.
        $add = [end($tags)];
        $remove = array_values(array_diff(self::allTags(), $add));

        $ok = $order !== null && ($this->tagger instanceof SyncsOrderTags
            ? $this->tagger->replace($order, $add, $remove)
            : $this->tagger->add($order, $add));

        if ($ok) {
            // Only clear what was written: a newer state queued meanwhile stays pending.
            $confirmation->refresh();

            $stillWanted = self::pendingTags($confirmation);

            if ($stillWanted === [] || last($stillWanted) === $add[0]) {
                $confirmation->update(['pending_tags' => null, 'tag_synced_at' => now()]);
            }

            return true;
        }

        $attempts++;
        $reason = $order === null ? 'Order not found' : ($this->tagger instanceof SyncsOrderTags ? $this->tagger->lastFailure() : null);
        $confirmation->update([
            'pending_tags' => ['tags' => $add, 'attempts' => $attempts],
            'tag_synced_at' => null,
            'last_error' => mb_substr('Shopify tag '.implode(', ', $add)
                .($attempts >= self::MAX_ATTEMPTS ? " not written after {$attempts} tries" : '').': '.($reason ?? 'write failed'), 0, 500),
        ]);

        return false;
    }
}
