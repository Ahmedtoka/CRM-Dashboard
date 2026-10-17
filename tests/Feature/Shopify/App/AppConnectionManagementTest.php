<?php

use App\Enums\UserRole;
use App\Models\ShopifySyncRun;
use App\Models\ShopifyWebhookSubscription;
use App\Models\User;
use App\Shopify\App\FakeShopifyOAuth;
use App\Shopify\Connection\ConnectShopify;
use App\Shopify\Connection\ShopifyIntegration;
use App\Shopify\Webhooks\WebhookRegistrar;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Http;

/*
 * Running on the Partners app (round 2026-10-17): its webhooks live in shopify.app.toml, so the
 * registrar never registers anything in app mode; the old app's API subscriptions are removed with
 * the old token once the owner says so; one click rolls the store back to the manual connection;
 * a scheduled refresh keeps the 90-day refresh token alive.
 */

const MGMT_SHOP = 'levoile-test.myshopify.com';

function onApp(array $attributes = []): ShopifyIntegration
{
    return ShopifyIntegration::create($attributes + [
        'shop_domain' => MGMT_SHOP, 'status' => 'connected', 'auth_mode' => 'app', 'app_client_id' => 'client-levoile',
        'access_token' => 'app-token', 'access_token_expires_at' => now()->addHour(),
        'refresh_token' => 'refresh-1', 'refresh_token_expires_at' => now()->addDays(80),
        'legacy_credentials' => ['access_token' => 'shpat_manual', 'api_secret' => 'manual-secret'],
    ]);
}

beforeEach(function () {
    config([
        'crm.shopify.driver' => 'live',
        'app.url' => 'https://crm.example.com',
        'crm.shopify.apps' => ['levoile' => ['client_id' => 'client-levoile', 'client_secret' => 'app-secret', 'allowed_shops' => MGMT_SHOP]],
    ]);
    $this->admin = User::factory()->create(['role' => UserRole::Admin, 'is_active' => true]);
});

describe('WebhookRegistrar in app mode', function () {
    it('registers nothing with Shopify and keeps one config-managed row per topic for the health screen', function () {
        onApp();
        Http::fake();

        expect(app(WebhookRegistrar::class)->register())->toBe([]);
        app(WebhookRegistrar::class)->check();

        Http::assertNothingSent();
        expect(ShopifyWebhookSubscription::count())->toBe(count(config('crm.shopify.webhook_topics')))
            ->and(ShopifyWebhookSubscription::where('managed_by', '!=', 'config')->count())->toBe(0)
            ->and(ShopifyWebhookSubscription::where('topic', 'orders/create')->value('callback_url'))->toBe('https://crm.example.com/webhooks/shopify/orders-create')
            ->and(ShopifySyncRun::where('type', 'webhook_health')->value('status'))->toBe('ok');
    });

    it('removes the old app subscriptions with the old token and keeps the rows as config-managed', function () {
        onApp();
        ShopifyWebhookSubscription::create(['topic' => 'orders/create', 'shopify_subscription_id' => 'gid://shopify/WebhookSubscription/1', 'callback_url' => 'https://crm.example.com/webhooks/shopify/orders-create', 'last_received_at' => now()]);
        Http::fake([MGMT_SHOP.'/*' => function (HttpRequest $request) {
            if (str_contains($request['query'] ?? '', 'query webhookSubscriptions')) {
                return Http::response(['data' => ['webhookSubscriptions' => ['nodes' => [
                    ['id' => 'gid://shopify/WebhookSubscription/1', 'topic' => 'ORDERS_CREATE', 'endpoint' => ['callbackUrl' => 'https://crm.example.com/webhooks/shopify/orders-create']],
                    ['id' => 'gid://shopify/WebhookSubscription/2', 'topic' => 'ORDERS_PAID', 'endpoint' => ['callbackUrl' => 'https://crm.example.com/webhooks/shopify/orders-paid']],
                    ['id' => 'gid://shopify/WebhookSubscription/3', 'topic' => 'ORDERS_PAID', 'endpoint' => ['callbackUrl' => 'https://someone-else.example.com/hook']],
                ]]]]);
            }

            return Http::response(['data' => ['webhookSubscriptionDelete' => ['deletedWebhookSubscriptionId' => $request['variables']['id'], 'userErrors' => []]]]);
        }]);

        $removed = app(WebhookRegistrar::class)->removeLegacy();

        expect($removed)->toBe(2);
        Http::assertSent(fn (HttpRequest $r) => $r->header('X-Shopify-Access-Token')[0] === 'shpat_manual');
        Http::assertNotSent(fn (HttpRequest $r) => $r->header('X-Shopify-Access-Token')[0] === 'app-token');
        Http::assertNotSent(fn (HttpRequest $r) => ($r['variables']['id'] ?? null) === 'gid://shopify/WebhookSubscription/3');
        $row = ShopifyWebhookSubscription::where('topic', 'orders/create')->first();
        expect($row->managed_by)->toBe('config')
            ->and($row->shopify_subscription_id)->toBeNull()
            ->and($row->last_received_at)->not->toBeNull();
    });
});

describe('rollback to the manual connection', function () {
    it('puts the kept token and secret back and registers the API webhooks again', function () {
        onApp();
        Http::fake([MGMT_SHOP.'/*' => function (HttpRequest $request) {
            if (str_contains($request['query'] ?? '', 'query webhookSubscriptions')) {
                return Http::response(['data' => ['webhookSubscriptions' => ['nodes' => []]]]);
            }

            return Http::response(['data' => ['webhookSubscriptionCreate' => ['webhookSubscription' => ['id' => 'gid://shopify/WebhookSubscription/'.random_int(1, 99999)], 'userErrors' => []]]]);
        }]);

        $this->actingAs($this->admin)->postJson('/settings/shopify/app/rollback')->assertOk();

        $integration = ShopifyIntegration::sole();
        expect($integration->auth_mode)->toBe('custom_token')
            ->and($integration->access_token)->toBe('shpat_manual')
            ->and($integration->api_secret)->toBe('manual-secret')
            ->and($integration->refresh_token)->toBeNull()
            ->and($integration->access_token_expires_at)->toBeNull()
            ->and($integration->status)->toBe('connected')
            ->and(ShopifyWebhookSubscription::where('managed_by', 'api')->count())->toBe(count(config('crm.shopify.webhook_topics')));
        Http::assertSent(fn (HttpRequest $r) => $r->header('X-Shopify-Access-Token')[0] === 'shpat_manual'
            && str_contains($r['query'] ?? '', 'webhookSubscriptionCreate'));
    });

    it('refuses without kept credentials, and for anyone but an admin', function () {
        onApp(['legacy_credentials' => null]);
        $supervisor = User::factory()->create(['role' => UserRole::Supervisor]);

        $this->actingAs($supervisor)->postJson('/settings/shopify/app/rollback')->assertForbidden();
        $this->actingAs($this->admin)->postJson('/settings/shopify/app/rollback')->assertStatus(422);

        expect(ShopifyIntegration::sole()->auth_mode)->toBe('app');
    });
});

it('lets the admin remove the old app webhooks from Settings', function () {
    onApp();
    Http::fake([MGMT_SHOP.'/*' => Http::response(['data' => ['webhookSubscriptions' => ['nodes' => []]]])]);

    $this->actingAs($this->admin)->postJson('/settings/shopify/app/remove-legacy-webhooks')->assertOk()->assertJsonPath('data.removed', 0);
});

it('refuses a manual connect while the store runs on the app', function () {
    onApp();

    $result = app(ConnectShopify::class)->connect(MGMT_SHOP, 'shpat_other', 'x');

    expect($result['ok'])->toBeFalse()
        ->and(ShopifyIntegration::sole()->access_token)->toBe('app-token');
});

it('shows the app connection on Settings without any secret', function () {
    onApp();

    $res = $this->actingAs($this->admin)->get('/settings/shopify')->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('integration.auth_mode', 'app')
            ->where('integration.has_legacy_credentials', true)
            ->where('integration.has_webhook_secret', true)
            ->has('integration.access_token_expires_at')
            ->where('app.configured', true)
            ->has('app.last_verified'));

    expect($res->getContent())->not->toContain('app-token')->not->toContain('refresh-1')
        ->not->toContain('shpat_manual')->not->toContain('manual-secret')->not->toContain('app-secret');
});

it('disconnecting on the app drops the app tokens too', function () {
    onApp();
    Http::fake();

    $this->actingAs($this->admin)->deleteJson('/settings/shopify')->assertOk();

    $integration = ShopifyIntegration::sole();
    expect($integration->status)->toBe('disconnected')
        ->and($integration->access_token)->toBeNull()
        ->and($integration->refresh_token)->toBeNull();
});

describe('keep-alive refresh', function () {
    it('refreshes an app token that has less than 40 minutes left', function () {
        config(['crm.shopify.driver' => 'fake']);
        onApp(['access_token_expires_at' => now()->addMinutes(20)]);

        $this->artisan('crm:shopify:refresh-app-token')->assertSuccessful();

        expect(FakeShopifyOAuth::calls())->toHaveCount(1)
            ->and(ShopifyIntegration::sole()->refresh_token)->toBe('fake-refresh-1');
    });

    it('leaves a fresh token, a manual connection and a disconnected store alone', function (array $attributes) {
        config(['crm.shopify.driver' => 'fake']);
        onApp($attributes);

        $this->artisan('crm:shopify:refresh-app-token')->assertSuccessful();

        expect(FakeShopifyOAuth::calls())->toBe([]);
    })->with([
        'fresh' => [['access_token_expires_at' => now()->addMinutes(55)]],
        'manual' => [['auth_mode' => 'custom_token', 'access_token_expires_at' => null]],
        'disconnected' => [['status' => 'disconnected', 'access_token_expires_at' => now()->addMinute()]],
    ]);

    it('is scheduled every 30 minutes without overlapping, on one server', function () {
        $event = collect(app(Schedule::class)->events())
            ->first(fn ($e) => str_contains((string) $e->command, 'crm:shopify:refresh-app-token'));

        expect($event)->not->toBeNull()
            ->and($event->expression)->toBe('*/30 * * * *')
            ->and($event->withoutOverlapping)->toBeTrue()
            ->and($event->onOneServer)->toBeTrue();
    });
});

it('records when each secret last verified a delivery', function () {
    app()['env'] = 'production';
    onApp();
    $body = json_encode(['id' => 5]);
    $this->call('POST', '/webhooks/shopify/products-delete', [], [], [], [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_X-Shopify-Hmac-Sha256' => base64_encode(hash_hmac('sha256', $body, 'manual-secret', true)),
        'HTTP_X-Shopify-Shop-Domain' => MGMT_SHOP,
    ], $body)->assertOk();

    $this->actingAs($this->admin)->get('/settings/shopify')->assertOk()
        ->assertInertia(fn ($page) => $page->has('app.last_verified.legacy'));
});
