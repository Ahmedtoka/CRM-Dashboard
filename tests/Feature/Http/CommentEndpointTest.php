<?php

use App\Channels\Adapters\FakeChannelAdapter;
use App\Enums\{CommentStatus, Platform, UserRole};
use App\Models\{ChannelAccount, Comment, CustomerIdentity, Post, User};
use Illuminate\Support\Facades\Event;

beforeEach(function () {
    Event::fake();
    $this->admin = User::factory()->create(['role'=>UserRole::Admin]);
});

function commentOn(Platform $platform): Comment {
    $post = Post::factory()->for(ChannelAccount::factory()->state(['platform'=>$platform]), 'channelAccount')->create();
    $comment = Comment::factory()->for($post)->create();
    CustomerIdentity::factory()->create(['customer_id'=>$comment->customer_id, 'platform'=>$platform]);

    return $comment;
}

it('replies to a comment and returns the resource', function () {
    $c = commentOn(Platform::Facebook);
    $this->actingAs($this->admin)->postJson("/comments/{$c->id}/reply", ['text'=>'تم الرد'])
        ->assertOk()->assertJsonPath('data.status', 'replied');
    expect($c->fresh()->status)->toBe(CommentStatus::Replied);
});

it('maps adapter failures to 502', function () {
    $c = commentOn(Platform::Facebook);
    FakeChannelAdapter::failNext('(#200) permissions error');
    $this->actingAs($this->admin)->postJson("/comments/{$c->id}/hide")
        ->assertStatus(502)->assertJsonPath('message', '(#200) permissions error');
});

it('maps private reply not allowed to 422', function () {
    $c = commentOn(Platform::TikTok);
    $this->actingAs($this->admin)->postJson("/comments/{$c->id}/private-reply", ['text'=>'inbox'])
        ->assertStatus(422)->assertJsonStructure(['message']);
});

it('forbids moderators on other platforms and scopes the feed', function () {
    $fb = commentOn(Platform::Facebook);
    $ig = commentOn(Platform::Instagram);
    $mod = User::factory()->create(['role'=>UserRole::Moderator]);
    $mod->userPlatforms()->create(['platform'=>Platform::Facebook]);

    $this->actingAs($mod)->postJson("/comments/{$ig->id}/reply", ['text'=>'x'])->assertForbidden();
    $ids = $this->actingAs($mod)->getJson('/comments/feed')->assertOk()->json('data.*.id');
    expect($ids)->toContain($fb->id)->not->toContain($ig->id);
});
