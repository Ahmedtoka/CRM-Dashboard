<?php

use App\Enums\UserRole;
use App\Events\UserNotified;
use App\Models\User;
use App\Models\WebhookEvent;
use App\Models\WebhookRejection;
use App\Shopify\Connection\ShopifyIntegration;
use Illuminate\Support\Facades\Event;

/*
 * Le Voile app (round 2026-10-17): webhooks are verified with the app's client secret, then its
 * previous secret, then the manual connection's api secret, then the env secret; the winner is
 * recorded on webhook_events.verified_with, and X-Shopify-Shop-Domain must be the connected store.
 */

const APP_SHOP = 'levoile-test.myshopify.com';

function appWebhook($test, string $topic, array $payload, ?string $secret, ?string $shop = APP_SHOP)
{
    $body = json_encode($payload);
    $server = ['CONTENT_TYPE' => 'application/json'];

    if ($secret !== null) {
        $server['HTTP_X-Shopify-Hmac-Sha256'] = base64_encode(hash_hmac('sha256', $body, $secret, true));
    }
    if ($shop !== null) {
        $server['HTTP_X-Shopify-Shop-Domain'] = $shop;
    }
    $server['HTTP_X-Shopify-Webhook-Id'] = 'wh-'.uniqid('', true);

    return $test->call('POST', "/webhooks/shopify/{$topic}", [], [], [], $server, $body);
}

function appIntegration(array $attributes = []): ShopifyIntegration
{
    return ShopifyIntegration::create($attributes + [
        'shop_domain' => APP_SHOP, 'access_token' => 'tok', 'api_secret' => 'legacy-secret', 'status' => 'connected',
    ]);
}

beforeEach(function () {
    app()['env'] = 'production';
    config([
        'crm.drivers.commerce' => 'fake',
        'crm.shopify.driver' => 'fake',
        'crm.shopify.webhook_secret' => null,
        'crm.allow_fake_webhooks' => false,
        'crm.shopify.apps' => ['levoile' => [
            'client_id' => 'client-levoile', 'client_secret' => 'app-secret',
            'client_secret_previous' => 'app-secret-old', 'allowed_shops' => APP_SHOP,
        ]],
    ]);
});

it('verifies with each secret in order and records which one', function (?string $secret, string $label) {
    appIntegration();

    appWebhook($this, 'products-delete', ['id' => 5], $secret)->assertOk();

    expect(WebhookEvent::where('provider', 'shopify')->value('verified_with'))->toBe($label);
})->with([
    'app secret' => ['app-secret', 'app:levoile'],
    'previous app secret' => ['app-secret-old', 'app_prev:levoile'],
    'manual connection api secret' => ['legacy-secret', 'legacy'],
]);

it('keeps verifying the manual app deliveries with the credentials kept aside after installing the app', function () {
    appIntegration([
        'auth_mode' => 'app', 'app_client_id' => 'client-levoile', 'api_secret' => null,
        'legacy_credentials' => ['access_token' => 'old-tok', 'api_secret' => 'legacy-secret'],
    ]);

    appWebhook($this, 'products-delete', ['id' => 5], 'legacy-secret')->assertOk();

    expect(WebhookEvent::value('verified_with'))->toBe('legacy');
});

it('falls back to the env secret only when no manual secret is stored', function () {
    config(['crm.shopify.webhook_secret' => 'env-secret']);
    appIntegration(['api_secret' => null]);

    appWebhook($this, 'products-delete', ['id' => 5], 'env-secret')->assertOk();

    expect(WebhookEvent::value('verified_with'))->toBe('env');
});

it('rejects a delivery no secret verifies', function () {
    appIntegration();

    appWebhook($this, 'products-delete', ['id' => 5], 'nope')->assertUnauthorized();

    expect(WebhookEvent::count())->toBe(0)
        ->and(WebhookRejection::query()->value('last_reason'))->toBe('bad_hmac');
});

it('rejects a delivery for another store even when the signature is right', function () {
    appIntegration();

    appWebhook($this, 'products-delete', ['id' => 5], 'app-secret', 'someone-else.myshopify.com')->assertUnauthorized();

    expect(WebhookEvent::count())->toBe(0)
        ->and(WebhookRejection::query()->value('last_reason'))->toBe('wrong_shop');
});

it('counts an app secret as configured when the store has no manual secret', function () {
    appIntegration(['api_secret' => null]);

    appWebhook($this, 'products-delete', ['id' => 5], null)->assertUnauthorized();

    expect(WebhookRejection::query()->value('last_reason'))->toBe('missing_hmac');
});

describe('app/uninstalled is scoped to the app that sent it', function () {
    beforeEach(function () {
        Event::fake([UserNotified::class]);
        User::factory()->create(['role' => UserRole::Admin, 'is_active' => true]);
    });

    it('ignores the manual app being uninstalled while the store runs on the Partners app', function () {
        appIntegration([
            'auth_mode' => 'app', 'app_client_id' => 'client-levoile', 'api_secret' => null,
            'legacy_credentials' => ['access_token' => 'old-tok', 'api_secret' => 'legacy-secret'],
            'refresh_token' => 'r1',
        ]);

        appWebhook($this, 'app-uninstalled', ['id' => 1], 'legacy-secret')->assertOk();

        $integration = ShopifyIntegration::first();
        expect($integration->status)->toBe('connected')
            ->and($integration->access_token)->toBe('tok')
            ->and(WebhookEvent::value('status'))->toBe('processed');
        Event::assertNotDispatched(UserNotified::class);
    });

    it('disconnects and drops the app tokens when the Partners app itself is uninstalled', function () {
        appIntegration([
            'auth_mode' => 'app', 'app_client_id' => 'client-levoile', 'api_secret' => null,
            'legacy_credentials' => ['access_token' => 'old-tok', 'api_secret' => 'legacy-secret'],
            'refresh_token' => 'r1', 'access_token_expires_at' => now()->addHour(), 'refresh_token_expires_at' => now()->addDays(90),
        ]);

        appWebhook($this, 'app-uninstalled', ['id' => 1], 'app-secret')->assertOk();

        $integration = ShopifyIntegration::first();
        expect($integration->status)->toBe('disconnected')
            ->and($integration->access_token)->toBeNull()
            ->and($integration->refresh_token)->toBeNull()
            ->and($integration->access_token_expires_at)->toBeNull()
            ->and($integration->uninstalled_at)->not->toBeNull()
            // The manual credentials stay for the rollback.
            ->and($integration->legacy_credentials['access_token'])->toBe('old-tok');
        Event::assertDispatched(UserNotified::class, fn ($e) => $e->type === 'shopify.uninstalled');
    });

    it('ignores the Partners app being uninstalled while the store runs on the manual connection', function () {
        appIntegration();

        appWebhook($this, 'app-uninstalled', ['id' => 1], 'app-secret')->assertOk();

        expect(ShopifyIntegration::first()->status)->toBe('connected');
    });

    it('still disconnects the manual connection when its own app is uninstalled', function () {
        appIntegration();

        appWebhook($this, 'app-uninstalled', ['id' => 1], 'legacy-secret')->assertOk();

        expect(ShopifyIntegration::first()->status)->toBe('disconnected');
    });
});
