<?php

use App\Enums\UserRole;
use App\Models\Customer;
use App\Models\Order;
use App\Models\User;
use App\Models\UserNotification;
use App\Models\WebhookEvent;
use App\Shopify\Connection\ShopifyIntegration;

/*
 * The three mandatory privacy webhooks (round 2026-10-17, shopify-app.md §1.4) arrive at
 * /webhooks/shopify/compliance, signed with the app's client secret only. customers/redact erases
 * that customer; customers/data_request and shop/redact are logged and the admins told; shop/redact
 * NEVER wipes anything (the owner purges by hand with shopify:purge-data).
 */

const COMPLIANCE_SHOP = 'levoile-test.myshopify.com';

function complianceCall($test, string $topic, array $payload, ?string $secret = 'app-secret', string $shop = COMPLIANCE_SHOP)
{
    $body = json_encode($payload);
    $server = [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_X-Shopify-Topic' => $topic,
        'HTTP_X-Shopify-Shop-Domain' => $shop,
        'HTTP_X-Shopify-Webhook-Id' => 'wh-'.uniqid('', true),
    ];
    if ($secret !== null) {
        $server['HTTP_X-Shopify-Hmac-Sha256'] = base64_encode(hash_hmac('sha256', $body, $secret, true));
    }

    return $test->call('POST', '/webhooks/shopify/compliance', [], [], [], $server, $body);
}

beforeEach(function () {
    app()['env'] = 'production';
    config([
        'crm.shopify.webhook_secret' => 'env-secret',
        'crm.allow_fake_webhooks' => false,
        'crm.shopify.apps' => ['levoile' => ['client_id' => 'client-levoile', 'client_secret' => 'app-secret', 'allowed_shops' => COMPLIANCE_SHOP]],
    ]);
    ShopifyIntegration::create(['shop_domain' => COMPLIANCE_SHOP, 'access_token' => 't', 'api_secret' => 'legacy-secret', 'status' => 'connected']);
    $this->admin = User::factory()->create(['role' => UserRole::Admin, 'is_active' => true]);
});

it('answers 401 to a bad or a non-app signature and 200 to the app signature', function () {
    complianceCall($this, 'shop/redact', ['shop_domain' => COMPLIANCE_SHOP], 'wrong')->assertUnauthorized();
    complianceCall($this, 'shop/redact', ['shop_domain' => COMPLIANCE_SHOP], 'legacy-secret')->assertUnauthorized();
    complianceCall($this, 'shop/redact', ['shop_domain' => COMPLIANCE_SHOP], 'env-secret')->assertUnauthorized();
    complianceCall($this, 'shop/redact', ['shop_domain' => COMPLIANCE_SHOP], null)->assertUnauthorized();

    complianceCall($this, 'shop/redact', ['shop_domain' => COMPLIANCE_SHOP])->assertOk();

    expect(WebhookEvent::where('event_type', 'shop/redact')->value('verified_with'))->toBe('app:levoile');
});

it('answers 401 when no app is configured', function () {
    config(['crm.shopify.apps' => []]);

    complianceCall($this, 'shop/redact', ['shop_domain' => COMPLIANCE_SHOP])->assertUnauthorized();
});

it('refuses a topic that is not a compliance topic', function () {
    complianceCall($this, 'orders/create', ['id' => 1])->assertStatus(400);

    expect(WebhookEvent::count())->toBe(0);
});

it('erases only the customer named in customers/redact', function () {
    $gone = Customer::factory()->create(['shopify_customer_id' => '5551']);
    $kept = Customer::factory()->create(['shopify_customer_id' => '5552']);
    Order::factory()->create(['customer_id' => $gone->id]);
    Order::factory()->create(['customer_id' => $kept->id]);

    complianceCall($this, 'customers/redact', [
        'shop_domain' => COMPLIANCE_SHOP,
        'customer' => ['id' => 5551, 'email' => 'x@example.com', 'phone' => '+201000000000'],
        'orders_to_redact' => [],
    ])->assertOk();

    expect(Customer::find($gone->id))->toBeNull()
        ->and(Customer::find($kept->id))->not->toBeNull()
        ->and(Order::where('customer_id', $kept->id)->count())->toBe(1)
        ->and(WebhookEvent::where('event_type', 'customers/redact')->value('status'))->toBe('processed');
});

it('never deletes anything on shop/redact and tells the admins', function () {
    $customer = Customer::factory()->create(['shopify_customer_id' => '5553']);
    Order::factory()->create(['customer_id' => $customer->id]);

    complianceCall($this, 'shop/redact', ['shop_id' => 1, 'shop_domain' => COMPLIANCE_SHOP])->assertOk();

    expect(Customer::count())->toBe(1)
        ->and(Order::count())->toBe(1)
        ->and(ShopifyIntegration::first()->status)->toBe('connected');
    $note = UserNotification::where('user_id', $this->admin->id)->where('type', 'shopify.app')->first();
    expect($note?->data['event'])->toBe('shop_redact');
});

it('records a data request and tells the admins without exporting anything', function () {
    complianceCall($this, 'customers/data_request', [
        'shop_domain' => COMPLIANCE_SHOP,
        'customer' => ['id' => 5554],
        'data_request' => ['id' => 9999],
    ])->assertOk();

    $note = UserNotification::where('user_id', $this->admin->id)->where('type', 'shopify.app')->first();
    expect($note?->data['event'])->toBe('data_request')
        ->and($note?->data['request_id'])->toBe('9999');
});

it('acts on nothing for another store', function () {
    $customer = Customer::factory()->create(['shopify_customer_id' => '5555']);

    complianceCall($this, 'customers/redact', [
        'shop_domain' => 'other.myshopify.com', 'customer' => ['id' => 5555], 'orders_to_redact' => [],
    ], 'app-secret', 'other.myshopify.com')->assertOk();

    expect(Customer::find($customer->id))->not->toBeNull()
        ->and(WebhookEvent::where('event_type', 'customers/redact')->value('status'))->toBe('ignored');
});
