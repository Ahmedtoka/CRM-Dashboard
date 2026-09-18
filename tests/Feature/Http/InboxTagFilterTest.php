<?php

use App\Enums\UserRole;
use App\Models\ChannelAccount;
use App\Models\Conversation;
use App\Models\Tag;
use App\Models\User;

it('filters the inbox list by tag', function () {
    $vip = Tag::factory()->create(['name' => 'VIP']);
    $tagged = Conversation::factory()->create(['last_message_at' => now()]);
    $tagged->tags()->attach($vip);
    Conversation::factory()->create(['last_message_at' => now()]);

    $this->actingAs(User::factory()->create(['role' => UserRole::Admin]))
        ->getJson("/inbox/conversations?tag={$vip->id}")->assertOk()
        ->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $tagged->id);

    $this->actingAs(User::factory()->create(['role' => UserRole::Admin]))->getJson('/inbox/conversations?tag=999999')->assertStatus(422);
});

it('scopes the tag filter to a moderator platforms', function () {
    $mod = User::factory()->create(['role' => UserRole::Moderator]);
    $mod->userPlatforms()->create(['platform' => 'instagram']);

    $vip = Tag::factory()->create(['name' => 'VIP']);
    $igAccount = ChannelAccount::factory()->create(['platform' => 'instagram']);
    $fbAccount = ChannelAccount::factory()->create(['platform' => 'facebook']);

    $ig = Conversation::factory()->for($igAccount, 'channelAccount')->create(['platform' => 'instagram', 'last_message_at' => now()]);
    $ig->tags()->attach($vip);
    $fb = Conversation::factory()->for($fbAccount, 'channelAccount')->create(['platform' => 'facebook', 'last_message_at' => now()]);
    $fb->tags()->attach($vip);

    $ids = collect($this->actingAs($mod)->getJson("/inbox/conversations?tag={$vip->id}")->assertOk()->json('data'))->pluck('id');

    expect($ids)->toContain($ig->id)->not->toContain($fb->id);
});
