<?php

use App\Channels\MetaPageSubscriber;
use App\Enums\UserRole;
use App\Models\ChannelAccount;
use App\Models\User;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;

const SYSTEM_TOKEN = 'EAAB-system-user-token-0123456789';

beforeEach(function () {
    Sleep::fake();
    Http::preventStrayRequests();
    config([
        'app.url' => 'https://crm.example.com',
        'crm.meta.app_id' => '111222',
        'crm.meta.app_secret' => 'app-sec',
        'crm.meta.graph_version' => 'v23.0',
    ]);
    $this->admin = User::factory()->create(['role' => UserRole::Admin]);
    $this->healthRoutes = [
        'GET debug_token' => ['data' => ['is_valid' => true, 'expires_at' => 0, 'scopes' => ['pages_messaging', 'pages_manage_metadata', 'pages_read_engagement', 'pages_manage_engagement', 'pages_read_user_content']]],
        'GET 459028320806456/subscribed_apps' => ['data' => [['id' => '111222', 'name' => 'ARENA', 'subscribed_fields' => ['messages', 'messaging_postbacks', 'feed']]]],
    ];
});

it('lists the pages of a system user token without returning any token, then connects the picked page', function () {
    fakeMetaGraph($this->healthRoutes + [
        'GET me/accounts' => ['data' => [
            ['id' => '459028320806456', 'name' => 'Le Voile Stores', 'category' => 'Clothing store', 'picture' => ['data' => ['url' => 'https://cdn.example/lv.jpg']], 'tasks' => ['MESSAGING', 'MODERATE', 'MANAGE'], 'access_token' => 'PAGE-TOKEN-LV'],
            ['id' => '777000', 'name' => 'Read-only Page', 'tasks' => ['ANALYZE'], 'access_token' => 'PAGE-TOKEN-RO'],
        ]],
        'POST 459028320806456/subscribed_apps' => ['success' => true],
    ]);

    $list = $this->actingAs($this->admin)->postJson('/settings/integrations/facebook/system-token', ['token' => SYSTEM_TOKEN])->assertOk();

    $list->assertJsonPath('pages.0.id', '459028320806456')
        ->assertJsonPath('pages.0.missing_tasks', [])
        ->assertJsonPath('pages.1.missing_tasks', ['MESSAGING', 'MODERATE']);
    expect($list->getContent())->not->toContain('PAGE-TOKEN')->not->toContain(SYSTEM_TOKEN);

    Http::assertSent(fn (ClientRequest $r) => str_contains($r->url(), '/me/accounts') && $r->hasHeader('Authorization', 'Bearer '.SYSTEM_TOKEN)
        && str_contains($r->url(), 'appsecret_proof='.hash_hmac('sha256', SYSTEM_TOKEN, 'app-sec')));

    $connect = $this->postJson('/settings/integrations/facebook/system-token/connect', ['page_id' => '459028320806456'])->assertOk();

    $connect->assertJsonPath('subscribed', true)
        ->assertJsonPath('account.name', 'Le Voile Stores')
        ->assertJsonPath('account.profile.method', 'system_user')
        ->assertJsonPath('account.health_status', 'ok');
    expect($connect->getContent())->not->toContain('PAGE-TOKEN')->not->toContain(SYSTEM_TOKEN);

    $account = ChannelAccount::where('platform', 'facebook')->where('driver', 'live')->sole();
    expect($account->external_id)->toBe('459028320806456')
        ->and($account->credentials['access_token'])->toBe('PAGE-TOKEN-LV')
        ->and($account->profile['picture'])->toBe('https://cdn.example/lv.jpg')
        ->and($account->connected_at)->not->toBeNull();

    Http::assertSent(fn (ClientRequest $r) => $r->method() === 'POST' && str_contains($r->url(), '459028320806456/subscribed_apps')
        && $r->hasHeader('Authorization', 'Bearer PAGE-TOKEN-LV')
        && $r['subscribed_fields'] === MetaPageSubscriber::FIELDS);

    // The stored system token is single-use.
    expect(session()->has('integrations.system_token'))->toBeFalse();
});

it('refuses a page the system user cannot fully manage', function () {
    fakeMetaGraph(['GET me/accounts' => ['data' => [['id' => '777000', 'name' => 'Read-only Page', 'tasks' => ['ANALYZE'], 'access_token' => 'PAGE-TOKEN-RO']]]]);

    $this->actingAs($this->admin)->postJson('/settings/integrations/facebook/system-token', ['token' => SYSTEM_TOKEN])->assertOk();
    $this->postJson('/settings/integrations/facebook/system-token/connect', ['page_id' => '777000'])
        ->assertStatus(422)->assertJsonPath('error', 'missing_tasks');

    expect(ChannelAccount::count())->toBe(0);
});

it('falls back to a typed page id when the token lists no pages, deriving the page token', function () {
    fakeMetaGraph($this->healthRoutes + [
        'GET me/accounts' => ['data' => []],
        'GET 459028320806456' => fn (ClientRequest $r) => str_contains($r->url(), 'access_token')
            ? ['id' => '459028320806456', 'name' => 'Le Voile Stores', 'access_token' => 'PAGE-TOKEN-DERIVED']
            : [['error' => ['message' => 'bad']], 400],
        'POST 459028320806456/subscribed_apps' => ['success' => true],
    ]);

    $this->actingAs($this->admin)->postJson('/settings/integrations/facebook/system-token', ['token' => SYSTEM_TOKEN])
        ->assertOk()->assertJsonPath('pages', []);

    $this->postJson('/settings/integrations/facebook/system-token/connect', ['page_id' => '459028320806456'])->assertOk();

    Http::assertSent(fn (ClientRequest $r) => $r->method() === 'GET' && str_contains($r->url(), '/459028320806456?')
        && str_contains(urldecode($r->url()), 'fields=id,name,category,picture{url},access_token')
        && $r->hasHeader('Authorization', 'Bearer '.SYSTEM_TOKEN));
    expect(ChannelAccount::where('driver', 'live')->sole()->credentials['access_token'])->toBe('PAGE-TOKEN-DERIVED');
});

it('reports an invalid token and an expired session clearly', function () {
    fakeMetaGraph(['GET me/accounts' => [['error' => ['message' => 'Invalid OAuth access token.', 'type' => 'OAuthException', 'code' => 190]], 400]]);

    $this->actingAs($this->admin)->postJson('/settings/integrations/facebook/system-token', ['token' => SYSTEM_TOKEN])
        ->assertStatus(422)
        ->assertJsonPath('error', 'token_invalid')
        ->assertJsonPath('detail', 'Invalid OAuth access token.');

    $this->postJson('/settings/integrations/facebook/system-token/connect', ['page_id' => '459028320806456'])
        ->assertStatus(422)->assertJsonPath('error', 'token_expired');
});

it('reuses the existing live messenger account on reconnect and leaves demo accounts alone', function () {
    $fake = ChannelAccount::factory()->create(['platform' => 'facebook', 'driver' => 'fake', 'name' => 'Demo']);
    $live = ChannelAccount::factory()->create(['platform' => 'facebook', 'driver' => 'live', 'status' => 'disconnected', 'name' => 'Old', 'external_id' => '1']);
    fakeMetaGraph($this->healthRoutes + [
        'GET me/accounts' => ['data' => [['id' => '459028320806456', 'name' => 'Le Voile Stores', 'access_token' => 'PAGE-TOKEN-LV']]],
        'POST 459028320806456/subscribed_apps' => ['success' => true],
    ]);

    $this->actingAs($this->admin)->postJson('/settings/integrations/facebook/system-token', ['token' => SYSTEM_TOKEN]);
    $this->postJson('/settings/integrations/facebook/system-token/connect', ['page_id' => '459028320806456'])->assertOk()->assertJsonPath('account.id', $live->id);

    expect($live->fresh()->status)->toBe('connected')->and($live->fresh()->external_id)->toBe('459028320806456')
        ->and($fake->fresh()->name)->toBe('Demo')->and(ChannelAccount::count())->toBe(2);
});
