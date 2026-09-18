<?php

use App\Channels\Adapters\InstagramAdapter;
use App\Channels\Adapters\MessengerAdapter;
use App\Channels\Adapters\MetaGraphClient;
use App\Channels\Data\SendResult;
use App\Enums\Platform;
use App\Models\ChannelAccount;
use App\Models\CustomerIdentity;
use Illuminate\Support\Facades\Http;

beforeEach(fn () => Http::preventStrayRequests());

it('sends typing once, without retrying, when the graph call fails', function (string $adapter, Platform $platform) {
    Http::fake(['graph.facebook.com/*' => Http::response(['error' => ['message' => 'down']], 500)]);
    $acc = ChannelAccount::factory()->create(['platform' => $platform, 'driver' => 'live', 'credentials' => ['access_token' => 'tok']]);
    $to = CustomerIdentity::factory()->create(['platform' => $platform, 'external_id' => 'PSID1']);

    app($adapter)->typing($acc, $to, true);

    Http::assertSentCount(1);
    Http::assertSent(fn ($r) => ($r->data()['sender_action'] ?? null) === 'typing_on');
})->with([
    'messenger' => [MessengerAdapter::class, Platform::Facebook],
    'instagram' => [InstagramAdapter::class, Platform::Instagram],
]);

it('sends typing through the fast path with a 3 second timeout', function (string $adapter, Platform $platform) {
    $graph = Mockery::mock(MetaGraphClient::class);
    $graph->shouldReceive('postFast')->once()
        ->withArgs(fn ($account, $endpoint, $payload, $timeout) => $endpoint === 'me/messages' && $payload['sender_action'] === 'typing_off' && $timeout === 3)
        ->andReturn(SendResult::ok(''));
    $graph->shouldNotReceive('post');
    app()->instance(MetaGraphClient::class, $graph);

    $acc = ChannelAccount::factory()->create(['platform' => $platform, 'driver' => 'live', 'credentials' => ['access_token' => 'tok']]);
    $to = CustomerIdentity::factory()->create(['platform' => $platform, 'external_id' => 'PSID1']);

    app($adapter)->typing($acc, $to, false);
})->with([
    'messenger' => [MessengerAdapter::class, Platform::Facebook],
    'instagram' => [InstagramAdapter::class, Platform::Instagram],
]);
