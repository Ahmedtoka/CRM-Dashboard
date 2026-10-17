<?php

use App\Enums\UserRole;
use App\Models\User;
use App\Models\UserNotification;
use App\Shopify\App\FakeShopifyOAuth;
use App\Shopify\App\HttpShopifyOAuth;
use App\Shopify\App\IdTokenVerifier;
use App\Shopify\App\InvalidIdToken;
use App\Shopify\App\ShopifyAccessTokens;
use App\Shopify\App\ShopifyApps;
use App\Shopify\App\ShopifyOAuth;
use App\Shopify\App\ShopNotAllowed;
use App\Shopify\App\TokenSet;
use App\Shopify\Client\ShopifyClient;
use App\Shopify\Client\ShopifyException;
use App\Shopify\Client\ShopifyTransport;
use App\Shopify\Connection\IntegrationRepository;
use App\Shopify\Connection\ShopifyIntegration;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Http;

/*
 * Le Voile app auth (round 2026-10-17, shopify-app.md §2): ID tokens from App Bridge are verified
 * (HS256 with the client secret, exp/nbf/aud/iss/dest), exchanged for an expiring offline token,
 * and that token is refreshed under one lock shared with the exchange.
 */

const TX_SHOP = 'levoile-test.myshopify.com';

function b64url(string $raw): string
{
    return rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
}

function makeIdToken(array $overrides = [], string $secret = 'app-secret', string $alg = 'HS256'): string
{
    $claims = array_replace([
        'iss' => 'https://'.TX_SHOP.'/admin',
        'dest' => 'https://'.TX_SHOP,
        'aud' => 'client-levoile',
        'sub' => '42',
        'exp' => time() + 60,
        'nbf' => time() - 5,
        'iat' => time() - 5,
        'jti' => 'jti-1',
        'sid' => 'sid-1',
    ], $overrides);
    $header = b64url(json_encode(['alg' => $alg, 'typ' => 'JWT']));
    $payload = b64url(json_encode($claims));

    return "{$header}.{$payload}.".b64url(hash_hmac('sha256', "{$header}.{$payload}", $secret, true));
}

function txApps(array $extra = []): void
{
    config(['crm.shopify.apps' => ['levoile' => $extra + [
        'client_id' => 'client-levoile', 'client_secret' => 'app-secret',
        'client_secret_previous' => null, 'allowed_shops' => TX_SHOP,
    ]]]);
}

function appModeIntegration(array $attributes = []): ShopifyIntegration
{
    return ShopifyIntegration::create($attributes + [
        'shop_domain' => TX_SHOP, 'status' => 'connected', 'auth_mode' => 'app', 'app_client_id' => 'client-levoile',
        'access_token' => 'app-token-old', 'access_token_expires_at' => now()->addHour(),
        'refresh_token' => 'refresh-old', 'refresh_token_expires_at' => now()->addDays(80),
    ]);
}

beforeEach(function () {
    txApps();
    FakeShopifyOAuth::reset();
});

describe('IdTokenVerifier', function () {
    it('accepts a valid token and names the shop and the app', function () {
        $token = app(IdTokenVerifier::class)->verify(makeIdToken());

        expect($token->shop)->toBe(TX_SHOP)
            ->and($token->app->key)->toBe('levoile')
            ->and($token->claims['sub'])->toBe('42');
    });

    it('accepts a token signed with the previous secret during a rotation', function () {
        txApps(['client_secret_previous' => 'old-secret']);

        expect(app(IdTokenVerifier::class)->verify(makeIdToken([], 'old-secret'))->shop)->toBe(TX_SHOP);
    });

    it('rejects every broken token', function (Closure $make) {
        expect(fn () => app(IdTokenVerifier::class)->verify($make()))->toThrow(InvalidIdToken::class);
    })->with([
        'wrong signature' => [fn () => makeIdToken([], 'other-secret')],
        'expired' => [fn () => makeIdToken(['exp' => time() - 30])],
        'not yet valid' => [fn () => makeIdToken(['nbf' => time() + 120])],
        'other app' => [fn () => makeIdToken(['aud' => 'someone-else'])],
        'iss and dest differ' => [fn () => makeIdToken(['iss' => 'https://evil.myshopify.com/admin'])],
        'dest not a store' => [fn () => makeIdToken(['dest' => 'https://evil.example.com', 'iss' => 'https://evil.example.com/admin'])],
        'alg none' => [fn () => makeIdToken([], 'app-secret', 'none')],
        'garbage' => [fn () => 'not.a.jwt'],
        'empty' => [fn () => ''],
    ]);

    it('refuses a store the app is not for', function () {
        $other = 'someone-else.myshopify.com';

        expect(fn () => app(IdTokenVerifier::class)->verify(makeIdToken(['iss' => "https://{$other}/admin", 'dest' => "https://{$other}"])))
            ->toThrow(ShopNotAllowed::class);
    });
});

describe('HttpShopifyOAuth', function () {
    it('sends the exact token-exchange request for an expiring offline token', function () {
        Http::fake(['https://'.TX_SHOP.'/admin/oauth/access_token' => Http::response([
            'access_token' => 'shpat_new', 'expires_in' => 3600, 'refresh_token' => 'shprt_new',
            'refresh_token_expires_in' => 7776000, 'scope' => 'read_orders,write_orders',
        ])]);
        $app = app(ShopifyApps::class)->primary();

        $set = app(HttpShopifyOAuth::class)->exchange($app, TX_SHOP, 'the-id-token');

        Http::assertSent(fn (HttpRequest $r) => $r->url() === 'https://'.TX_SHOP.'/admin/oauth/access_token'
            && $r['client_id'] === 'client-levoile'
            && $r['client_secret'] === 'app-secret'
            && $r['grant_type'] === 'urn:ietf:params:oauth:grant-type:token-exchange'
            && $r['subject_token'] === 'the-id-token'
            && $r['subject_token_type'] === 'urn:ietf:params:oauth:token-type:id_token'
            && $r['requested_token_type'] === 'urn:shopify:params:oauth:token-type:offline-access-token'
            && (string) $r['expiring'] === '1');
        expect($set->accessToken)->toBe('shpat_new')
            ->and($set->refreshToken)->toBe('shprt_new')
            ->and($set->scopes)->toBe(['read_orders', 'write_orders'])
            ->and(abs($set->accessTokenExpiresAt->diffInSeconds(now()->addHour())))->toBeLessThan(5)
            ->and(abs($set->refreshTokenExpiresAt->diffInSeconds(now()->addDays(90))))->toBeLessThan(5);
    });

    it('sends the refresh request with the stored refresh token', function () {
        Http::fake(['*' => Http::response(['access_token' => 'a2', 'expires_in' => 3600, 'refresh_token' => 'r2', 'refresh_token_expires_in' => 7776000])]);

        app(HttpShopifyOAuth::class)->refresh(app(ShopifyApps::class)->primary(), TX_SHOP, 'r1');

        Http::assertSent(fn (HttpRequest $r) => $r['grant_type'] === 'refresh_token' && $r['refresh_token'] === 'r1'
            && $r['client_id'] === 'client-levoile' && $r['client_secret'] === 'app-secret');
    });

    it('never calls the demo shop', function () {
        Http::fake();

        expect(fn () => app(HttpShopifyOAuth::class)->exchange(app(ShopifyApps::class)->primary(), ShopifyIntegration::DEMO_SHOP_DOMAIN, 'x'))
            ->toThrow(ShopifyException::class);
        Http::assertNothingSent();
    });

    it('reports a refused grant as an auth failure', function () {
        Http::fake(['*' => Http::response(['error' => 'invalid_grant'], 400)]);

        try {
            app(HttpShopifyOAuth::class)->refresh(app(ShopifyApps::class)->primary(), TX_SHOP, 'r1');
            $this->fail('expected an exception');
        } catch (ShopifyException $e) {
            expect($e->kind)->toBe('auth');
        }
    });
});

describe('ShopifyAccessTokens', function () {
    it('hands back a manual connection token untouched', function () {
        $integration = ShopifyIntegration::create(['shop_domain' => TX_SHOP, 'access_token' => 'shpat_manual', 'status' => 'connected']);

        expect(app(ShopifyAccessTokens::class)->token($integration))->toBe('shpat_manual');
        expect(FakeShopifyOAuth::calls())->toBe([]);
    });

    it('keeps a token with more than five minutes left', function () {
        $integration = appModeIntegration(['access_token_expires_at' => now()->addMinutes(6)]);

        expect(app(ShopifyAccessTokens::class)->token($integration))->toBe('app-token-old')
            ->and(FakeShopifyOAuth::calls())->toBe([]);
    });

    it('refreshes a token about to expire and stores the rotated pair', function () {
        $integration = appModeIntegration(['access_token_expires_at' => now()->addMinutes(4)]);

        $token = app(ShopifyAccessTokens::class)->token($integration);

        $fresh = $integration->fresh();
        expect($token)->toBe($fresh->access_token)
            ->and($token)->not->toBe('app-token-old')
            ->and($fresh->refresh_token)->not->toBe('refresh-old')
            ->and($fresh->access_token_expires_at->isFuture())->toBeTrue()
            ->and(FakeShopifyOAuth::calls())->toHaveCount(1)
            ->and(FakeShopifyOAuth::calls()[0]['refresh_token'])->toBe('refresh-old');
    });

    it('does not refresh twice when another process already did it', function () {
        $integration = appModeIntegration(['access_token_expires_at' => now()->addMinutes(4)]);
        $stale = ShopifyIntegration::find($integration->id); // the second process read the row before the refresh

        $first = app(ShopifyAccessTokens::class)->token($integration);
        $second = app(ShopifyAccessTokens::class)->token($stale);

        expect($second)->toBe($first)->and(FakeShopifyOAuth::calls())->toHaveCount(1);
    });

    it('waits for the lock held by an exchange or refresh in progress', function () {
        $integration = appModeIntegration(['access_token_expires_at' => now()->addMinutes(4)]);
        $lock = Cache::lock(ShopifyAccessTokens::lockKey(TX_SHOP), 30);
        $lock->get();

        expect(fn () => app(ShopifyAccessTokens::class)->withLockWait(0)->token($integration))->toThrow(ShopifyException::class);
        expect(FakeShopifyOAuth::calls())->toBe([]);

        $lock->release();
    });

    it('marks the store broken and tells the admins when the refresh token is refused', function () {
        $admin = User::factory()->create(['role' => UserRole::Admin, 'is_active' => true]);
        $integration = appModeIntegration(['access_token_expires_at' => now()->subMinute()]);
        FakeShopifyOAuth::failNextWith(new ShopifyException('auth', 'invalid_grant'));

        expect(fn () => app(ShopifyAccessTokens::class)->token($integration))->toThrow(ShopifyException::class);

        expect($integration->fresh()->status)->toBe('error')
            ->and(UserNotification::where('user_id', $admin->id)->where('type', 'shopify.app')->value('data')['event'] ?? null)->toBe('token_refresh_failed');
    });

    it('marks the store broken when the refresh token itself has expired, without calling Shopify', function () {
        $integration = appModeIntegration(['access_token_expires_at' => now()->subMinute(), 'refresh_token_expires_at' => now()->subDay()]);

        expect(fn () => app(ShopifyAccessTokens::class)->token($integration))->toThrow(ShopifyException::class);

        expect($integration->fresh()->status)->toBe('error')->and(FakeShopifyOAuth::calls())->toBe([]);
    });

    it('leaves the store connected when the refresh fails for a passing network error', function () {
        $integration = appModeIntegration(['access_token_expires_at' => now()->subMinute()]);
        FakeShopifyOAuth::failNextWith(new ShopifyException('transport', 'timeout'));

        expect(fn () => app(ShopifyAccessTokens::class)->token($integration))->toThrow(ShopifyException::class);

        expect($integration->fresh()->status)->toBe('connected');
    });
});

describe('ShopifyClient with an app token', function () {
    it('refreshes once and retries when Shopify answers 401', function () {
        appModeIntegration();
        $transport = new class implements ShopifyTransport
        {
            public array $tokens = [];

            public function post(string $url, array $headers, array $body): array
            {
                $this->tokens[] = $headers['X-Shopify-Access-Token'];

                return count($this->tokens) === 1
                    ? ['status' => 401, 'json' => ['errors' => 'Invalid API key or access token'], 'headers' => []]
                    : ['status' => 200, 'json' => ['data' => ['shop' => ['name' => 'x']]], 'headers' => []];
            }
        };
        $client = new ShopifyClient($transport, app(IntegrationRepository::class), fn () => null);

        expect($client->query('query { shop { name } }'))->toBe(['shop' => ['name' => 'x']]);
        expect($transport->tokens[0])->toBe('app-token-old')
            ->and($transport->tokens[1])->not->toBe('app-token-old')
            ->and(ShopifyIntegration::first()->status)->toBe('connected');
    });

    it('still marks the store broken after the retry is refused too', function () {
        appModeIntegration();
        $transport = new class implements ShopifyTransport
        {
            public function post(string $url, array $headers, array $body): array
            {
                return ['status' => 401, 'json' => [], 'headers' => []];
            }
        };
        $client = new ShopifyClient($transport, app(IntegrationRepository::class), fn () => null);

        expect(fn () => $client->query('query { shop { name } }'))->toThrow(ShopifyException::class);
        expect(ShopifyIntegration::first()->status)->toBe('error')
            ->and(FakeShopifyOAuth::calls())->toHaveCount(1);
    });
});

it('binds the fake OAuth unless the Shopify driver is live', function () {
    config(['crm.shopify.driver' => 'fake']);
    expect(app(ShopifyOAuth::class))->toBeInstanceOf(FakeShopifyOAuth::class);

    config(['crm.shopify.driver' => 'live']);
    expect(app(ShopifyOAuth::class))->toBeInstanceOf(HttpShopifyOAuth::class);
});

it('builds a token set from a non-expiring answer too', function () {
    $set = TokenSet::fromResponse(['access_token' => 'a', 'scope' => 'read_orders']);

    expect($set->accessTokenExpiresAt)->toBeNull()->and($set->refreshToken)->toBeNull();
});
