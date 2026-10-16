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

it('never overwrites the original row when a duplicate arrives', function (string $status) {
    Queue::fake();
    $payload = ['fake' => true, 'events' => [['type'=>'message','customer_id'=>'c1','name'=>'Ahmed','text'=>'بكام','id'=>'m-dup','at'=>'2026-09-12T10:00:00Z']]];
    $raw = json_encode($payload);
    $original = WebhookEvent::create([
        'provider' => 'whatsapp', 'dedupe_key' => sha1($raw), 'payload' => $payload,
        'signature_valid' => true, 'status' => $status, 'error' => $status === 'failed' ? 'boom' : null,
    ]);

    $this->call('POST', '/webhooks/whatsapp', [], [], [], ['CONTENT_TYPE' => 'application/json'], $raw)->assertOk();

    expect(WebhookEvent::count())->toBe(1)
        ->and($original->fresh()->status)->toBe($status)
        ->and($original->fresh()->error)->toBe($status === 'failed' ? 'boom' : null);
    Queue::assertNothingPushed();
})->with(['failed', 'processed', 'received']);
