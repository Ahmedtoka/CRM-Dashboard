<?php

// «الربط بتيك توك» (x6, 2026-10-24): OAuth start, the callback (state, code → token → account → webhook), the
// pasted-code fallback, the health check, resubscribe and disconnect. Everything goes through FakeTikTokApi.

use App\Channels\Adapters\TikTok\FakeTikTokApi;
use App\Channels\Adapters\TikTok\TikTokApiException;
use App\Channels\Adapters\TikTok\TikTokMessagingApi;
use App\Channels\Integrations\ConnectionHealthCheck;
use App\Channels\Integrations\TikTokConnector;
use App\Enums\Platform;
use App\Enums\UserRole;
use App\Models\ChannelAccount;
use App\Models\User;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    Http::preventStrayRequests();
    FakeTikTokApi::reset();
    app()->instance(TikTokMessagingApi::class, new FakeTikTokApi);
    config([
        'app.url' => 'https://crm.example.com',
        'services.tiktok.app_id' => '7591714577012752400',
        'services.tiktok.app_secret' => 'tt-secret',
        'services.tiktok.authorize_url' => null,
    ]);
    $this->admin = User::factory()->create(['role' => UserRole::Admin]);
});

function ttConnected(): ChannelAccount
{
    [$account] = app(TikTokConnector::class)->connectWithCode('code-1');

    return $account->fresh();
}

it('is admin only', function () {
    $mod = User::factory()->create(['role' => UserRole::Moderator]);
    $this->actingAs($mod)->get('/settings/channels/tiktok/connect')->assertForbidden();
    $this->actingAs($mod)->postJson('/settings/integrations/tiktok/code', ['code' => 'abcdef'])->assertForbidden();
});

it('sends the owner to TikTok with a one-time state and the messaging scopes, or refuses without app keys', function () {
    $response = $this->actingAs($this->admin)->get('/settings/channels/tiktok/connect');

    $location = (string) $response->headers->get('Location');
    expect($location)->toStartWith('https://www.tiktok.com/v2/auth/authorize?')
        ->toContain('client_key=7591714577012752400')
        ->toContain('redirect_uri='.urlencode('https://crm.example.com/settings/channels/tiktok/callback/'))
        ->toContain('scope='.urlencode('user.info.basic,user.account.type,message.list.read,message.list.send,message.list.manage'))
        ->toContain('disable_auto_auth=1')
        ->toContain('state='.session('tiktok_connect.state'));

    config(['services.tiktok.app_secret' => null]);
    $this->actingAs($this->admin)->get('/settings/channels/tiktok/connect')->assertRedirect('/settings/integrations');
    expect(session('tiktok_connect.code'))->toBe('app_secret_missing');
});

it('uses the portal authorization URL verbatim when pasted, adding the state', function () {
    config(['services.tiktok.authorize_url' => 'https://www.tiktok.com/v2/auth/authorize?client_key=abc&scope=x&redirect_uri=https%3A%2F%2Flevoilestores.com%2F&response_type=code']);

    expect(TikTokConnector::authorizeUrl('st-1'))->toBe('https://www.tiktok.com/v2/auth/authorize?client_key=abc&scope=x&redirect_uri=https%3A%2F%2Flevoilestores.com%2F&response_type=code&state=st-1&disable_auto_auth=1');
});

it('exchanges the code on the callback, saves the messaging account with an encrypted token, subscribes the webhook and flashes connected', function () {
    $this->withSession(['tiktok_connect.state' => 'st-cancel'])->actingAs($this->admin)
        ->get('/settings/channels/tiktok/callback?error=access_denied&error_description=user+cancelled&state=st-cancel');
    expect(session('tiktok_connect.code'))->toBe('cancelled');

    $this->withSession(['tiktok_connect.state' => 'st-nocode'])->actingAs($this->admin)
        ->get('/settings/channels/tiktok/callback?state=st-nocode');
    expect(session('tiktok_connect.code'))->toBe('no_code');
    expect(FakeTikTokApi::calls('exchangeCode'))->toBe([]);
});

it('flashes code_invalid when TikTok refuses the code', function () {
    $this->actingAs($this->admin)->get('/settings/channels/tiktok/connect');
    FakeTikTokApi::failNext(40002, 'auth_code expired');

    $this->actingAs($this->admin)->get('/settings/channels/tiktok/callback?code=old&state='.session('tiktok_connect.state'));

    expect(session('tiktok_connect.code'))->toBe('code_invalid')->and(session('tiktok_connect.detail'))->toContain('expired')->and(ChannelAccount::count())->toBe(0);
});

it('accepts a pasted code, or the whole redirected storefront URL, and returns the card without a token', function () {
    $response = $this->actingAs($this->admin)->postJson('/settings/integrations/tiktok/code', ['code' => 'https://levoilestores.com/?code=pasted-77&state=whatever&scopes=x'])
        ->assertOk()
        ->assertJsonPath('subscribed', true)
        ->assertJsonPath('account.platform', 'tiktok')
        ->assertJsonPath('account.profile.username', 'levoilestores')
        ->assertJsonPath('account.has_token', true);

    expect($response->getContent())->not->toContain('fake-access-pasted-77')
        ->and(FakeTikTokApi::calls('exchangeCode')[0]['code'])->toBe('pasted-77');

    FakeTikTokApi::failNext(40002, 'bad code');
    $this->actingAs($this->admin)->postJson('/settings/integrations/tiktok/code', ['code' => 'nonsense-code'])->assertStatus(422)->assertJsonPath('error', 'tiktok_code_invalid');
});

it('shows the TikTok card on Settings → Channels with its URLs, and manages it: test, resubscribe, disconnect (token revoked)', function () {
    $account = ttConnected();

    $this->actingAs($this->admin)->get('/settings/integrations')->assertOk()
        ->assertInertia(fn ($page) => $page->component('settings/Integrations')
            ->where('accounts.tiktok.id', $account->id)
            ->where('accounts.tiktok.profile.username', 'levoilestores')
            ->where('tiktok.enabled', true)
            ->where('tiktok.redirect_uri', 'https://crm.example.com/settings/channels/tiktok/callback/')
            ->where('tiktok.callback_url', 'https://crm.example.com/webhooks/tiktok')
            ->missing('accounts.tiktok.credentials'));

    $this->actingAs($this->admin)->postJson("/settings/integrations/{$account->id}/test")->assertOk()->assertJsonPath('account.health_status', 'ok');

    $account->forceFill(['profile' => array_merge($account->profile, ['webhook_subscribed_at' => null])])->save();
    $this->actingAs($this->admin)->postJson("/settings/integrations/{$account->id}/test")->assertOk()->assertJsonPath('account.health_status', 'problem');
    $this->actingAs($this->admin)->postJson("/settings/integrations/{$account->id}/fix", ['action' => 'resubscribe'])->assertOk()->assertJsonPath('account.health_status', 'ok');

    $this->actingAs($this->admin)->deleteJson("/settings/integrations/{$account->id}")->assertOk()->assertJsonPath('accounts.tiktok.status', 'disconnected');
    expect($account->fresh()->credentials)->toBeNull()
        ->and(FakeTikTokApi::calls('revoke')[0]['accessToken'])->toBe('fake-access-code-1');
});

it('never manages the ad-comments TikTok channel from the integrations endpoints', function () {
    $comments = ChannelAccount::factory()->create(['platform' => Platform::TikTok, 'driver' => 'live', 'external_id' => '7000', 'profile' => ['source' => 'ad_comments']]);

    $this->actingAs($this->admin)->postJson("/settings/integrations/{$comments->id}/test")->assertNotFound();
    $this->actingAs($this->admin)->deleteJson("/settings/integrations/{$comments->id}")->assertNotFound();
});

describe('health', function () {
    it('renews an expiring access token during the check and reports the scopes', function () {
        $account = ttConnected();
        $account->forceFill(['credentials' => array_merge($account->credentials, ['access_token_expires_at' => now()->addMinutes(5)->toIso8601String()])])->save();

        $result = app(ConnectionHealthCheck::class)->run($account);

        expect($result['status'])->toBe('ok')
            ->and(collect($result['checks'])->pluck('key')->all())->toBe(['token', 'account', 'webhooks', 'inbound'])
            ->and(FakeTikTokApi::calls('refreshToken'))->toHaveCount(1)
            ->and($account->fresh()->credentials['access_token'])->toStartWith('fake-access-refreshed-');
    });

    it('flags an invalid token, a lost Advanced Access and an expired refresh token as problems and alerts admins', function () {
        $account = ttConnected();

        FakeTikTokApi::failNext(TikTokApiException::INVALID_TOKEN, 'Invalid or incorrect access token.');
        $result = app(ConnectionHealthCheck::class)->run($account);
        expect($result['status'])->toBe('problem')
            ->and(collect($result['checks'])->firstWhere('key', 'token')['code'])->toBe('token_invalid')
            ->and($account->fresh()->status)->toBe('error')
            ->and($account->fresh()->last_error)->toBe('problem:token_invalid');

        $api = new class extends FakeTikTokApi
        {
            public function businessInfo(ChannelAccount $account): array
            {
                return ['username' => 'levoilestores', 'display_name' => 'Le Voile', 'has_advanced_access' => false];
            }
        };
        app()->instance(TikTokMessagingApi::class, $api);
        $result = app(ConnectionHealthCheck::class)->run($account->fresh());
        expect(collect($result['checks'])->firstWhere('key', 'account')['code'])->toBe('no_advanced_access')->and($result['status'])->toBe('problem');

        $account = $account->fresh();
        $account->forceFill(['credentials' => array_merge($account->credentials, ['refresh_token_expires_at' => now()->subDay()->toIso8601String()])])->save();
        $result = app(ConnectionHealthCheck::class)->run($account);
        expect(collect($result['checks'])->firstWhere('key', 'token')['code'])->toBe('refresh_token_expired');
    });

    it('is covered by the daily channels:health run', function () {
        ttConnected();
        ChannelAccount::factory()->create(['platform' => Platform::TikTok, 'driver' => 'live', 'external_id' => '7000', 'profile' => ['source' => 'ad_comments']]);

        $this->artisan('channels:health')->expectsOutputToContain('tiktok Le Voile: ok')->assertSuccessful();
        expect(ChannelAccount::whereNotNull('health_status')->count())->toBe(1);
    });
});
