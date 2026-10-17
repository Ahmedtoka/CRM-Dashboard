<?php

namespace App\Shopify\App;

use App\Shopify\Client\ShopifyException;
use Carbon\CarbonImmutable;
use Throwable;

/**
 * Deterministic token endpoint for every non-live driver (tests, local, demo): hands out
 * `fake-app-token-N` / `fake-refresh-N` pairs with the real lifetimes and every required scope,
 * and records each call (never the secret).
 */
final class FakeShopifyOAuth implements ShopifyOAuth
{
    /** @var list<array{grant: string, shop: string, client_id: string, refresh_token?: string}> */
    private static array $calls = [];

    private static int $seq = 0;

    private static ?Throwable $failNext = null;

    public static function reset(): void
    {
        self::$calls = [];
        self::$seq = 0;
        self::$failNext = null;
    }

    /** @return list<array{grant: string, shop: string, client_id: string, refresh_token?: string}> */
    public static function calls(): array
    {
        return self::$calls;
    }

    public static function failNextWith(Throwable $e): void
    {
        self::$failNext = $e;
    }

    public function exchange(ShopifyApp $app, string $shop, string $idToken): TokenSet
    {
        self::$calls[] = ['grant' => 'token_exchange', 'shop' => $shop, 'client_id' => $app->clientId];

        return $this->answer();
    }

    public function refresh(ShopifyApp $app, string $shop, string $refreshToken): TokenSet
    {
        self::$calls[] = ['grant' => 'refresh_token', 'shop' => $shop, 'client_id' => $app->clientId, 'refresh_token' => $refreshToken];

        return $this->answer();
    }

    private function answer(): TokenSet
    {
        if (self::$failNext !== null) {
            $e = self::$failNext;
            self::$failNext = null;

            throw $e instanceof ShopifyException ? $e : new ShopifyException('transport', $e->getMessage());
        }

        $n = ++self::$seq;
        $now = CarbonImmutable::now();

        return new TokenSet(
            "fake-app-token-{$n}",
            $now->addHour(),
            "fake-refresh-{$n}",
            $now->addDays(90),
            array_values((array) config('crm.shopify.required_scopes', [])),
        );
    }
}
