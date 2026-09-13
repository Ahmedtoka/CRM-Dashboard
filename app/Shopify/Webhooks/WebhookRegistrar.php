<?php

namespace App\Shopify\Webhooks;

use App\Models\ShopifySyncRun;
use App\Models\ShopifyWebhookSubscription;
use App\Shopify\Client\ShopifyClient;
use App\Shopify\Client\ShopifyException;

/**
 * Registers/audits/tears down the Shopify webhook subscriptions this app
 * needs (spec §4.2, plan Global Constraints — exact topic list and callback
 * format). Every call goes through ShopifyClient, never Http directly.
 *
 * Note: `check()` writes `shopify_sync_runs` directly with the model, the same
 * way Task 5's `SyncRunRecorder` presumably will — there was nothing to call
 * yet at the time this was written; worth revisiting once that lands.
 */
final class WebhookRegistrar
{
    private const DELETE_MUTATION = <<<'GRAPHQL'
        mutation webhookSubscriptionDelete($id: ID!) {
          webhookSubscriptionDelete(id: $id) {
            deletedWebhookSubscriptionId
            userErrors { field message }
          }
        }
    GRAPHQL;

    /**
     * `WebhookSubscription.uri` / `WebhookSubscriptionInput.uri` only exist
     * from API version 2025-10 onward; this app is pinned to 2025-07
     * (config('crm.shopify.api_version')), where the destination is read via
     * `endpoint { ... on WebhookHttpEndpoint { callbackUrl } }` and written via
     * `WebhookSubscriptionInput{ callbackUrl, format }`. Selecting `uri` against
     * an older API version is a hard GraphQL validation error (unknown field),
     * so this is a real version switch, not a fallback query.
     */
    private const URI_FIELD_API_VERSION = '2025-10';

    public function __construct(private readonly ShopifyClient $client) {}

    /**
     * Creates a subscription for every configured topic that isn't already
     * correctly registered on Shopify. Returns the topics actually
     * created/recreated (adoptions of an already-correct remote subscription
     * are not "created").
     *
     * @return list<string>
     */
    public function register(): array
    {
        return $this->reconcile()['topics'];
    }

    /**
     * Same reconciliation as register(), plus a `shopify_sync_runs` row (type
     * `webhook_health`) recording the outcome regardless of success.
     *
     * @return list<string>
     */
    public function check(): array
    {
        $startedAt = now();
        $result = $this->reconcile();

        ShopifySyncRun::create([
            'type' => 'webhook_health',
            'resource' => 'webhooks',
            'status' => $result['status'],
            'processed' => count($this->topics()),
            'created' => count($result['topics']),
            'errors' => $result['errors'],
            'started_at' => $startedAt,
            'finished_at' => now(),
        ]);

        return $result['topics'];
    }

    /**
     * Deletes every stored subscription on Shopify (best-effort — a failure for
     * one topic never blocks the rest) and clears the local rows regardless.
     */
    public function removeAll(): void
    {
        ShopifyWebhookSubscription::whereNotNull('shopify_subscription_id')
            ->get()
            ->each(fn (ShopifyWebhookSubscription $subscription) => $this->deleteRemote($subscription->shopify_subscription_id));

        ShopifyWebhookSubscription::query()->delete();
    }

    /**
     * Lists what Shopify actually has, then for every configured topic:
     *  - right topic + matching URI already remote -> adopt (local row upserted
     *    with its id, no Shopify call);
     *  - right topic but a different URI -> the stale remote subscription is
     *    deleted, then a fresh one created for our callback URL;
     *  - topic missing remotely -> created.
     * A create that fails with an "already been taken" userError means the
     * remote list above was already stale (another process just registered it,
     * or the delete above hadn't propagated yet): re-list once and adopt
     * whatever is there instead of recording a failure.
     *
     * @return array{topics: list<string>, errors: list<string>, status: string}
     */
    private function reconcile(): array
    {
        $topics = $this->topics();

        try {
            $remote = $this->remoteSubscriptions();
        } catch (ShopifyException $e) {
            return ['topics' => [], 'errors' => [$e->getMessage()], 'status' => 'failed'];
        }

        $changed = [];
        $errors = [];

        foreach ($topics as $topic) {
            $enumTopic = $this->toEnum($topic);
            $callbackUrl = $this->callbackUrl($topic);
            $match = $remote[$enumTopic] ?? null;

            if ($match !== null && $match['uri'] === $callbackUrl) {
                $this->adopt($topic, $match['id'], $callbackUrl);

                continue;
            }

            if ($match !== null) {
                // Right topic, wrong destination: remove the stale registration
                // before creating the correct one (Shopify allows only one
                // subscription per topic per app).
                $this->deleteRemote($match['id']);
            }

            $outcome = $this->createSubscription($topic, $callbackUrl);

            if ($outcome['status'] === 'created') {
                $changed[] = $topic;

                continue;
            }

            if ($outcome['status'] === 'taken' && $this->adoptIfNowRemote($topic, $callbackUrl)) {
                continue;
            }

            $errors[] = $outcome['error'] ?? "Failed to register {$topic}";
        }

        $status = match (true) {
            $errors === [] => 'ok',
            count($errors) >= count($topics) => 'failed',
            default => 'partial',
        };

        return ['topics' => $changed, 'errors' => $errors, 'status' => $status];
    }

    /**
     * @return array{status: 'created'|'taken'|'failed', error?: string}
     */
    private function createSubscription(string $topic, string $callbackUrl): array
    {
        try {
            $data = $this->client->mutate($this->createMutation(), [
                'topic' => $this->toEnum($topic),
                'webhookSubscription' => $this->subscriptionInput($callbackUrl),
            ], 'webhookSubscriptionCreate');
        } catch (ShopifyException $e) {
            if ($e->kind === 'user_errors' && $this->isAlreadyTaken($e->userErrors)) {
                return ['status' => 'taken'];
            }

            return ['status' => 'failed', 'error' => "{$topic}: {$e->getMessage()}"];
        }

        $id = $data['webhookSubscriptionCreate']['webhookSubscription']['id'] ?? null;

        if ($id === null) {
            return ['status' => 'failed', 'error' => "{$topic}: Shopify returned no subscription id"];
        }

        $this->adopt($topic, $id, $callbackUrl);

        return ['status' => 'created'];
    }

    private function adoptIfNowRemote(string $topic, string $callbackUrl): bool
    {
        try {
            $remote = $this->remoteSubscriptions();
        } catch (ShopifyException) {
            return false;
        }

        $match = $remote[$this->toEnum($topic)] ?? null;

        if ($match === null || $match['uri'] !== $callbackUrl) {
            return false;
        }

        $this->adopt($topic, $match['id'], $callbackUrl);

        return true;
    }

    private function adopt(string $topic, string $subscriptionId, string $callbackUrl): void
    {
        ShopifyWebhookSubscription::updateOrCreate(
            ['topic' => $topic],
            [
                'shopify_subscription_id' => $subscriptionId,
                'callback_url' => $callbackUrl,
                'registered_at' => now(),
            ],
        );
    }

    private function deleteRemote(?string $subscriptionId): void
    {
        if ($subscriptionId === null) {
            return;
        }

        try {
            $this->client->mutate(self::DELETE_MUTATION, ['id' => $subscriptionId], 'webhookSubscriptionDelete');
        } catch (ShopifyException) {
            // Best-effort: a failed delete surfaces as "already been taken" on
            // the create that follows, which is handled by re-listing/adopting.
        }
    }

    private function isAlreadyTaken(array $userErrors): bool
    {
        foreach ($userErrors as $error) {
            if (str_contains(strtolower((string) ($error['message'] ?? '')), 'already been taken')) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array<string, array{id: string, uri: ?string}> keyed by the GraphQL enum topic
     */
    private function remoteSubscriptions(): array
    {
        $subscriptions = [];
        $cursor = null;

        do {
            $data = $this->client->query($this->listQuery(), ['cursor' => $cursor]);
            $page = $data['webhookSubscriptions'] ?? null;

            if ($page === null) {
                break;
            }

            foreach ($page['nodes'] ?? [] as $node) {
                if (! isset($node['topic'], $node['id'])) {
                    continue;
                }

                $subscriptions[$node['topic']] = [
                    'id' => $node['id'],
                    'uri' => $this->usesUriField() ? ($node['uri'] ?? null) : ($node['endpoint']['callbackUrl'] ?? null),
                ];
            }

            $cursor = ($page['pageInfo']['hasNextPage'] ?? false) ? $page['pageInfo']['endCursor'] : null;
        } while ($cursor !== null);

        return $subscriptions;
    }

    private function usesUriField(): bool
    {
        return (string) config('crm.shopify.api_version', '2025-07') >= self::URI_FIELD_API_VERSION;
    }

    private function createMutation(): string
    {
        $subscriptionFields = $this->usesUriField()
            ? 'id topic uri'
            : "id\ntopic\nendpoint { ... on WebhookHttpEndpoint { callbackUrl } }";

        return <<<GRAPHQL
            mutation webhookSubscriptionCreate(\$topic: WebhookSubscriptionTopic!, \$webhookSubscription: WebhookSubscriptionInput!) {
              webhookSubscriptionCreate(topic: \$topic, webhookSubscription: \$webhookSubscription) {
                webhookSubscription { {$subscriptionFields} }
                userErrors { field message }
              }
            }
        GRAPHQL;
    }

    private function listQuery(): string
    {
        $nodeFields = $this->usesUriField()
            ? 'id topic uri'
            : "id\n      topic\n      endpoint { ... on WebhookHttpEndpoint { callbackUrl } }";

        return <<<GRAPHQL
            query webhookSubscriptions(\$cursor: String) {
              webhookSubscriptions(first: 100, after: \$cursor) {
                pageInfo { hasNextPage endCursor }
                nodes { {$nodeFields} }
              }
            }
        GRAPHQL;
    }

    /**
     * @return array<string, mixed>
     */
    private function subscriptionInput(string $callbackUrl): array
    {
        return $this->usesUriField()
            ? ['uri' => $callbackUrl]
            : ['callbackUrl' => $callbackUrl, 'format' => 'JSON'];
    }

    /**
     * @return list<string>
     */
    private function topics(): array
    {
        return config('crm.shopify.webhook_topics', []);
    }

    /**
     * REST topic (`orders/paid`) -> GraphQL enum (`ORDERS_PAID`). Every configured
     * topic has exactly one "/" and no literal "-", so this is a total mapping.
     */
    private function toEnum(string $topic): string
    {
        return strtoupper(str_replace('/', '_', $topic));
    }

    /**
     * REST topic (`orders/paid`) -> URL segment (`orders-paid`); underscores in a
     * topic (e.g. `inventory_levels/update`, `draft_orders/update`) are left
     * alone since only "/" is replaced (plan Global Constraints).
     */
    private function callbackUrl(string $topic): string
    {
        return rtrim((string) config('app.url'), '/').'/webhooks/shopify/'.str_replace('/', '-', $topic);
    }
}
