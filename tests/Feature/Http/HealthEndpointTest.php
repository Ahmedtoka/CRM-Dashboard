<?php

use App\Enums\Platform;
use App\Models\ChannelAccount;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Redis;

it('reports health only with the token', function () {
    config(['crm.health.token' => 'h-token']);

    $this->getJson('/up/crm')->assertNotFound();
    $this->getJson('/up/crm', ['X-Health-Token' => 'wrong'])->assertNotFound();

    $this->getJson('/up/crm', ['X-Health-Token' => 'h-token'])->assertOk()
        ->assertJsonStructure([
            'db',
            'redis',
            'queues' => ['outbound', 'webhooks', 'bot', 'commerce', 'commerce-long', 'media', 'default', 'analytics'],
            'queues_error',
            'oldest_job_seconds',
            'reverb',
            'scheduler_last_run',
            'shopify',
            'channels',
        ])
        ->assertJsonPath('db', 'ok')
        ->assertJsonPath('queues_error', false);
});

it('reports null for a queue whose size cannot be read and flips queues_error', function () {
    config(['crm.health.token' => 'h-token']);

    Queue::shouldReceive('size')->andReturnUsing(function (string $queue) {
        if ($queue === 'webhooks') {
            throw new RuntimeException('queue connection unavailable');
        }

        return 0;
    });

    $response = $this->getJson('/up/crm', ['X-Health-Token' => 'h-token'])->assertOk();

    expect($response->json('queues.webhooks'))->toBeNull()
        ->and($response->json('queues.outbound'))->toBe(0)
        ->and($response->json('queues_error'))->toBeTrue();
});

it('computes the max oldest-job age from redis pushedAt payloads', function () {
    config(['crm.health.token' => 'h-token']);
    config(['queue.default' => 'redis']);
    config(['queue.connections.redis.connection' => 'default']);

    $pushedAt = microtime(true) - 120;
    $payload = json_encode(['pushedAt' => $pushedAt]);

    $redisConnection = Mockery::mock();
    $redisConnection->shouldReceive('lindex')
        ->andReturnUsing(fn (string $key) => str_contains($key, 'queues:outbound') ? $payload : null);
    $redisConnection->shouldReceive('ping')->andReturn(true);

    // Any arg set: redisStatus() pings with no args, oldestJobSecondsRedis() connects by name.
    Redis::shouldReceive('connection')->withAnyArgs()->andReturn($redisConnection);

    $response = $this->getJson('/up/crm', ['X-Health-Token' => 'h-token'])->assertOk();

    expect($response->json('oldest_job_seconds'))->toBeGreaterThanOrEqual(119);
});

it('reports null oldest-job age on redis when no payload carries pushedAt', function () {
    config(['crm.health.token' => 'h-token']);
    config(['queue.default' => 'redis']);
    config(['queue.connections.redis.connection' => 'default']);

    $redisConnection = Mockery::mock();
    $redisConnection->shouldReceive('lindex')->andReturn(null);
    $redisConnection->shouldReceive('ping')->andReturn(true);

    Redis::shouldReceive('connection')->withAnyArgs()->andReturn($redisConnection);

    $this->getJson('/up/crm', ['X-Health-Token' => 'h-token'])->assertOk()
        ->assertJsonPath('oldest_job_seconds', null);
});

it('always 404s when no health token is configured', function () {
    config(['crm.health.token' => null]);

    $this->getJson('/up/crm')->assertNotFound();
    $this->getJson('/up/crm', ['X-Health-Token' => ''])->assertNotFound();
});

it('reports disconnected for shopify when no integration row exists yet and reflects channel account status', function () {
    config(['crm.health.token' => 'h-token']);
    ChannelAccount::factory()->create(['platform' => Platform::Facebook, 'status' => 'connected']);

    $response = $this->getJson('/up/crm', ['X-Health-Token' => 'h-token'])->assertOk();

    expect($response->json('shopify'))->toBe('disconnected')
        ->and($response->json('channels.facebook'))->toBe('connected');
});

it('reports none for shopify when the integration table/class is not present', function () {
    config(['crm.health.token' => 'h-token']);
    Illuminate\Support\Facades\Schema::drop('shopify_integrations');

    $this->getJson('/up/crm', ['X-Health-Token' => 'h-token'])->assertOk()
        ->assertJsonPath('shopify', 'none');
});

it('reads the scheduler heartbeat from cache', function () {
    config(['crm.health.token' => 'h-token']);
    $now = now()->toISOString();
    Cache::put('crm:scheduler_heartbeat', $now);

    $this->getJson('/up/crm', ['X-Health-Token' => 'h-token'])
        ->assertOk()
        ->assertJsonPath('scheduler_last_run', $now);
});
