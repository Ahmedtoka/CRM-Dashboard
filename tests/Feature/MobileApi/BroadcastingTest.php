<?php

use App\Enums\Platform;
use App\Enums\UserRole;
use App\Models\ChannelAccount;
use App\Models\Conversation;
use App\Models\User;

beforeEach(function () {
    // Same setup as tests/Feature/Broadcast/InboxChannelsTest: a real broadcaster so the
    // channel callbacks run, re-registered on it.
    config([
        'broadcasting.default' => 'reverb',
        'broadcasting.connections.reverb.key' => 'k',
        'broadcasting.connections.reverb.secret' => 's',
        'broadcasting.connections.reverb.app_id' => '1',
    ]);
    app('Illuminate\Broadcasting\BroadcastManager')->forgetDrivers();
    require base_path('routes/channels.php');

    $this->mod = User::factory()->create(['role' => UserRole::Moderator, 'is_active' => true]);
    $this->mod->userPlatforms()->create(['platform' => Platform::Instagram]);
    $this->token = $this->mod->createToken('pixel', ['mobile'], now()->addDays(30))->plainTextToken;
});

function mbChannelAuth(string $token, string $channel)
{
    app('auth')->forgetGuards();

    return test()->withToken($token)->postJson('/api/v1/broadcasting/auth', ['socket_id' => '1234.5678', 'channel_name' => $channel]);
}

it('lets a moderator token join her platform channel only', function () {
    mbChannelAuth($this->token, 'private-inbox.platform.instagram')->assertOk()->assertJsonStructure(['auth']);
    mbChannelAuth($this->token, 'private-inbox.platform.facebook')->assertForbidden();
});

it('refuses the all-platform inbox channel to a moderator', function () {
    mbChannelAuth($this->token, 'private-inbox')->assertForbidden();
});

it('lets her join her own user channel and nobody else\'s', function () {
    mbChannelAuth($this->token, 'private-user.'.$this->mod->id)->assertOk();
    mbChannelAuth($this->token, 'private-user.'.User::factory()->create()->id)->assertForbidden();
});

it('returns presence channel_data for a conversation on her platform', function () {
    $conv = Conversation::factory()->for(ChannelAccount::factory()->create(['platform' => Platform::Instagram]), 'channelAccount')
        ->create(['platform' => Platform::Instagram]);
    $fb = Conversation::factory()->for(ChannelAccount::factory()->create(['platform' => Platform::Facebook]), 'channelAccount')
        ->create(['platform' => Platform::Facebook]);

    $res = mbChannelAuth($this->token, 'presence-conversation.'.$conv->id)->assertOk()->assertJsonStructure(['auth', 'channel_data']);
    $data = json_decode($res->json('channel_data'), true);

    expect((int) $data['user_id'])->toBe($this->mod->id)
        ->and($data['user_info'])->toMatchArray(['id' => $this->mod->id, 'name' => $this->mod->name]);

    mbChannelAuth($this->token, 'presence-conversation.'.$fb->id)->assertForbidden();
});

it('refuses an expired or missing token', function () {
    mbChannelAuth('nope', 'private-user.'.$this->mod->id)->assertUnauthorized();

    $this->travel(31)->days();
    mbChannelAuth($this->token, 'private-user.'.$this->mod->id)->assertUnauthorized();
});
