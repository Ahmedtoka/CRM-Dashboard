<?php

use App\Enums\UserRole;
use App\Models\User;
use App\Models\UserNotification;
use App\Models\WebhookEvent;
use App\Models\WebhookRejection;
use App\Webhooks\WebhookRejections;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;

// Visible webhook rejections (2026-10-17): a wrong App Secret / API secret used to fail silently.

beforeEach(function () {
    Event::fake();
    $this->admin = User::factory()->create(['role' => UserRole::Admin]);
});

function badMetaPost(object $test, string $platform = 'facebook', ?string $signature = 'sha256=bad'): void
{
    $headers = $signature === null ? [] : ['X-Hub-Signature-256' => $signature];
    $test->postJson("/webhooks/{$platform}", ['object' => 'page', 'entry' => [['id' => 'secret-page-id']]], $headers)->assertForbidden();
}

function badShopifyPost(object $test, ?string $hmac = 'bad'): void
{
    $headers = ['CONTENT_TYPE' => 'application/json'] + ($hmac === null ? [] : ['HTTP_X-Shopify-Hmac-Sha256' => $hmac]);
    $test->call('POST', '/webhooks/shopify/orders-updated', [], [], [], $headers, '{"id":1}')->assertUnauthorized();
}

it('counts a bad Meta signature per provider per hour, with the reason and path, storing no event', function () {
    config(['crm.drivers.channels' => 'live', 'crm.meta.app_secret' => 'secret']);

    badMetaPost($this);
    badMetaPost($this);
    badMetaPost($this, 'instagram', null);

    $fb = WebhookRejection::where('provider', 'facebook')->first();
    expect($fb->rejected)->toBe(2)
        ->and($fb->last_reason)->toBe('bad_signature')
        ->and($fb->last_path)->toBe('webhooks/facebook')
        ->and(WebhookRejection::where('provider', 'instagram')->value('last_reason'))->toBe('missing_signature')
        ->and(WebhookEvent::count())->toBe(0);
});

it('names a missing App Secret as the reason', function () {
    config(['crm.drivers.channels' => 'live', 'crm.meta.app_secret' => '']);

    badMetaPost($this);

    expect(WebhookRejection::value('last_reason'))->toBe('no_app_secret');
});

it('counts a bad Shopify HMAC', function () {
    config(['crm.shopify.webhook_secret' => 's3']);

    badShopifyPost($this);
    badShopifyPost($this, null);

    $row = WebhookRejection::where('provider', 'shopify')->first();
    expect($row->rejected)->toBe(2)->and($row->last_reason)->toBe('missing_hmac');
});

it('logs a throttled warning without payload or secrets', function () {
    config(['crm.drivers.channels' => 'live', 'crm.meta.app_secret' => 'very-secret-value']);
    Log::spy();

    badMetaPost($this);
    badMetaPost($this);

    Log::shouldHaveReceived('warning')->once()->withArgs(function (string $message, array $context) {
        $flat = json_encode($context);

        return $message === 'webhook.rejected'
            && $context === ['provider' => 'facebook', 'path' => 'webhooks/facebook', 'reason' => 'bad_signature']
            && ! str_contains($flat, 'very-secret-value') && ! str_contains($flat, 'secret-page-id');
    });
});

it('rings the admins once a day per provider when rejections pile up', function () {
    $this->travelTo(\Carbon\CarbonImmutable::parse('2026-10-17 10:15', 'Africa/Cairo'));
    $moderator = User::factory()->create(['role' => UserRole::Moderator]);
    $rejections = app(WebhookRejections::class);

    foreach (range(1, WebhookRejections::THRESHOLD - 1) as $i) {
        $rejections->record('shopify', 'webhooks/shopify/orders-paid', 'bad_hmac');
    }
    expect(UserNotification::count())->toBe(0);

    $rejections->record('shopify', 'webhooks/shopify/orders-paid', 'bad_hmac');
    $rejections->record('shopify', 'webhooks/shopify/orders-paid', 'bad_hmac');

    expect(UserNotification::where('type', WebhookRejections::NOTIFICATION)->count())->toBe(1)
        ->and(UserNotification::first()->user_id)->toBe($this->admin->id)
        ->and(UserNotification::first()->data['provider'])->toBe('shopify')
        ->and(UserNotification::where('user_id', $moderator->id)->count())->toBe(0);

    // The next hour, the same day: no second bell.
    $this->travel(1)->hours();
    foreach (range(1, WebhookRejections::THRESHOLD) as $i) {
        $rejections->record('shopify', 'webhooks/shopify/orders-paid', 'bad_hmac');
    }
    expect(UserNotification::count())->toBe(1);

    // Another provider has its own bell.
    foreach (range(1, WebhookRejections::THRESHOLD) as $i) {
        $rejections->record('facebook', 'webhooks/facebook', 'bad_signature');
    }
    expect(UserNotification::count())->toBe(2);
});

it('summarises the last 24 hours only', function () {
    $rejections = app(WebhookRejections::class);
    $this->travel(-30)->hours();
    $rejections->record('facebook', 'webhooks/facebook', 'bad_signature');
    $this->travelBack();
    $rejections->record('facebook', 'webhooks/facebook', 'bad_signature');
    $rejections->record('facebook', 'webhooks/facebook', 'missing_signature');

    $summary = $rejections->summary('facebook');

    expect($summary['count'])->toBe(2)
        ->and($summary['last_reason'])->toBe('missing_signature')
        ->and($summary['last_at'])->not->toBeNull()
        ->and($rejections->summary('instagram')['count'])->toBe(0);
});

it('wipes the counters on a fresh start', function () {
    expect(\App\Maintenance\FreshStart::WIPE)->toContain('webhook_rejections');
});

it('shows the rejections on Settings → Channels and Settings → Shopify', function () {
    app(WebhookRejections::class)->record('facebook', 'webhooks/facebook', 'bad_signature');
    app(WebhookRejections::class)->record('shopify', 'webhooks/shopify/orders-paid', 'bad_hmac');

    $this->actingAs($this->admin)->get('/settings/channels')->assertOk()
        ->assertInertia(fn ($page) => $page->where('webhookRejections.facebook.count', 1)->where('webhookRejections.whatsapp.count', 0));

    $this->actingAs($this->admin)->get('/settings/shopify')->assertOk()
        ->assertInertia(fn ($page) => $page->where('webhookRejections.count', 1)->has('lastWebhookAt'));
});
