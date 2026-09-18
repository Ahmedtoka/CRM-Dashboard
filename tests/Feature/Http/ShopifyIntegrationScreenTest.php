<?php

use App\Enums\UserRole;
use App\Models\Product;
use App\Models\ShopifySyncRun;
use App\Models\User;
use App\Shopify\Connection\ShopifyIntegration;
use App\Shopify\Jobs\RunBulkImportStage;
use App\Shopify\Jobs\RunManualSync;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    config(['crm.shopify.driver' => 'live']);
    $this->admin = User::factory()->create(['role' => UserRole::Admin]);
    $this->sup = User::factory()->create(['role' => UserRole::Supervisor]);
});

it('is admin only and never exposes secrets', function () {
    ShopifyIntegration::create(['shop_domain' => 'demo.myshopify.com', 'access_token' => 'shpat_secret', 'api_secret' => 'sec', 'status' => 'connected']);
    $this->actingAs($this->sup)->get('/settings/shopify')->assertForbidden();
    $res = $this->actingAs($this->admin)->get('/settings/shopify')->assertOk();
    expect($res->getContent())->not->toContain('shpat_secret')->not->toContain('"sec"');
});

/**
 * Task 10 verification (controller ruling: confirm the settings screen renders
 * without logging into the running preview): with no integration row at all —
 * the state of a fresh install — the page must render successfully and carry
 * the props `settings/Shopify.vue` needs to show `ConnectGuide` (`integration:
 * null` drives `needsCredentials`, `requiredScopes` is what the guide lists).
 */
it('renders the connect guide props when no integration exists yet', function () {
    expect(ShopifyIntegration::count())->toBe(0);

    $this->actingAs($this->admin)->get('/settings/shopify')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('settings/Shopify')
            ->where('integration', null)
            ->where('requiredScopes', config('crm.shopify.required_scopes'))
        );
});

it('refuses to connect when scopes are missing', function () {
    Http::fake(['demo.myshopify.com/*' => Http::response(['data' => ['shop' => ['name' => 'D', 'currencyCode' => 'EGP'], 'currentAppInstallation' => ['accessScopes' => [['handle' => 'read_products']]]]])]);
    $this->actingAs($this->admin)->postJson('/settings/shopify/connect', ['shop_domain' => 'demo.myshopify.com', 'access_token' => 't', 'api_secret' => 's'])
        ->assertStatus(422)->assertJsonPath('missing_scopes.0', 'read_inventory');
    expect(ShopifyIntegration::count())->toBe(0);
});

it('connects, registers webhooks and starts the import', function () {
    Queue::fake();
    $scopes = collect(config('crm.shopify.required_scopes'))->map(fn ($h) => ['handle' => $h])->all();
    Http::fake(['demo.myshopify.com/*' => function ($req) use ($scopes) {
        if (str_contains($req['query'] ?? '', 'webhookSubscriptionCreate')) {
            return Http::response(['data' => ['webhookSubscriptionCreate' => ['webhookSubscription' => ['id' => 'gid://shopify/WebhookSubscription/1'], 'userErrors' => []]]]);
        }

        return Http::response(['data' => ['shop' => ['name' => 'D', 'currencyCode' => 'EGP'], 'currentAppInstallation' => ['accessScopes' => $scopes]]]);
    }]);
    $this->actingAs($this->admin)->post('/settings/shopify/connect', ['shop_domain' => 'demo.myshopify.com', 'access_token' => 't', 'api_secret' => 's'])->assertRedirect();
    expect(ShopifyIntegration::first()->status)->toBe('connected');
    Queue::assertPushed(RunBulkImportStage::class, fn ($j) => $j->stage === 'shipping');
});

it('disconnects and keeps local data', function () {
    Http::fake(['demo.myshopify.com/*' => Http::response(['data' => ['webhookSubscriptionDelete' => ['deletedWebhookSubscriptionId' => '1', 'userErrors' => []]]])]);
    ShopifyIntegration::create(['shop_domain' => 'demo.myshopify.com', 'access_token' => 't', 'api_secret' => 's', 'status' => 'connected']);
    Product::factory()->create();
    $this->actingAs($this->admin)->delete('/settings/shopify')->assertRedirect();
    expect(ShopifyIntegration::first()->status)->toBe('disconnected')->and(ShopifyIntegration::first()->access_token)->toBeNull()
        ->and(Product::count())->toBe(1);
});

it('re-connects idempotently after an error without duplicating the integration row', function () {
    $integration = ShopifyIntegration::create([
        'shop_domain' => 'demo.myshopify.com',
        'access_token' => 'old-token',
        'api_secret' => 'old-secret',
        'status' => 'error',
        'last_error' => 'Shopify authentication failed',
        'import_state' => ['stages' => ['shipping' => ['status' => 'completed', 'total' => 3, 'processed' => 3, 'failed' => 0, 'bulk_operation_id' => null]]],
    ]);
    Queue::fake();
    $scopes = collect(config('crm.shopify.required_scopes'))->map(fn ($h) => ['handle' => $h])->all();
    Http::fake(['demo.myshopify.com/*' => function ($req) use ($scopes) {
        if (str_contains($req['query'] ?? '', 'webhookSubscriptionCreate')) {
            return Http::response(['data' => ['webhookSubscriptionCreate' => ['webhookSubscription' => ['id' => 'gid://shopify/WebhookSubscription/2'], 'userErrors' => []]]]);
        }

        return Http::response(['data' => ['shop' => ['name' => 'D', 'currencyCode' => 'EGP'], 'currentAppInstallation' => ['accessScopes' => $scopes]]]);
    }]);

    $this->actingAs($this->admin)->post('/settings/shopify/connect', [
        'shop_domain' => 'demo.myshopify.com',
        'access_token' => 'new-token',
        'api_secret' => 'new-secret',
    ])->assertRedirect();

    expect(ShopifyIntegration::count())->toBe(1);
    $fresh = $integration->fresh();
    expect($fresh->id)->toBe($integration->id)
        ->and($fresh->status)->toBe('connected')
        ->and($fresh->last_error)->toBeNull()
        ->and($fresh->access_token)->toBe('new-token');

    // A stage already completed before this reconnect: the resumed import continues rather than restarting from scratch.
    Queue::assertNotPushed(RunBulkImportStage::class, fn ($j) => $j->stage === 'shipping');
});

it('connects when only an optional scope is missing', function () {
    Queue::fake();
    $scopes = collect(config('crm.shopify.required_scopes'))->reject(fn ($h) => $h === 'write_draft_orders')->map(fn ($h) => ['handle' => $h])->values()->all();
    Http::fake(['demo.myshopify.com/*' => function ($req) use ($scopes) {
        if (str_contains($req['query'] ?? '', 'webhookSubscriptionCreate')) {
            return Http::response(['data' => ['webhookSubscriptionCreate' => ['webhookSubscription' => ['id' => 'gid://shopify/WebhookSubscription/4'], 'userErrors' => []]]]);
        }

        return Http::response(['data' => ['shop' => ['name' => 'D', 'currencyCode' => 'EGP'], 'currentAppInstallation' => ['accessScopes' => $scopes]]]);
    }]);

    $this->actingAs($this->admin)->postJson('/settings/shopify/test', ['shop_domain' => 'demo.myshopify.com', 'access_token' => 't'])
        ->assertJsonPath('missing_scopes', ['write_draft_orders'])
        ->assertJsonPath('optional_scopes', ['write_draft_orders']);

    $this->actingAs($this->admin)->post('/settings/shopify/connect', ['shop_domain' => 'demo.myshopify.com', 'access_token' => 't'])->assertRedirect();
    expect(ShopifyIntegration::first()->status)->toBe('connected');
});

it('connects without an api secret and keeps a previously stored one', function () {
    ShopifyIntegration::create(['shop_domain' => 'demo.myshopify.com', 'access_token' => 'old', 'api_secret' => 'kept-secret', 'status' => 'error']);
    Queue::fake();
    $scopes = collect(config('crm.shopify.required_scopes'))->map(fn ($h) => ['handle' => $h])->all();
    Http::fake(['demo.myshopify.com/*' => function ($req) use ($scopes) {
        if (str_contains($req['query'] ?? '', 'webhookSubscriptionCreate')) {
            return Http::response(['data' => ['webhookSubscriptionCreate' => ['webhookSubscription' => ['id' => 'gid://shopify/WebhookSubscription/3'], 'userErrors' => []]]]);
        }

        return Http::response(['data' => ['shop' => ['name' => 'D', 'currencyCode' => 'EGP'], 'currentAppInstallation' => ['accessScopes' => $scopes]]]);
    }]);

    $this->actingAs($this->admin)->post('/settings/shopify/connect', ['shop_domain' => 'demo.myshopify.com', 'access_token' => 'new', 'api_secret' => null])->assertRedirect();

    $fresh = ShopifyIntegration::first();
    expect($fresh->status)->toBe('connected')->and($fresh->access_token)->toBe('new')->and($fresh->api_secret)->toBe('kept-secret');
});

it('returns a conflict when a bulk import chain is already running', function () {
    ShopifyIntegration::create([
        'shop_domain' => 'demo.myshopify.com',
        'access_token' => 't',
        'api_secret' => 's',
        'status' => 'error',
        'import_state' => ['stages' => ['shipping' => ['status' => 'running', 'updated_at' => now()->toIso8601String(), 'total' => null, 'processed' => 0, 'failed' => 0, 'bulk_operation_id' => null]]],
    ]);
    $scopes = collect(config('crm.shopify.required_scopes'))->map(fn ($h) => ['handle' => $h])->all();
    Http::fake(['demo.myshopify.com/*' => Http::response(['data' => ['shop' => ['name' => 'D', 'currencyCode' => 'EGP'], 'currentAppInstallation' => ['accessScopes' => $scopes]]])]);

    $this->actingAs($this->admin)->postJson('/settings/shopify/connect', ['shop_domain' => 'demo.myshopify.com', 'access_token' => 't2', 'api_secret' => 's2'])
        ->assertStatus(409)
        ->assertJsonPath('ok', false);
});

it('exposes live status as json without secrets for the polling fallback', function () {
    ShopifyIntegration::create(['shop_domain' => 'demo.myshopify.com', 'access_token' => 'shpat_secret', 'api_secret' => 'sec', 'status' => 'connected', 'import_state' => ['stages' => []]]);
    ShopifySyncRun::factory()->create(['resource' => 'products', 'status' => 'completed']);

    $this->actingAs($this->sup)->getJson('/settings/shopify/status')->assertForbidden();

    $res = $this->actingAs($this->admin)->getJson('/settings/shopify/status')
        ->assertOk()
        ->assertJsonPath('integration.shop_domain', 'demo.myshopify.com')
        ->assertJsonPath('integration.status', 'connected')
        ->assertJsonCount(1, 'runs')
        ->assertJsonStructure(['integration', 'runs', 'webhooks']);

    expect($res->getContent())->not->toContain('shpat_secret')->not->toContain('"sec"');
});

it('dispatches a unique manual sync job per resource and validates the orders date range', function () {
    Queue::fake();
    ShopifyIntegration::create(['shop_domain' => 'demo.myshopify.com', 'access_token' => 't', 'api_secret' => 's', 'status' => 'connected']);

    $this->actingAs($this->admin)->postJson('/settings/shopify/sync', ['resource' => 'orders'])
        ->assertStatus(422);

    $this->actingAs($this->admin)->postJson('/settings/shopify/sync', ['resource' => 'orders', 'from' => '2020-01-01', 'to' => '2025-01-01'])
        ->assertStatus(422);

    $this->actingAs($this->admin)->postJson('/settings/shopify/sync', ['resource' => 'products'])->assertOk();
    $this->actingAs($this->admin)->postJson('/settings/shopify/sync', ['resource' => 'products'])->assertOk();

    Queue::assertPushed(RunManualSync::class, 1);
});

it('updates commerce settings within their bounds', function () {
    ShopifyIntegration::create(['shop_domain' => 'demo.myshopify.com', 'access_token' => 't', 'api_secret' => 's', 'status' => 'connected']);

    $this->actingAs($this->admin)->putJson('/settings/shopify/settings', [
        'default_shipping_fee' => 75,
        'auto_create_shipment' => false,
        'stuck_order_days' => 7,
        'mismatch_alerts' => true,
        'order_creation_enabled' => true,
    ])->assertOk();

    expect(ShopifyIntegration::first()->settings['default_shipping_fee'])->toEqual(75);

    $this->actingAs($this->admin)->putJson('/settings/shopify/settings', [
        'default_shipping_fee' => 10001,
        'auto_create_shipment' => false,
        'stuck_order_days' => 7,
        'mismatch_alerts' => true,
        'order_creation_enabled' => true,
    ])->assertStatus(422);
});
