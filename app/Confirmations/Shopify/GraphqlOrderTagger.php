<?php

namespace App\Confirmations\Shopify;

use App\Confirmations\Contracts\ShopifyOrderTagger;
use App\Models\Order;
use App\Shopify\Client\ShopifyClient;
use App\Shopify\Client\ShopifyException;
use App\Shopify\Connection\IntegrationRepository;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Writes WhatsApp confirmation tags on the Shopify order with `tagsAdd` / `tagsRemove`
 * (spec 2026-10-16, slice W3). The only Shopify write this feature makes: it never
 * cancels or edits the order.
 *
 * `write_orders` is an optional scope, so a store connected without it must not be
 * flagged as broken by a refused tag: the scope is checked first (no call at all when
 * it is missing), and the mutation goes through mutateWithoutFlagging(). Any failure is
 * logged and answered false, with the reason in lastFailure(), so TagSync keeps the
 * tags pending for the sweep.
 */
final class GraphqlOrderTagger implements ShopifyOrderTagger, SyncsOrderTags
{
    public const MISSING_SCOPE = 'Shopify app lacks write_orders';

    private ?string $lastFailure = null;

    public function __construct(
        private readonly ShopifyClient $client,
        private readonly IntegrationRepository $integrations,
    ) {}

    public function add(Order $order, array $tags): bool
    {
        return $this->replace($order, $tags, []);
    }

    public function replace(Order $order, array $add, array $remove): bool
    {
        $this->lastFailure = null;
        $add = self::clean($add);
        $remove = array_values(array_diff(self::clean($remove), $add));

        if (blank($order->shopify_order_id)) {
            return $this->fail('Order is not on Shopify yet');
        }

        if ($add === [] && $remove === []) {
            return true;
        }

        $integration = $this->integrations->current();

        if ($integration === null || $integration->status !== 'connected') {
            return $this->fail('Shopify is not connected');
        }

        // Older integrations saved before scopes were recorded have none: try, unflagged.
        $scopes = $integration->granted_scopes;

        if (is_array($scopes) && $scopes !== [] && ! in_array('write_orders', $scopes, true)) {
            return $this->fail(self::MISSING_SCOPE);
        }

        $id = (string) $order->shopify_order_id;
        $variables = ['id' => str_starts_with($id, 'gid://') ? $id : "gid://shopify/Order/{$id}"];
        $params = ['$id: ID!'];
        $fields = [];

        if ($add !== []) {
            $variables['add'] = $add;
            $params[] = '$add: [String!]!';
            $fields[] = 'added: tagsAdd(id: $id, tags: $add) { userErrors { field message } }';
        }

        if ($remove !== []) {
            $variables['remove'] = $remove;
            $params[] = '$remove: [String!]!';
            $fields[] = 'removed: tagsRemove(id: $id, tags: $remove) { userErrors { field message } }';
        }

        $mutation = 'mutation confirmationTags('.implode(', ', $params).') { '.implode(' ', $fields).' }';

        try {
            $data = $this->client->mutateWithoutFlagging($mutation, $variables);
        } catch (Throwable $e) {
            $reason = $e instanceof ShopifyException && $e->kind === 'auth' ? self::MISSING_SCOPE.' (access denied)' : $e->getMessage();

            return $this->fail($reason, $order, $variables);
        }

        $errors = array_merge($data['added']['userErrors'] ?? [], $data['removed']['userErrors'] ?? []);

        if ($errors !== []) {
            return $this->fail('Shopify refused the tags: '.collect($errors)->pluck('message')->filter()->implode('; '), $order, $variables);
        }

        return true;
    }

    public function lastFailure(): ?string
    {
        return $this->lastFailure;
    }

    /** @return list<string> */
    private static function clean(array $tags): array
    {
        return array_values(array_unique(array_filter(array_map('strval', $tags), 'filled')));
    }

    private function fail(string $reason, ?Order $order = null, array $variables = []): bool
    {
        $this->lastFailure = mb_substr($reason, 0, 400);

        if ($order !== null) {
            Log::warning('Shopify confirmation tags failed for order '.$order->id.': '.$reason, $variables);
        }

        return false;
    }
}
