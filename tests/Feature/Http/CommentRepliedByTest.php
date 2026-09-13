<?php

use App\Enums\{Platform, UserRole};
use App\Models\{ChannelAccount, Comment, CustomerIdentity, Post, User};
use Illuminate\Support\Facades\Event;

beforeEach(function () {
    Event::fake();
});

it('exposes the name of the staff member who replied to a comment', function () {
    $post = Post::factory()->for(ChannelAccount::factory()->state(['platform' => Platform::Facebook]), 'channelAccount')->create(['platform' => Platform::Facebook]);
    $comment = Comment::factory()->for($post)->create();
    CustomerIdentity::factory()->create(['customer_id' => $comment->customer_id, 'platform' => Platform::Facebook]);

    $mona = User::factory()->create(['role' => UserRole::Supervisor, 'name' => 'Mona']);
    $viewer = User::factory()->create(['role' => UserRole::Admin]);

    $this->actingAs($mona)->postJson("/comments/{$comment->id}/reply", ['text' => 'تم'])
        ->assertOk()
        ->assertJsonPath('data.replied_by.id', $mona->id)
        ->assertJsonPath('data.replied_by.name', 'Mona');

    $this->actingAs($viewer)->getJson('/comments/feed')
        ->assertOk()
        ->assertJsonPath('data.0.replied_by.name', 'Mona')
        ->assertJsonPath('data.0.replied_by_type', 'user');
});

it('has no replier for bot or unreplied comments', function () {
    $post = Post::factory()->for(ChannelAccount::factory()->state(['platform' => Platform::Instagram]), 'channelAccount')->create(['platform' => Platform::Instagram]);
    Comment::factory()->for($post)->create();
    $admin = User::factory()->create(['role' => UserRole::Admin]);

    $this->actingAs($admin)->getJson('/comments/feed')
        ->assertOk()
        ->assertJsonPath('data.0.replied_by', null);
});
