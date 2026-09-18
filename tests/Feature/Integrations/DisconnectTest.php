<?php

use App\Enums\UserRole;
use App\Models\ChannelAccount;
use App\Models\Conversation;
use App\Models\User;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;

beforeEach(function () {
    Sleep::fake();
    Http::preventStrayRequests();
    config(['crm.meta.app_id' => '111222', 'crm.meta.app_secret' => 'app-sec', 'crm.meta.graph_version' => 'v23.0']);
    $this->admin = User::factory()->create(['role' => UserRole::Admin]);
    $this->fb = ChannelAccount::factory()->create(['platform' => 'facebook', 'driver' => 'live', 'external_id' => '4590', 'credentials' => ['access_token' => 'PAGE-TOKEN'], 'health_status' => 'ok', 'health' => ['status' => 'ok', 'checks' => []]]);
    $this->ig = ChannelAccount::factory()->create(['platform' => 'instagram', 'driver' => 'live', 'external_id' => '1784', 'credentials' => ['linked_facebook_account_id' => $this->fb->id]]);
});

it('disconnects facebook: unsubscribes the page, clears credentials, keeps conversations, takes instagram with it', function () {
    fakeMetaGraph(['DELETE 4590/subscribed_apps' => ['success' => true]]);
    $conversation = Conversation::factory()->create(['channel_account_id' => $this->fb->id]);

    $this->actingAs($this->admin)->deleteJson("/settings/integrations/{$this->fb->id}")->assertOk()
        ->assertJsonPath('disconnected', [$this->ig->id, $this->fb->id])
        ->assertJsonPath('accounts.facebook.status', 'disconnected')
        ->assertJsonPath('accounts.instagram.status', 'disconnected');

    Http::assertSent(fn (ClientRequest $r) => $r->method() === 'DELETE' && str_contains($r->url(), '/4590/subscribed_apps') && $r->hasHeader('Authorization', 'Bearer PAGE-TOKEN'));

    $fb = $this->fb->fresh();
    expect($fb->status)->toBe('disconnected')
        ->and($fb->credentials)->toBeNull()
        ->and($fb->health)->toBeNull()
        ->and($fb->external_id)->toBe('4590')
        ->and($this->ig->fresh()->status)->toBe('disconnected')
        ->and($this->ig->fresh()->credentials)->toBeNull()
        ->and(Conversation::find($conversation->id))->not->toBeNull();
});

it('still disconnects when meta refuses the unsubscribe', function () {
    fakeMetaGraph(['DELETE 4590/subscribed_apps' => [['error' => ['message' => 'Invalid OAuth access token.', 'code' => 190]], 400]]);

    $this->actingAs($this->admin)->deleteJson("/settings/integrations/{$this->fb->id}")->assertOk();

    expect($this->fb->fresh()->status)->toBe('disconnected');
});

it('disconnects instagram without unsubscribing the page messenger still uses', function () {
    fakeMetaGraph([]);

    $this->actingAs($this->admin)->deleteJson("/settings/integrations/{$this->ig->id}")->assertOk()->assertJsonPath('disconnected', [$this->ig->id]);

    Http::assertNothingSent();
    expect($this->ig->fresh()->status)->toBe('disconnected')->and($this->fb->fresh()->status)->toBe('connected');
});

it('disconnects whatsapp by unsubscribing the app from the WABA', function () {
    $wa = ChannelAccount::factory()->create(['platform' => 'whatsapp', 'driver' => 'live', 'external_id' => '1098', 'credentials' => ['access_token' => 'WA-TOKEN'], 'profile' => ['waba_id' => '5550001']]);
    fakeMetaGraph(['DELETE 5550001/subscribed_apps' => ['success' => true]]);

    $this->actingAs($this->admin)->deleteJson("/settings/integrations/{$wa->id}")->assertOk();

    Http::assertSent(fn (ClientRequest $r) => $r->method() === 'DELETE' && str_contains($r->url(), '/5550001/subscribed_apps') && $r->hasHeader('Authorization', 'Bearer WA-TOKEN'));
    expect($wa->fresh()->status)->toBe('disconnected')->and($wa->fresh()->credentials)->toBeNull();
});
