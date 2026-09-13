<?php

use App\Analytics\LatencyRecorder;
use App\Models\ChannelAccount;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\WebhookEvent;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

it('computes percentiles per kind', function () {
    config(['crm.latency.enabled' => true]);

    $r = app(LatencyRecorder::class);
    foreach (range(1, 100) as $i) {
        $r->list('q', 0.0, $i / 1000);
    } // 1..100 ms

    $p = $r->percentiles('list', CarbonImmutable::now()->subMinute(), CarbonImmutable::now()->addMinute());

    expect($p['count'])->toBe(100)
        ->and($p['p50'])->toBe(50)
        ->and($p['p95'])->toBe(95)
        ->and($p['max'])->toBe(100);
});

it('computes an exact inbound duration in whole milliseconds via the injectable clock', function () {
    config(['crm.latency.enabled' => true]);

    // Fix round 1: webhook_events.received_at is only second precision (DATETIME(0)), so
    // the real start instant is the plain bigint received_at_ms column, and the end
    // checkpoint is read through crm.latency.clock instead of a real sleep.
    $startMs = 1_700_000_000_000;
    app()->bind('crm.latency.clock', fn () => fn (): float => ($startMs + 750) / 1000);

    $event = WebhookEvent::factory()->create(['received_at_ms' => $startMs]);
    $message = Message::factory()->for(Conversation::factory())->create();

    app(LatencyRecorder::class)->inbound($event, $message);

    $row = DB::table('latency_samples')->where('kind', 'inbound')->latest('id')->first();

    expect((int) $row->duration_ms)->toBe(750);
});

it('computes an exact outbound duration in whole milliseconds from queued_at_ms', function () {
    config(['crm.latency.enabled' => true]);

    // messages.created_at is also only second precision; queued_at_ms is the real start.
    $startMs = 1_700_000_000_000;
    $message = Message::factory()->for(Conversation::factory())->create(['queued_at_ms' => $startMs]);
    $providerCallAt = CarbonImmutable::createFromTimestampMs($startMs + 1200);

    app(LatencyRecorder::class)->outbound($message, $providerCallAt);

    $row = DB::table('latency_samples')->where('kind', 'outbound')->latest('id')->first();

    expect((int) $row->duration_ms)->toBe(1200);
});

it('records inbound latency from webhook receipt to broadcast', function () {
    config(['crm.latency.enabled' => true]);
    $payload = ['fake' => true, 'events' => [['type' => 'message', 'customer_id' => 'c1', 'name' => 'A', 'text' => 'بكام', 'id' => 'm1', 'at' => now()->toIso8601String()]]];
    ChannelAccount::factory()->create(['platform' => 'facebook', 'external_id' => 'demo-facebook']);

    $this->postJson('/webhooks/facebook', $payload)->assertOk();

    expect(\DB::table('latency_samples')->where('kind', 'inbound')->count())->toBe(1);
});

it('never records when latency tracking is disabled', function () {
    config(['crm.latency.enabled' => false]);

    app(LatencyRecorder::class)->list('q', 0.0, 0.05);

    expect(\DB::table('latency_samples')->count())->toBe(0);
});

it('swallows recorder failures instead of throwing', function () {
    config(['crm.latency.enabled' => true]);

    // duration_ms overflow-safe: an absurd endedAt shouldn't ever bubble an exception up.
    expect(fn () => app(LatencyRecorder::class)->list('q', 0.0, NAN))->not->toThrow(\Throwable::class);
});
