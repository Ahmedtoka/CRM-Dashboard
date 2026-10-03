<?php

use App\Enums\Platform;
use App\Enums\UserRole;
use App\Events\ConversationUpdated;
use App\Events\CustomerUpdated;
use App\Events\MessageCreated;
use App\Events\MessageUpdated;
use App\Events\OrderUpdated;
use App\Models\ChannelAccount;
use App\Models\Conversation;
use App\Models\Customer;
use App\Models\CustomerIdentity;
use App\Models\Message;
use App\Models\Order;
use App\Models\User;

beforeEach(function () {
    // The auth route needs a real broadcaster to evaluate the channel callbacks.
    config([
        'broadcasting.default' => 'reverb',
        'broadcasting.connections.reverb.key' => 'k',
        'broadcasting.connections.reverb.secret' => 's',
        'broadcasting.connections.reverb.app_id' => '1',
    ]);
    // The channel callbacks were registered on the default (null) driver at boot: register them on this one.
    app('Illuminate\Broadcasting\BroadcastManager')->forgetDrivers();
    require base_path('routes/channels.php');
});

function channelAuth(User $u, string $channel)
{
    return test()->actingAs($u)->post('/broadcasting/auth', ['socket_id' => '1234.5678', 'channel_name' => 'private-'.$channel]);
}

function platformMod(array $platforms): User
{
    $u = User::factory()->create(['role' => UserRole::Moderator, 'is_active' => true]);
    foreach ($platforms as $p) {
        $u->userPlatforms()->create(['platform' => $p]);
    }

    return $u->fresh();
}

function channelNames(array $channels): array
{
    return array_map(fn ($c) => $c->name, $channels);
}

it('authorises the all-platform inbox channel for supervisors and admins only', function () {
    channelAuth(platformMod([Platform::Facebook, Platform::Instagram]), 'inbox')->assertForbidden();
    channelAuth(User::factory()->create(['role' => UserRole::Supervisor, 'is_active' => true]), 'inbox')->assertOk();
    channelAuth(User::factory()->create(['role' => UserRole::Admin, 'is_active' => true]), 'inbox')->assertOk();
    channelAuth(User::factory()->create(['role' => UserRole::Supervisor, 'is_active' => false]), 'inbox')->assertForbidden();
});

it('authorises a platform channel only for active users on that platform', function () {
    channelAuth(platformMod([Platform::Instagram]), 'inbox.platform.instagram')->assertOk();
    channelAuth(platformMod([Platform::Facebook]), 'inbox.platform.instagram')->assertForbidden();
    channelAuth(platformMod([]), 'inbox.platform.instagram')->assertForbidden();
    channelAuth(User::factory()->create(['role' => UserRole::Admin, 'is_active' => true]), 'inbox.platform.instagram')->assertOk();
    channelAuth(User::factory()->create(['role' => UserRole::Supervisor, 'is_active' => true]), 'inbox.platform.instagram')->assertOk();

    $inactive = platformMod([Platform::Instagram]);
    $inactive->forceFill(['is_active' => false])->save();
    channelAuth($inactive->fresh(), 'inbox.platform.instagram')->assertForbidden();
});

it('rejects an unknown platform name on the platform channel', function () {
    channelAuth(platformMod([Platform::Instagram]), 'inbox.platform.nonsense')->assertForbidden();
});

it('broadcasts conversation and message events on inbox plus only their own platform channel', function () {
    $acc = ChannelAccount::factory()->create(['platform' => Platform::Instagram]);
    $conv = Conversation::factory()->for($acc, 'channelAccount')->create(['platform' => Platform::Instagram]);
    $msg = Message::factory()->create(['conversation_id' => $conv->id]);

    foreach ([new ConversationUpdated($conv), new MessageCreated($msg), new MessageUpdated($msg)] as $event) {
        $names = channelNames($event->broadcastOn());
        expect($names)->toContain('private-inbox', 'private-inbox.platform.instagram')
            ->not->toContain('private-inbox.platform.facebook');
    }
});

it('puts an order on inbox plus its own platform channel', function () {
    $order = Order::factory()->create(['platform' => Platform::Facebook]);

    expect(channelNames((new OrderUpdated($order))->broadcastOn()))
        ->toEqualCanonicalizing(['private-inbox', 'private-inbox.platform.facebook']);
});

it('puts a customer on inbox plus every platform of its identities', function () {
    $c = Customer::factory()->create();
    CustomerIdentity::factory()->create(['customer_id' => $c->id, 'platform' => Platform::Facebook]);
    CustomerIdentity::factory()->create(['customer_id' => $c->id, 'platform' => Platform::Instagram]);

    expect(channelNames((new CustomerUpdated($c))->broadcastOn()))
        ->toEqualCanonicalizing(['private-inbox', 'private-inbox.platform.facebook', 'private-inbox.platform.instagram']);
});
