<?php

use App\Channels\Adapters\FakeChannelAdapter;
use App\Enums\Platform;
use App\Enums\UserRole;
use App\Models\ChannelAccount;
use App\Models\Conversation;
use App\Models\CustomerIdentity;
use App\Models\Post;
use App\Models\User;

beforeEach(function () {
    config(['crm.dev_tools' => true]);
    $this->admin = User::factory()->create(['role' => UserRole::Admin]);
});

function seedCanonicalAccounts(): void
{
    foreach (Platform::cases() as $p) {
        ChannelAccount::factory()->create(['platform' => $p, 'external_id' => 'demo-'.$p->value, 'driver' => 'fake']);
    }
}

it('reuses the canonical demo account so follow-up messages stay in one conversation', function () {
    seedCanonicalAccounts();

    foreach (['السلام عليكم', 'بكام؟'] as $text) {
        $this->actingAs($this->admin)->postJson('/simulator/message', [
            'platform' => 'instagram', 'customer_key' => 'cust-7', 'name' => 'Nour', 'text' => $text,
        ])->assertCreated();
    }

    expect(ChannelAccount::count())->toBe(4)
        ->and(ChannelAccount::where('external_id', 'fake')->exists())->toBeFalse()
        ->and(Conversation::count())->toBe(1)
        ->and(Conversation::first()->channelAccount->external_id)->toBe('demo-instagram')
        ->and(Conversation::first()->messages()->where('direction', 'in')->count())->toBe(2);
});

it('creates a single demo-{platform} account when none exists yet', function () {
    $this->actingAs($this->admin)->postJson('/simulator/message', [
        'platform' => 'whatsapp', 'customer_key' => 'cust-8', 'name' => 'Hala', 'text' => 'مرحبا',
    ])->assertCreated();

    $this->actingAs($this->admin)->postJson('/simulator/comment', [
        'platform' => 'whatsapp', 'post_key' => 'post-1', 'customer_key' => 'cust-8', 'name' => 'Hala', 'text' => 'تمام',
    ])->assertCreated();

    expect(ChannelAccount::where('platform', 'whatsapp')->pluck('external_id')->all())->toBe(['demo-whatsapp'])
        ->and(Post::first()->channel_account_id)->toBe(ChannelAccount::where('platform', 'whatsapp')->value('id'));
});

it('gives every burst message its own customer key on the canonical account', function () {
    seedCanonicalAccounts();

    $this->actingAs($this->admin)->postJson('/simulator/burst', [
        'count' => 5, 'seconds' => 0, 'platforms' => ['facebook'],
    ])->assertStatus(202)->assertJsonPath('data.queued', 5);

    expect(ChannelAccount::count())->toBe(4)
        ->and(CustomerIdentity::where('platform', 'facebook')->distinct()->count('external_id'))->toBe(5)
        ->and(Conversation::where('platform', 'facebook')->count())->toBe(5)
        ->and(Conversation::pluck('channel_account_id')->unique()->all())
        ->toBe([ChannelAccount::where('external_id', 'demo-facebook')->value('id')]);
});

it('reports the channel account external id from the fake adapter instead of a fixed "fake" id', function () {
    seedCanonicalAccounts();

    $dto = (new FakeChannelAdapter(Platform::Facebook))->normalize(['events' => [[
        'type' => 'message', 'id' => 'm1', 'customer_id' => 'c1', 'name' => 'A', 'text' => 'hi', 'at' => now()->toIso8601String(),
    ]]])[0];

    expect($dto->channelExternalId)->toBe('demo-facebook');
});
