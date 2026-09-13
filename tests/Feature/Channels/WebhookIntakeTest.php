<?php

use App\Channels\Jobs\ProcessWebhookEvent;
use App\Models\WebhookEvent;
use Illuminate\Support\Facades\Queue;

it('stores, dedupes and queues webhook payloads', function () {
    Queue::fake();
    $payload = ['fake' => true, 'events' => [['type'=>'message','customer_id'=>'c1','name'=>'Ahmed','text'=>'بكام','id'=>'m1','at'=>'2026-09-12T10:00:00Z']]];
    $this->postJson('/webhooks/whatsapp', $payload)->assertOk();
    $this->postJson('/webhooks/whatsapp', $payload)->assertOk();
    expect(WebhookEvent::count())->toBe(1);
    Queue::assertPushedOn('webhooks', ProcessWebhookEvent::class);
    Queue::assertPushed(ProcessWebhookEvent::class, 1);
});

it('answers the meta verification handshake', function () {
    config(['crm.drivers.channels' => 'live', 'crm.meta.verify_token' => 'tok']);
    $this->get('/webhooks/facebook?hub_mode=subscribe&hub_verify_token=tok&hub_challenge=12345')
        ->assertOk()->assertSee('12345');
    $this->get('/webhooks/facebook?hub_mode=subscribe&hub_verify_token=bad&hub_challenge=1')->assertForbidden();
});

it('rejects bad meta signatures in live mode', function () {
    config(['crm.drivers.channels' => 'live', 'crm.meta.app_secret' => 'secret']);
    $this->postJson('/webhooks/facebook', ['object' => 'page', 'entry' => []], ['X-Hub-Signature-256' => 'sha256=bad'])
        ->assertForbidden();
});
