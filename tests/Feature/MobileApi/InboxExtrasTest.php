<?php

use App\Enums\Platform;
use App\Enums\UserRole;
use App\Models\ChannelAccount;
use App\Models\Conversation;
use App\Models\Tag;
use App\Models\User;
use Laravel\Sanctum\Sanctum;

/* Inbox extras on the mobile API: filter-bar counts, the tag list, tag sync and the conversation context. */

function mxChat(Platform $platform, array $attrs = []): Conversation
{
    $acc = ChannelAccount::factory()->create(['platform' => $platform]);

    return Conversation::factory()->for($acc, 'channelAccount')->create($attrs + ['platform' => $platform]);
}

function mxModerator(Platform ...$platforms): User
{
    $u = User::factory()->create(['role' => UserRole::Moderator]);
    foreach ($platforms as $p) {
        $u->userPlatforms()->create(['platform' => $p]);
    }

    return $u;
}

// ───── counts ─────

it('serves the web counts JSON, scoped to her platforms', function () {
    mxChat(Platform::Facebook, ['status' => 'open']);
    mxChat(Platform::WhatsApp, ['status' => 'open']);
    $mod = mxModerator(Platform::Facebook);
    Sanctum::actingAs($mod);

    $api = $this->getJson('/api/v1/conversations/counts')->assertOk()->assertJsonPath('status.open', 1)->json();
    expect($api)->toBe($this->actingAs($mod, 'web')->getJson('/inbox/conversations/counts')->json());
});

it('validates the counts params like the list', function (string $qs, string $field) {
    Sanctum::actingAs(mxModerator(Platform::Facebook));

    $this->getJson('/api/v1/conversations/counts?'.$qs)->assertUnprocessable()->assertJsonValidationErrors([$field]);
})->with([
    'platform' => ['platform=myspace', 'platform'],
    'tag' => ['tag=999999', 'tag'],
    'flags' => ['flags=nonsense', 'flags'],
    'assignee' => ['assignee=someone', 'assignee'],
    'from' => ['from=2026/10/01', 'from'],
]);

// ───── tags ─────

it('lists the tags by name with id, name and color only', function () {
    $b = Tag::factory()->create(['name' => 'Beta', 'color' => '#00ff00']);
    $a = Tag::factory()->create(['name' => 'Alpha', 'color' => '#ff0000']);
    Sanctum::actingAs(mxModerator(Platform::Facebook));

    $this->getJson('/api/v1/tags')->assertOk()->assertExactJson(['data' => [
        ['id' => $a->id, 'name' => 'Alpha', 'color' => '#ff0000'],
        ['id' => $b->id, 'name' => 'Beta', 'color' => '#00ff00'],
    ]]);
});

it('syncs tags on a conversation on her platform', function () {
    $c = mxChat(Platform::Facebook);
    [$t1, $t2] = Tag::factory()->count(2)->create()->all();
    Sanctum::actingAs(mxModerator(Platform::Facebook));

    $this->postJson("/api/v1/conversations/{$c->id}/tags", ['tag_ids' => [$t1->id, $t2->id]])->assertOk()
        ->assertJsonPath('data.id', $c->id);
    expect($c->tags()->pluck('tags.id')->sort()->values()->all())->toBe([$t1->id, $t2->id]);

    $this->postJson("/api/v1/conversations/{$c->id}/tags", ['tag_ids' => []])->assertOk();
    expect($c->tags()->count())->toBe(0);

    $this->postJson("/api/v1/conversations/{$c->id}/tags", [])->assertUnprocessable()->assertJsonValidationErrors(['tag_ids']);
    $this->postJson("/api/v1/conversations/{$c->id}/tags", ['tag_ids' => [999999]])->assertUnprocessable()->assertJsonValidationErrors(['tag_ids.0']);
});

it('refuses tag sync and context on a conversation outside her platforms', function () {
    $c = mxChat(Platform::WhatsApp);
    $t = Tag::factory()->create();
    Sanctum::actingAs(mxModerator(Platform::Facebook));

    $this->postJson("/api/v1/conversations/{$c->id}/tags", ['tag_ids' => [$t->id]])->assertForbidden();
    $this->getJson("/api/v1/conversations/{$c->id}/context")->assertForbidden();
    expect($c->tags()->count())->toBe(0);
});

it('serves the conversation context like the web', function () {
    $c = mxChat(Platform::Facebook);
    $mod = mxModerator(Platform::Facebook);
    Sanctum::actingAs($mod);

    $api = $this->getJson("/api/v1/conversations/{$c->id}/context")->assertOk()
        ->assertJsonPath('data.outcome', ['current' => null, 'source' => null, 'auto' => null])->json();
    expect($api)->toBe($this->actingAs($mod, 'web')->getJson("/inbox/conversations/{$c->id}/context")->json());
});

it('refuses ads roles, inactive users and missing tokens', function () {
    $c = mxChat(Platform::Facebook);

    $this->getJson('/api/v1/tags')->assertUnauthorized();
    $this->getJson('/api/v1/conversations/counts')->assertUnauthorized();

    Sanctum::actingAs(User::factory()->create(['role' => UserRole::Content]));
    $this->getJson('/api/v1/tags')->assertForbidden();
    $this->getJson('/api/v1/conversations/counts')->assertForbidden();
    $this->getJson("/api/v1/conversations/{$c->id}/context")->assertForbidden();
    app('auth')->forgetGuards();

    $inactive = mxModerator(Platform::Facebook);
    $inactive->forceFill(['is_active' => false])->save();
    $this->withToken($inactive->createToken('phone')->plainTextToken)->getJson('/api/v1/tags')->assertUnauthorized();
});
