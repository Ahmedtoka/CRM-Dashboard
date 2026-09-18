<?php

use App\Enums\UserRole;
use App\Models\ChannelAccount;
use App\Models\User;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;

beforeEach(function () {
    Sleep::fake();
    Http::preventStrayRequests();
    config(['crm.meta.app_id' => '111222', 'crm.meta.app_secret' => 'app-sec', 'crm.meta.graph_version' => 'v23.0', 'app.url' => 'https://crm.example.com']);
    $this->admin = User::factory()->create(['role' => UserRole::Admin]);
    $this->facebook = ChannelAccount::factory()->create([
        'platform' => 'facebook', 'driver' => 'live', 'name' => 'Le Voile Stores', 'external_id' => '459028320806456',
        'credentials' => ['access_token' => 'PAGE-TOKEN-LV'],
    ]);
    $this->igField = ['instagram_business_account' => ['id' => '17841400123456789', 'username' => 'levoile.eg', 'name' => 'Le Voile', 'profile_picture_url' => 'https://scontent.cdninstagram.com/lv.jpg'], 'id' => '459028320806456'];
});

it('discovers the instagram account linked to the connected page', function () {
    fakeMetaGraph(['GET 459028320806456' => $this->igField]);

    $this->actingAs($this->admin)->getJson('/settings/integrations/instagram/discover')->assertOk()
        ->assertJsonPath('instagram.id', '17841400123456789')
        ->assertJsonPath('instagram.username', 'levoile.eg')
        ->assertJsonPath('page_name', 'Le Voile Stores');

    Http::assertSent(fn (ClientRequest $r) => str_contains(urldecode($r->url()), 'fields=instagram_business_account{id,username,profile_picture_url,name}')
        && $r->hasHeader('Authorization', 'Bearer PAGE-TOKEN-LV'));
});

it('says when no instagram account is linked, and when facebook is not connected', function () {
    fakeMetaGraph(['GET 459028320806456' => ['id' => '459028320806456']]);

    $this->actingAs($this->admin)->getJson('/settings/integrations/instagram/discover')->assertOk()->assertJsonPath('instagram', null);
    $this->postJson('/settings/integrations/instagram/connect')->assertStatus(422)->assertJsonPath('error', 'instagram_not_linked');

    $this->facebook->update(['status' => 'disconnected']);
    $this->getJson('/settings/integrations/instagram/discover')->assertStatus(422)->assertJsonPath('error', 'facebook_not_connected');
});

it('connects instagram in one click: live account linked to the page, page subscribed', function () {
    fakeMetaGraph([
        'GET 459028320806456' => $this->igField,
        'POST 459028320806456/subscribed_apps' => ['success' => true],
        'GET debug_token' => ['data' => ['is_valid' => true, 'expires_at' => 0, 'scopes' => ['instagram_basic', 'instagram_manage_messages', 'instagram_manage_comments', 'pages_manage_metadata', 'pages_read_engagement']]],
        'GET 459028320806456/subscribed_apps' => ['data' => [['id' => '111222', 'subscribed_fields' => ['messages', 'feed']]]],
    ]);
    // A demo account for the platform must neither be reused nor keep the live one on the fake adapter.
    ChannelAccount::factory()->create(['platform' => 'instagram', 'driver' => 'fake', 'name' => 'Demo IG']);
    config(['crm.drivers.channels' => 'live']);

    $response = $this->actingAs($this->admin)->postJson('/settings/integrations/instagram/connect')->assertOk()
        ->assertJsonPath('subscribed', true)
        ->assertJsonPath('account.name', '@levoile.eg')
        ->assertJsonPath('account.profile.username', 'levoile.eg')
        ->assertJsonPath('account.health_status', 'ok');
    expect($response->getContent())->not->toContain('PAGE-TOKEN');

    $ig = ChannelAccount::where('platform', 'instagram')->where('driver', 'live')->sole();
    expect($ig->external_id)->toBe('17841400123456789')
        ->and($ig->credentials)->toBe(['linked_facebook_account_id' => $this->facebook->id])
        ->and($ig->graphToken())->toBe('PAGE-TOKEN-LV')
        ->and(app(App\Channels\ChannelRegistry::class)->adapter(App\Enums\Platform::Instagram))->toBeInstanceOf(App\Channels\Adapters\InstagramAdapter::class);

    Http::assertSent(fn (ClientRequest $r) => $r->method() === 'POST' && str_contains($r->url(), '459028320806456/subscribed_apps')
        && str_contains($r['subscribed_fields'], 'messages'));
});

it('flags a token without instagram messaging permission as a problem', function () {
    fakeMetaGraph([
        'GET 459028320806456' => $this->igField,
        'POST 459028320806456/subscribed_apps' => ['success' => true],
        'GET debug_token' => ['data' => ['is_valid' => true, 'expires_at' => 0, 'scopes' => ['pages_messaging', 'pages_manage_metadata']]],
        'GET 459028320806456/subscribed_apps' => ['data' => [['id' => '111222', 'subscribed_fields' => ['messages']]]],
    ]);

    $this->actingAs($this->admin)->postJson('/settings/integrations/instagram/connect')->assertOk()
        ->assertJsonPath('account.health_status', 'problem')
        ->assertJsonPath('account.health.checks.0.code', 'missing_scopes')
        ->assertJsonPath('account.health.checks.0.missing', ['instagram_basic', 'instagram_manage_messages']);
});

it('sends instagram messages through the linked page id', function () {
    Http::fake(['graph.facebook.com/*' => Http::response(['message_id' => 'm.1'])]);
    config(['crm.drivers.channels' => 'live']);
    $ig = ChannelAccount::factory()->create(['platform' => 'instagram', 'driver' => 'live', 'external_id' => '17841400123456789', 'credentials' => ['linked_facebook_account_id' => $this->facebook->id]]);
    $to = App\Models\CustomerIdentity::factory()->create(['platform' => 'instagram', 'external_id' => 'IGSID-1']);

    app(App\Channels\Adapters\InstagramAdapter::class)->sendText($ig, $to, 'أهلا');

    Http::assertSent(fn (ClientRequest $r) => str_contains($r->url(), '/v23.0/459028320806456/messages')
        && $r['recipient'] === ['id' => 'IGSID-1'] && $r->hasHeader('Authorization', 'Bearer PAGE-TOKEN-LV'));
});
