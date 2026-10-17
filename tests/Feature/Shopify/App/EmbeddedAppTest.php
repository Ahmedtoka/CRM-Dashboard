<?php

use App\Shopify\App\FakeShopifyOAuth;
use App\Shopify\App\IdTokenVerifier;
use App\Shopify\Client\ShopifyException;
use App\Shopify\Connection\ShopifyIntegration;
use App\Shopify\Jobs\RunBulkImportStage;
use Illuminate\Support\Facades\Queue;

/*
 * The Le Voile app's embedded home (round 2026-10-17, shopify-app.md §2.2, §5.2): a tiny stateless
 * Blade page inside Shopify admin. Opening it verifies the ID token, runs the token exchange and
 * moves the single integration row to the app (same store: same row, no new import), with a
 * per-shop frame-ancestors CSP. The Inertia CRM itself is never embedded.
 */

const EMB_SHOP = 'levoile-test.myshopify.com';

function embIdToken(array $overrides = [], string $secret = 'app-secret'): string
{
    $claims = array_replace([
        'iss' => 'https://'.EMB_SHOP.'/admin', 'dest' => 'https://'.EMB_SHOP, 'aud' => 'client-levoile',
        'sub' => '42', 'exp' => time() + 60, 'nbf' => time() - 5, 'iat' => time() - 5, 'jti' => 'j', 'sid' => 's',
    ], $overrides);
    $enc = fn (string $raw) => rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
    $head = $enc(json_encode(['alg' => 'HS256', 'typ' => 'JWT']));
    $body = $enc(json_encode($claims));

    return "{$head}.{$body}.".$enc(hash_hmac('sha256', "{$head}.{$body}", $secret, true));
}

function manualIntegration(array $attributes = []): ShopifyIntegration
{
    return ShopifyIntegration::create($attributes + [
        'shop_domain' => EMB_SHOP, 'shop_name' => 'Le Voile', 'access_token' => 'shpat_manual', 'api_secret' => 'manual-secret',
        'status' => 'connected', 'import_state' => ['stages' => [['name' => 'orders', 'status' => 'completed']]],
        'granted_scopes' => ['read_orders'],
    ]);
}

beforeEach(function () {
    config(['crm.shopify.apps' => ['levoile' => [
        'client_id' => 'client-levoile', 'client_secret' => 'app-secret', 'allowed_shops' => EMB_SHOP,
    ]]]);
    Queue::fake([RunBulkImportStage::class]);
});

it('installs on first open: exchanges the ID token and moves the same row to the app', function () {
    manualIntegration();

    $response = $this->get('/shopify/app?shop='.EMB_SHOP.'&host=abc&embedded=1&id_token='.embIdToken());

    $response->assertOk()
        ->assertSee('<meta name="shopify-api-key" content="client-levoile">', false)
        ->assertSee('https://cdn.shopify.com/shopifycloud/app-bridge.js', false)
        ->assertHeader('Content-Security-Policy', 'frame-ancestors https://'.EMB_SHOP.' https://admin.shopify.com;');

    $integration = ShopifyIntegration::sole();
    expect($integration->auth_mode)->toBe('app')
        ->and($integration->app_client_id)->toBe('client-levoile')
        ->and($integration->access_token)->toBe('fake-app-token-1')
        ->and($integration->refresh_token)->toBe('fake-refresh-1')
        ->and($integration->access_token_expires_at->isFuture())->toBeTrue()
        ->and($integration->api_secret)->toBeNull()
        ->and($integration->legacy_credentials)->toBe(['access_token' => 'shpat_manual', 'api_secret' => 'manual-secret'])
        ->and($integration->import_state['stages'][0]['status'])->toBe('completed')
        ->and($integration->installed_at)->not->toBeNull()
        ->and($integration->status)->toBe('connected')
        ->and($integration->granted_scopes)->toBe(config('crm.shopify.required_scopes'));
    Queue::assertNothingPushed();
});

it('never sets a cookie or starts a session', function () {
    manualIntegration();

    $response = $this->get('/shopify/app?shop='.EMB_SHOP.'&id_token='.embIdToken());

    expect($response->headers->getCookies())->toBe([]);
});

it('keeps the manual credentials from the first install on a reinstall', function () {
    manualIntegration();
    $this->get('/shopify/app?shop='.EMB_SHOP.'&id_token='.embIdToken())->assertOk();
    ShopifyIntegration::sole()->forceFill(['status' => 'disconnected', 'access_token' => null, 'refresh_token' => null, 'uninstalled_at' => now()])->save();

    $this->get('/shopify/app?shop='.EMB_SHOP.'&id_token='.embIdToken())->assertOk();

    $integration = ShopifyIntegration::sole();
    expect($integration->status)->toBe('connected')
        ->and($integration->access_token)->toBe('fake-app-token-2')
        ->and($integration->uninstalled_at)->toBeNull()
        ->and($integration->legacy_credentials['access_token'])->toBe('shpat_manual');
});

it('serves the bootstrap page without an ID token and finishes through the session endpoint', function () {
    manualIntegration();

    $this->get('/shopify/app?shop='.EMB_SHOP.'&host=abc')
        ->assertOk()
        ->assertHeader('Content-Security-Policy', 'frame-ancestors https://'.EMB_SHOP.' https://admin.shopify.com;');
    expect(ShopifyIntegration::sole()->auth_mode)->toBe('custom_token');

    $this->postJson('/shopify/app/session', [], ['Authorization' => 'Bearer '.embIdToken()])
        ->assertOk()
        ->assertJsonPath('shop_domain', EMB_SHOP)
        ->assertJsonPath('status', 'connected')
        ->assertJsonPath('auth_mode', 'app')
        ->assertJsonMissingPath('access_token');
    expect(ShopifyIntegration::sole()->auth_mode)->toBe('app');
});

it('frames only for admin.shopify.com when the shop parameter is not an allowed store', function (string $shop) {
    $this->get('/shopify/app?shop='.urlencode($shop))
        ->assertOk()
        ->assertHeader('Content-Security-Policy', 'frame-ancestors https://admin.shopify.com;');
})->with(['other.myshopify.com', 'evil.com', 'x.myshopify.com.evil.com', '', 'levoile-test.myshopify.com.evil.com']);

it('accepts only a bare store domain, never one with a trailing newline', function () {
    expect(IdTokenVerifier::isShopDomain('levoile-test.myshopify.com'))->toBeTrue()
        ->and(IdTokenVerifier::isShopDomain("levoile-test.myshopify.com\n"))->toBeFalse();
});

it('never frames for an unverified shop parameter while the app has no allowlist', function () {
    config(['crm.shopify.apps.levoile.allowed_shops' => null]);

    $this->get('/shopify/app?shop=anyone.myshopify.com')
        ->assertOk()
        ->assertHeader('Content-Security-Policy', 'frame-ancestors https://admin.shopify.com;');
});

it('does not move a store rolled back on purpose until the admin allows it again', function () {
    manualIntegration(['legacy_credentials' => ['access_token' => 'shpat_old', 'api_secret' => 'old']]);

    $this->postJson('/shopify/app/session', [], ['Authorization' => 'Bearer '.embIdToken()])->assertStatus(409)->assertJsonPath('error', 'manual_connection');
    expect(ShopifyIntegration::sole()->auth_mode)->toBe('custom_token')
        ->and(FakeShopifyOAuth::calls())->toBe([]);

    // Allowed again (Settings): the next open moves it and keeps the CURRENT manual token aside.
    ShopifyIntegration::sole()->forceFill(['legacy_credentials' => null])->save();
    $this->postJson('/shopify/app/session', [], ['Authorization' => 'Bearer '.embIdToken()])->assertOk();

    expect(ShopifyIntegration::sole()->auth_mode)->toBe('app')
        ->and(ShopifyIntegration::sole()->legacy_credentials['access_token'])->toBe('shpat_manual');
});

it('answers 401 to a bad ID token and changes nothing', function () {
    manualIntegration();

    $this->get('/shopify/app?shop='.EMB_SHOP.'&id_token='.embIdToken([], 'wrong'))->assertUnauthorized();
    $this->postJson('/shopify/app/session')->assertUnauthorized();
    $this->postJson('/shopify/app/session', [], ['Authorization' => 'Bearer '.embIdToken(['exp' => time() - 60])])->assertUnauthorized();

    expect(ShopifyIntegration::sole()->auth_mode)->toBe('custom_token')
        ->and(FakeShopifyOAuth::calls())->toBe([]);
});

it('answers 403 when the CRM is connected to another store', function () {
    manualIntegration(['shop_domain' => 'another-store.myshopify.com']);

    $this->postJson('/shopify/app/session', [], ['Authorization' => 'Bearer '.embIdToken()])->assertForbidden();

    expect(ShopifyIntegration::sole()->auth_mode)->toBe('custom_token');
});

it('answers 502 and changes nothing when the exchange fails', function () {
    manualIntegration();
    FakeShopifyOAuth::failNextWith(new ShopifyException('transport', 'boom'));

    $this->postJson('/shopify/app/session', [], ['Authorization' => 'Bearer '.embIdToken()])->assertStatus(502);

    expect(ShopifyIntegration::sole()->auth_mode)->toBe('custom_token');
});

it('creates the integration and starts the first import when the CRM had none', function () {
    $this->postJson('/shopify/app/session', [], ['Authorization' => 'Bearer '.embIdToken()])->assertOk();

    $integration = ShopifyIntegration::sole();
    expect($integration->shop_domain)->toBe(EMB_SHOP)
        ->and($integration->auth_mode)->toBe('app')
        ->and($integration->legacy_credentials)->toBeNull();
    Queue::assertPushed(RunBulkImportStage::class);
});

it('is off (404) while no app is configured', function () {
    config(['crm.shopify.apps' => []]);

    $this->get('/shopify/app?shop='.EMB_SHOP)->assertNotFound();
    $this->postJson('/shopify/app/session')->assertNotFound();
});
