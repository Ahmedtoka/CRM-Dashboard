<?php

use App\Enums\UserRole;
use App\Models\{ChannelAccount, User};
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    config([
        'app.url' => 'https://crm.example.com',
        'crm.meta.app_id' => '1122334455',
        'crm.meta.app_secret' => 'app-sec',
        'crm.meta.graph_version' => 'v23.0',
        'crm.meta.login_config_id' => null,
    ]);
    $this->admin = User::factory()->create(['role' => UserRole::Admin]);
});

/** Graph fakes for the full callback: code exchange, long-lived exchange, two pages of /me/accounts. */
function fakeFacebookGraph(): void
{
    Http::fake(function (ClientRequest $request) {
        $url = $request->url();

        if (str_contains($url, '/oauth/access_token')) {
            return str_contains($url, 'fb_exchange_token')
                ? Http::response(['access_token' => 'LONG-user-token', 'token_type' => 'bearer'])
                : Http::response(['access_token' => 'SHORT-user-token', 'token_type' => 'bearer']);
        }

        if (str_contains($url, '/me/accounts')) {
            if (str_contains($url, 'after=CURSOR2')) {
                return Http::response(['data' => [
                    ['id' => '222', 'name' => 'Second Page', 'category' => 'Shop', 'tasks' => ['ANALYZE'], 'access_token' => 'PAGE-TOKEN-222'],
                ], 'paging' => ['cursors' => ['after' => 'END']]]);
            }

            return Http::response(['data' => [
                ['id' => '111', 'name' => 'Le Voile', 'category' => 'Clothing store', 'picture' => ['data' => ['url' => 'https://cdn.example/111.jpg']],
                    'tasks' => ['ADVERTISE', 'ANALYZE', 'CREATE_CONTENT', 'MESSAGING', 'MODERATE', 'MANAGE'], 'access_token' => 'PAGE-TOKEN-111'],
            ], 'paging' => ['cursors' => ['after' => 'CURSOR2'], 'next' => 'https://graph.facebook.com/v23.0/me/accounts?after=CURSOR2']]);
        }

        if (str_contains($url, '/subscribed_apps')) {
            return Http::response(['success' => true]);
        }

        return Http::response(['error' => ['message' => 'unexpected '.$url]], 500);
    });
}

function startLogin($test): string
{
    $location = $test->actingAs($test->admin)->get('/settings/channels/facebook/connect')->assertRedirect()->headers->get('Location');
    parse_str((string) parse_url($location, PHP_URL_QUERY), $query);

    return $query['state'];
}

it('redirects to the facebook dialog with the scope list', function () {
    $location = $this->actingAs($this->admin)->get('/settings/channels/facebook/connect')->assertRedirect()->headers->get('Location');

    expect($location)->toStartWith('https://www.facebook.com/v23.0/dialog/oauth?');
    parse_str((string) parse_url($location, PHP_URL_QUERY), $q);

    expect($q['client_id'])->toBe('1122334455')
        ->and($q['redirect_uri'])->toBe('https://crm.example.com/settings/channels/facebook/callback')
        ->and($q['response_type'])->toBe('code')
        ->and($q['scope'])->toBe('pages_show_list,pages_messaging,pages_manage_metadata,pages_read_engagement,pages_read_user_content,pages_manage_engagement')
        ->and($q)->not->toHaveKey('config_id')
        ->and($q['state'])->toBe(session('facebook_login.state'));
});

it('uses the login configuration id instead of scopes when set', function () {
    config(['crm.meta.login_config_id' => '998877']);

    $location = $this->actingAs($this->admin)->get('/settings/channels/facebook/connect')->headers->get('Location');
    parse_str((string) parse_url($location, PHP_URL_QUERY), $q);

    expect($q['config_id'])->toBe('998877')->and($q)->not->toHaveKey('scope');
});

it('refuses to start without an app id', function () {
    config(['crm.meta.app_id' => null]);

    $this->actingAs($this->admin)->get('/settings/channels/facebook/connect')
        ->assertRedirect('/settings/channels')
        ->assertSessionHas('facebook_connect.code', 'app_id_missing');
});

it('rejects a missing or mismatched state', function () {
    Http::fake();
    startLogin($this);

    $this->get('/settings/channels/facebook/callback?code=abc&state=wrong')
        ->assertRedirect('/settings/channels')
        ->assertSessionHas('facebook_connect.code', 'state_mismatch');

    // The state is single-use: even the right one is refused after a mismatch consumed it.
    $this->get('/settings/channels/facebook/callback?code=abc')
        ->assertSessionHas('facebook_connect.code', 'state_mismatch');

    Http::assertNothingSent();
});

it('handles the person cancelling the dialog', function () {
    Http::fake();
    $state = startLogin($this);

    $this->get("/settings/channels/facebook/callback?error=access_denied&error_reason=user_denied&state={$state}")
        ->assertRedirect('/settings/channels')
        ->assertSessionHas('facebook_connect.code', 'cancelled');

    Http::assertNothingSent();
});

it('exchanges the code, follows /me/accounts paging and never exposes tokens', function () {
    fakeFacebookGraph();
    $state = startLogin($this);

    $this->get("/settings/channels/facebook/callback?code=the-code&state={$state}")
        ->assertRedirect('/settings/channels/facebook/pages');

    Http::assertSent(fn ($r) => str_contains($r->url(), '/oauth/access_token') && str_contains($r->url(), 'code=the-code')
        && str_contains($r->url(), 'client_secret=app-sec')
        && str_contains($r->url(), urlencode('https://crm.example.com/settings/channels/facebook/callback')));
    Http::assertSent(fn ($r) => str_contains($r->url(), 'grant_type=fb_exchange_token') && str_contains($r->url(), 'fb_exchange_token=SHORT-user-token'));
    Http::assertSent(fn ($r) => str_contains($r->url(), '/me/accounts') && $r->hasHeader('Authorization', 'Bearer LONG-user-token')
        && str_contains($r->url(), 'appsecret_proof='.hash_hmac('sha256', 'LONG-user-token', 'app-sec')));
    Http::assertSent(fn ($r) => str_contains($r->url(), '/me/accounts') && str_contains($r->url(), 'after=CURSOR2'));

    // Stored encrypted: the raw session value does not contain a token.
    expect(session('facebook_login.pages'))->toBeString()->not->toContain('PAGE-TOKEN');

    $response = $this->get('/settings/channels/facebook/pages')->assertOk();
    $response->assertInertia(fn ($page) => $page->component('settings/FacebookPages')
        ->has('pages', 2)
        ->where('pages.0.name', 'Le Voile')
        ->where('pages.0.picture', 'https://cdn.example/111.jpg')
        ->where('pages.0.missing_tasks', [])
        ->where('pages.1.missing_tasks', ['MESSAGING', 'MODERATE'])
        ->missing('pages.0.access_token'));

    expect($response->getContent())->not->toContain('PAGE-TOKEN')->not->toContain('user-token');
});

it('saves the picked page on a new live messenger account and subscribes it', function () {
    fakeFacebookGraph();
    $state = startLogin($this);
    $this->get("/settings/channels/facebook/callback?code=c&state={$state}");

    $this->post('/settings/channels/facebook/pages/111')
        ->assertRedirect('/settings/channels')
        ->assertSessionHas('facebook_connect', ['code' => 'connected', 'name' => 'Le Voile']);

    $account = ChannelAccount::where('platform', 'facebook')->where('driver', 'live')->sole();
    expect($account->external_id)->toBe('111')
        ->and($account->name)->toBe('Le Voile')
        ->and($account->status)->toBe('connected')
        ->and($account->credentials['access_token'])->toBe('PAGE-TOKEN-111');

    Http::assertSent(fn ($r) => str_contains($r->url(), '/111/subscribed_apps') && $r->hasHeader('Authorization', 'Bearer PAGE-TOKEN-111')
        && $r['subscribed_fields'] === 'messages,messaging_postbacks,message_deliveries,message_reads,feed');

    // The list is single-use.
    expect(session()->has('facebook_login.pages'))->toBeFalse();
    $this->post('/settings/channels/facebook/pages/111')->assertNotFound();

    $channels = $this->get('/settings/channels')->assertOk();
    $channels->assertInertia(fn ($page) => $page->where('facebookLogin.redirect_uri', 'https://crm.example.com/settings/channels/facebook/callback'));
    expect($channels->getContent())->not->toContain('PAGE-TOKEN');
});

it('updates the existing live messenger account instead of creating another', function () {
    $fake = ChannelAccount::factory()->create(['platform' => 'facebook', 'driver' => 'fake', 'name' => 'Sim']);
    $live = ChannelAccount::factory()->create(['platform' => 'facebook', 'driver' => 'live', 'external_id' => '999', 'name' => 'Old', 'status' => 'error', 'credentials' => ['access_token' => 'old-tok']]);
    fakeFacebookGraph();
    $state = startLogin($this);
    $this->get("/settings/channels/facebook/callback?code=c&state={$state}");

    $this->post('/settings/channels/facebook/pages/111')->assertSessionHas('facebook_connect.code', 'connected');

    expect(ChannelAccount::where('platform', 'facebook')->count())->toBe(2);
    $live->refresh();
    expect($live->external_id)->toBe('111')->and($live->name)->toBe('Le Voile')->and($live->status)->toBe('connected')
        ->and($live->credentials['access_token'])->toBe('PAGE-TOKEN-111')
        ->and($fake->fresh()->name)->toBe('Sim');
});

it('keeps the page but reports a failed webhook subscription', function () {
    Http::fake([
        '*/oauth/access_token*' => Http::response(['access_token' => 'user-tok']),
        '*/me/accounts*' => Http::response(['data' => [['id' => '111', 'name' => 'Le Voile', 'access_token' => 'PAGE-TOKEN-111']]]),
        '*/subscribed_apps*' => Http::response(['error' => ['message' => '(#200) Permissions error']], 403),
    ]);
    $state = startLogin($this);
    $this->get("/settings/channels/facebook/callback?code=c&state={$state}");

    $this->post('/settings/channels/facebook/pages/111')
        ->assertSessionHas('facebook_connect', ['code' => 'subscribe_failed', 'name' => 'Le Voile', 'detail' => '(#200) Permissions error']);

    expect(ChannelAccount::where('external_id', '111')->exists())->toBeTrue();
});

it('refuses a page that is not in the session list or lacks the needed tasks', function () {
    fakeFacebookGraph();
    $this->actingAs($this->admin)->post('/settings/channels/facebook/pages/111')->assertNotFound();

    $state = startLogin($this);
    $this->get("/settings/channels/facebook/callback?code=c&state={$state}");

    $this->post('/settings/channels/facebook/pages/555')->assertNotFound();
    $this->post('/settings/channels/facebook/pages/222')->assertSessionHas('facebook_connect.code', 'missing_tasks');

    expect(ChannelAccount::count())->toBe(0);
});

it('sends the admin back when the pages list has expired', function () {
    fakeFacebookGraph();
    $state = startLogin($this);
    $this->get("/settings/channels/facebook/callback?code=c&state={$state}");

    $this->travel(16)->minutes();

    $this->get('/settings/channels/facebook/pages')->assertRedirect('/settings/channels')->assertSessionHas('facebook_connect.code', 'expired');
});

it('forbids non-admins', function () {
    $mod = User::factory()->create(['role' => UserRole::Supervisor]);

    $this->actingAs($mod)->get('/settings/channels/facebook/connect')->assertForbidden();
    $this->actingAs($mod)->get('/settings/channels/facebook/callback?code=x&state=y')->assertForbidden();
    $this->actingAs($mod)->get('/settings/channels/facebook/pages')->assertForbidden();
    $this->actingAs($mod)->post('/settings/channels/facebook/pages/111')->assertForbidden();
});
