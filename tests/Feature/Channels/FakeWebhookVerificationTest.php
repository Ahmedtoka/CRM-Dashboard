<?php

use App\Models\WebhookEvent;
use Illuminate\Support\Facades\Queue;

function fakeTikTokPayload(): array
{
    return ['fake' => true, 'events' => [[
        'type' => 'message', 'customer_id' => 'c1', 'name' => 'Ahmed', 'text' => 'بكام', 'id' => 'm1', 'at' => '2026-09-12T10:00:00Z',
    ]]];
}

it('rejects unauthenticated fake-driver webhooks in production', function () {
    Queue::fake();
    app()->detectEnvironment(fn () => 'production');
    config(['crm.allow_fake_webhooks' => false]);

    $this->postJson('/webhooks/tiktok', fakeTikTokPayload())->assertForbidden();

    expect(WebhookEvent::count())->toBe(0);
});

it('accepts fake-driver webhooks in production only when explicitly allowed', function () {
    Queue::fake();
    app()->detectEnvironment(fn () => 'production');
    config(['crm.allow_fake_webhooks' => true]);

    $this->postJson('/webhooks/tiktok', fakeTikTokPayload())->assertOk();

    expect(WebhookEvent::count())->toBe(1);
});

it('accepts fake-driver webhooks in local and testing environments', function () {
    Queue::fake();
    config(['crm.allow_fake_webhooks' => false]);

    $this->postJson('/webhooks/tiktok', fakeTikTokPayload())->assertOk();

    expect(WebhookEvent::count())->toBe(1);
});
