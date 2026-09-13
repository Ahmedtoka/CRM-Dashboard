<?php

use App\Enums\UserRole;
use App\Models\{ChannelAccount, User};
use Illuminate\Support\Facades\Http;

it('tests and subscribes a live page without exposing the token', function () {
    config(['crm.meta.app_secret' => 'sec']);
    $admin = User::factory()->create(['role' => UserRole::Admin]);
    $acc = ChannelAccount::factory()->create(['platform' => 'facebook', 'external_id' => '1234', 'driver' => 'live', 'credentials' => ['access_token' => 'EAAG-secret']]);
    Http::fake([
        'graph.facebook.com/*/1234?*' => Http::response(['name' => 'My Store Page', 'id' => '1234']),
        'graph.facebook.com/*/1234/subscribed_apps*' => Http::response(['success' => true]),
    ]);
    $this->actingAs($admin)->postJson("/settings/channels/{$acc->id}/test")->assertOk()->assertJsonPath('page_name', 'My Store Page');
    $this->actingAs($admin)->postJson("/settings/channels/{$acc->id}/subscribe")->assertOk()->assertJsonPath('ok', true);
    expect($this->actingAs($admin)->get('/settings/channels')->getContent())->not->toContain('EAAG-secret');
});

it('updates a live account keeping the stored token when the field is left blank', function () {
    $admin = User::factory()->create(['role' => UserRole::Admin]);
    $acc = ChannelAccount::factory()->create([
        'platform' => 'facebook',
        'external_id' => '1234',
        'driver' => 'fake',
        'credentials' => ['access_token' => 'EAAG-secret'],
    ]);

    $this->actingAs($admin)->putJson("/settings/channels/{$acc->id}", [
        'driver' => 'live',
        'external_id' => '1234',
        'credentials' => ['access_token' => ''],
    ])->assertOk()->assertJsonPath('data.has_token', true);

    expect($acc->fresh()->credentials)->toBe(['access_token' => 'EAAG-secret']);
});

it('returns the graph error message without a token when the test call fails', function () {
    $admin = User::factory()->create(['role' => UserRole::Admin]);
    $acc = ChannelAccount::factory()->create(['platform' => 'facebook', 'external_id' => '1234', 'driver' => 'live', 'credentials' => ['access_token' => 'EAAG-secret']]);
    Http::fake(['graph.facebook.com/*' => Http::response(['error' => ['message' => 'Invalid OAuth access token.']], 401)]);

    $response = $this->actingAs($admin)->postJson("/settings/channels/{$acc->id}/test");

    $response->assertOk()->assertJsonPath('ok', false)->assertJsonPath('error', 'Invalid OAuth access token.');
    expect($response->getContent())->not->toContain('EAAG-secret');
});

it('forbids a non-admin from testing or subscribing a channel', function () {
    $mod = \App\Models\User::factory()->create(['role' => UserRole::Moderator]);
    $acc = ChannelAccount::factory()->create(['platform' => 'facebook', 'external_id' => '1234', 'driver' => 'live']);

    $this->actingAs($mod)->postJson("/settings/channels/{$acc->id}/test")->assertForbidden();
    $this->actingAs($mod)->postJson("/settings/channels/{$acc->id}/subscribe")->assertForbidden();
});

it('tests an instagram account through its linked facebook page token', function () {
    config(['crm.meta.app_secret' => 'sec']);
    $admin = User::factory()->create(['role' => UserRole::Admin]);
    $page = ChannelAccount::factory()->create(['platform' => 'facebook', 'external_id' => '1234', 'driver' => 'live', 'credentials' => ['access_token' => 'page-tok']]);
    $ig = ChannelAccount::factory()->create([
        'platform' => 'instagram',
        'external_id' => '17841400123456789',
        'driver' => 'live',
        'credentials' => ['linked_facebook_account_id' => $page->id],
    ]);
    Http::fake(['graph.facebook.com/*/17841400123456789?*' => Http::response(['username' => 'nour.style', 'name' => 'Nour Style'])]);

    $this->actingAs($admin)->postJson("/settings/channels/{$ig->id}/test")
        ->assertOk()->assertJsonPath('ok', true)->assertJsonPath('ig_username', 'nour.style');

    Http::assertSent(fn ($r) => str_contains($r->url(), 'appsecret_proof='.hash_hmac('sha256', 'page-tok', 'sec')));
});

it('fails an instagram test with 422 when no facebook page is linked', function () {
    $admin = User::factory()->create(['role' => UserRole::Admin]);
    $ig = ChannelAccount::factory()->create(['platform' => 'instagram', 'external_id' => '17841400123456789', 'driver' => 'live', 'credentials' => []]);

    $this->actingAs($admin)->postJson("/settings/channels/{$ig->id}/test")
        ->assertStatus(422)
        ->assertJsonPath('ok', false)
        ->assertJsonPath('error', fn ($message) => is_string($message) && $message !== '');
});

it('subscribes the linked facebook page from the instagram card', function () {
    $admin = User::factory()->create(['role' => UserRole::Admin]);
    $page = ChannelAccount::factory()->create(['platform' => 'facebook', 'external_id' => '1234', 'driver' => 'live', 'credentials' => ['access_token' => 'page-tok']]);
    $ig = ChannelAccount::factory()->create([
        'platform' => 'instagram',
        'external_id' => '17841400123456789',
        'driver' => 'live',
        'credentials' => ['linked_facebook_account_id' => $page->id],
    ]);
    Http::fake(['graph.facebook.com/*/1234/subscribed_apps*' => Http::response(['success' => true])]);

    $this->actingAs($admin)->postJson("/settings/channels/{$ig->id}/subscribe")
        ->assertOk()->assertJsonPath('ok', true);

    Http::assertSent(fn ($r) => str_contains($r->url(), '/1234/subscribed_apps'));
});

it('never exposes or accepts a token on an instagram account', function () {
    $admin = User::factory()->create(['role' => UserRole::Admin]);
    $page = ChannelAccount::factory()->create(['platform' => 'facebook', 'external_id' => '1234', 'driver' => 'live', 'credentials' => ['access_token' => 'page-tok']]);
    $ig = ChannelAccount::factory()->create([
        'platform' => 'instagram',
        'external_id' => '17841400123456789',
        'driver' => 'live',
        'credentials' => ['linked_facebook_account_id' => $page->id],
    ]);

    $this->actingAs($admin)->putJson("/settings/channels/{$ig->id}", [
        'credentials' => ['access_token' => 'sneaky-token', 'linked_facebook_account_id' => $page->id],
    ])->assertStatus(422);

    expect($ig->fresh()->credentials)->not->toHaveKey('access_token');

    $body = $this->actingAs($admin)->get('/settings/channels')->getContent();
    expect($body)->not->toContain('page-tok')->and($body)->not->toContain('sneaky-token');
});
