<?php

use App\Enums\Platform;
use App\Enums\UserRole;
use App\Models\ChannelAccount;
use App\Models\Conversation;
use App\Models\Customer;
use App\Models\User;

it('filters conversations by customer order flags', function () {
    $admin = User::factory()->create(['role' => UserRole::Admin]);
    $repeat = Conversation::factory()->for(Customer::factory()->state(['is_repeat' => true]))->create();
    $open = Conversation::factory()->for(Customer::factory()->state(['has_open_order' => true]))->create();
    $return = Conversation::factory()->for(Customer::factory()->state(['has_return' => true]))->create();
    $stuck = Conversation::factory()->for(Customer::factory()->state(['has_stuck_order' => true]))->create();
    $new = Conversation::factory()->for(Customer::factory()->state(['orders_count' => 1]))->create();
    $plain = Conversation::factory()->for(Customer::factory())->create();

    $ids = fn ($f) => collect($this->actingAs($admin)->getJson("/inbox/conversations?filter={$f}")->assertOk()->json('data'))->pluck('id');

    expect($ids('customer_repeat'))->toContain($repeat->id)->not->toContain($plain->id)
        ->and($ids('open_order'))->toContain($open->id)->not->toContain($repeat->id)
        ->and($ids('has_return'))->toContain($return->id)->not->toContain($plain->id)
        ->and($ids('stuck_order'))->toContain($stuck->id)->not->toContain($plain->id)
        ->and($ids('customer_new'))->toContain($new->id)->not->toContain($repeat->id)->not->toContain($plain->id);
});

it('combines a customer filter with the platform filter', function () {
    $admin = User::factory()->create(['role' => UserRole::Admin]);
    $fbAccount = ChannelAccount::factory()->create(['platform' => 'facebook']);
    $igAccount = ChannelAccount::factory()->create(['platform' => 'instagram']);

    $fb = Conversation::factory()->for(Customer::factory()->state(['is_repeat' => true]))->for($fbAccount, 'channelAccount')->create(['platform' => 'facebook']);
    $ig = Conversation::factory()->for(Customer::factory()->state(['is_repeat' => true]))->for($igAccount, 'channelAccount')->create(['platform' => 'instagram']);

    $ids = collect($this->actingAs($admin)->getJson('/inbox/conversations?filter=customer_repeat&platform=facebook')->assertOk()->json('data'))->pluck('id');

    expect($ids)->toContain($fb->id)->not->toContain($ig->id);
});

it('rejects an unknown filter value', function () {
    $admin = User::factory()->create(['role' => UserRole::Admin]);

    $this->actingAs($admin)->getJson('/inbox/conversations?filter=not_a_real_filter')->assertStatus(422);
});

it('scopes a customer filter to a moderator platforms, on top of the customer flag', function () {
    $mod = User::factory()->create(['role' => UserRole::Moderator]);
    $mod->userPlatforms()->create(['platform' => Platform::Instagram]);

    $igAccount = ChannelAccount::factory()->create(['platform' => 'instagram']);
    $fbAccount = ChannelAccount::factory()->create(['platform' => 'facebook']);

    // Both conversations' customers are repeat customers; only the Instagram one is on a
    // platform this moderator is assigned to.
    $ig = Conversation::factory()->for(Customer::factory()->state(['is_repeat' => true]))->for($igAccount, 'channelAccount')->create(['platform' => 'instagram']);
    $fb = Conversation::factory()->for(Customer::factory()->state(['is_repeat' => true]))->for($fbAccount, 'channelAccount')->create(['platform' => 'facebook']);

    $ids = collect($this->actingAs($mod)->getJson('/inbox/conversations?filter=customer_repeat')->assertOk()->json('data'))->pluck('id');

    expect($ids)->toContain($ig->id)->not->toContain($fb->id);
});
