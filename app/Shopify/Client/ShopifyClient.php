<?php

namespace App\Shopify\Client;

use App\Shopify\Connection\IntegrationRepository;
use Closure;
use Throwable;

/**
 * Single GraphQL gateway to Shopify (spec §2, §4.4): auth header, API
 * version, throttle-aware pacing from `extensions.cost.throttleStatus`,
 * retries with backoff on 429/5xx/transport/THROTTLED, typed
 * ShopifyException on auth/throttled/transport/user_errors failures.
 *
 * Every later Shopify feature (mappers, webhooks, import, order submission)
 * must call Shopify only through this client.
 */
final class ShopifyClient
{
    /** Retry backoff in seconds; also the max retry count (5). */
    private const BACKOFF_SECONDS = [2, 4, 8, 16, 32];

    /** Default estimated cost of a query used to decide whether to pace. */
    private const DEFAULT_ESTIMATED_COST = 50.0;

    private ?array $throttleStatus = null;

    private bool $useStoredIntegration = true;

    private ?string $domain = null;

    private ?string $token = null;

    public function __construct(
        private readonly ShopifyTransport $transport,
        private readonly IntegrationRepository $integrations,
        private readonly ?Closure $sleeper = null,
    ) {}

    /**
     * Clone bound to explicit credentials, used by ConnectionTester before an
     * integration is saved. Never reads or writes the stored integration and
     * never marks it as broken.
     */
    public function forCredentials(string $domain, string $token): self
    {
        $clone = clone $this;
        $clone->useStoredIntegration = false;
        $clone->domain = $domain;
        $clone->token = $token;
        $clone->throttleStatus = null;

        return $clone;
    }

    /** @return array<mixed> */
    public function query(string $query, array $variables = []): array
    {
        return $this->execute($query, $variables);
    }

    /**
     * Mutation helper: throws ShopifyException('user_errors') when
     * $data[$root]['userErrors'] is non-empty.
     *
     * @return array<mixed>
     */
    public function mutate(string $mutation, array $variables, string $root): array
    {
        $data = $this->execute($mutation, $variables);
        $userErrors = $data[$root]['userErrors'] ?? [];

        if (! empty($userErrors)) {
            throw new ShopifyException('user_errors', 'Shopify mutation returned user errors', $userErrors);
        }

        return $data;
    }

    /** @return array{maximumAvailable: float, currentlyAvailable: float, restoreRate: float}|null */
    public function lastThrottleStatus(): ?array
    {
        return $this->throttleStatus;
    }

    /** @return array<mixed> */
    private function execute(string $query, array $variables): array
    {
        [$domain, $token] = $this->resolveCredentials();

        $version = config('crm.shopify.api_version', '2025-07');
        $url = "https://{$domain}/admin/api/{$version}/graphql.json";
        $headers = [
            'X-Shopify-Access-Token' => $token,
            'Content-Type' => 'application/json',
        ];
        $body = ['query' => $query, 'variables' => $variables];

        $this->paceIfNeeded();

        $attempt = 0;

        while (true) {
            try {
                $response = $this->transport->post($url, $headers, $body);
            } catch (Throwable $e) {
                if ($attempt >= count(self::BACKOFF_SECONDS)) {
                    throw new ShopifyException('transport', $e->getMessage(), [], $e);
                }
                $this->sleep(self::BACKOFF_SECONDS[$attempt]);
                $attempt++;

                continue;
            }

            $status = (int) ($response['status'] ?? 0);
            $json = $response['json'] ?? [];

            if ($status === 401 || $status === 403 || $this->hasErrorCode($json, 'ACCESS_DENIED')) {
                if ($this->useStoredIntegration) {
                    $this->integrations->markError('Shopify authentication failed (HTTP '.$status.')');
                }

                throw new ShopifyException('auth', 'Shopify authentication failed');
            }

            if ($status === 429 || $status >= 500 || $this->hasErrorCode($json, 'THROTTLED')) {
                if ($attempt >= count(self::BACKOFF_SECONDS)) {
                    throw new ShopifyException('throttled', 'Shopify request throttled after retries');
                }
                $this->sleep(self::BACKOFF_SECONDS[$attempt]);
                $attempt++;

                continue;
            }

            if (! empty($json['errors'])) {
                throw new ShopifyException('transport', 'Shopify GraphQL error: '.json_encode($json['errors']));
            }

            $this->recordThrottleStatus($json);

            return $json['data'] ?? [];
        }
    }

    /** @return array{0: string, 1: string} */
    private function resolveCredentials(): array
    {
        if (! $this->useStoredIntegration) {
            return [$this->domain, $this->token];
        }

        $integration = $this->integrations->requireConnected();

        return [$integration->shop_domain, $integration->access_token];
    }

    private function paceIfNeeded(float $estimatedCost = self::DEFAULT_ESTIMATED_COST): void
    {
        if ($this->throttleStatus === null) {
            return;
        }

        $available = (float) $this->throttleStatus['currentlyAvailable'];

        if ($available >= $estimatedCost) {
            return;
        }

        $restoreRate = (float) ($this->throttleStatus['restoreRate'] ?: 1);
        $seconds = (int) ceil(($estimatedCost - $available) / $restoreRate);

        if ($seconds > 0) {
            $this->sleep($seconds);
        }
    }

    private function recordThrottleStatus(array $json): void
    {
        $status = $json['extensions']['cost']['throttleStatus'] ?? null;

        if (! is_array($status)) {
            return;
        }

        $this->throttleStatus = [
            'maximumAvailable' => (float) ($status['maximumAvailable'] ?? 0),
            'currentlyAvailable' => (float) ($status['currentlyAvailable'] ?? 0),
            'restoreRate' => (float) ($status['restoreRate'] ?? 0),
        ];
    }

    private function hasErrorCode(array $json, string $code): bool
    {
        foreach ($json['errors'] ?? [] as $error) {
            if (($error['extensions']['code'] ?? null) === $code) {
                return true;
            }
        }

        return false;
    }

    /**
     * Sleeps `$seconds`. When no sleeper is injected (production), adds a
     * 0-500ms jitter and actually sleeps; tests inject a recording closure
     * that receives the integer seconds with no jitter.
     */
    private function sleep(int $seconds): void
    {
        if ($this->sleeper !== null) {
            ($this->sleeper)($seconds);

            return;
        }

        usleep($seconds * 1_000_000 + random_int(0, 500) * 1_000);
    }
}
