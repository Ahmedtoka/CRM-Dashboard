<?php

use App\Enums\UserRole;
use App\Models\ChannelAccount;
use App\Models\User;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;

const WA_TOKEN = 'EAAB-whatsapp-system-user-token-42';

beforeEach(function () {
    Sleep::fake();
    Http::preventStrayRequests();
    config([
        'app.url' => 'https://crm.example.com',
        'crm.meta.app_id' => '111222',
        'crm.meta.app_secret' => 'app-sec',
        'crm.meta.verify_token' => 'verify-me',
        'crm.meta.graph_version' => 'v23.0',
    ]);
    $this->admin = User::factory()->create(['role' => UserRole::Admin]);
    $this->phone = ['id' => '1098765432', 'display_phone_number' => '+20 100 123 4567', 'verified_name' => 'Le Voile', 'quality_rating' => 'GREEN', 'code_verification_status' => 'VERIFIED'];
    $this->routes = [
        'GET 5550001/phone_numbers' => ['data' => [$this->phone, ['id' => '2000', 'display_phone_number' => '+20 111', 'verified_name' => 'Other']]],
        'GET 1098765432' => $this->phone,
        'POST 5550001/subscribed_apps' => ['success' => true],
        'GET debug_token' => ['data' => ['is_valid' => true, 'expires_at' => 0, 'type' => 'SYSTEM_USER', 'scopes' => ['whatsapp_business_messaging', 'whatsapp_business_management', 'business_management']]],
        'GET 5550001/subscribed_apps' => ['data' => [['whatsapp_business_api_data' => ['id' => '111222', 'name' => 'ARENA'], 'override_callback_uri' => 'https://crm.example.com/webhooks/whatsapp']]],
    ];
});

it('lists the phone numbers of a WABA', function () {
    fakeMetaGraph($this->routes);

    $response = $this->actingAs($this->admin)->postJson('/settings/integrations/whatsapp/phone-numbers', ['waba_id' => '5550001', 'access_token' => WA_TOKEN])
        ->assertOk()
        ->assertJsonPath('phone_numbers.0.id', '1098765432')
        ->assertJsonPath('phone_numbers.0.quality_rating', 'GREEN')
        ->assertJsonCount(2, 'phone_numbers');
    expect($response->getContent())->not->toContain(WA_TOKEN);

    Http::assertSent(fn (ClientRequest $r) => str_contains($r->url(), '/5550001/phone_numbers') && $r->hasHeader('Authorization', 'Bearer '.WA_TOKEN));
});

it('validates, saves and subscribes the app to the WABA with the callback override', function () {
    fakeMetaGraph($this->routes);

    $response = $this->actingAs($this->admin)->postJson('/settings/integrations/whatsapp/connect', [
        'waba_id' => '5550001', 'phone_number_id' => '1098765432', 'access_token' => WA_TOKEN, 'override_callback' => true,
    ])->assertOk()
        ->assertJsonPath('subscribed', true)
        ->assertJsonPath('account.profile.display_phone_number', '+20 100 123 4567')
        ->assertJsonPath('account.profile.verified_name', 'Le Voile')
        ->assertJsonPath('account.profile.quality_rating', 'GREEN')
        ->assertJsonPath('account.profile.override_callback', true)
        ->assertJsonPath('account.health_status', 'ok');
    expect($response->getContent())->not->toContain(WA_TOKEN);

    $account = ChannelAccount::where('platform', 'whatsapp')->where('driver', 'live')->sole();
    expect($account->external_id)->toBe('1098765432')
        ->and($account->credentials)->toBe(['access_token' => WA_TOKEN])
        ->and($account->wabaId())->toBe('5550001');

    Http::assertSent(fn (ClientRequest $r) => $r->method() === 'GET' && str_contains($r->url(), '/1098765432?')
        && str_contains(urldecode($r->url()), 'fields=id,display_phone_number,verified_name,quality_rating,code_verification_status'));
    Http::assertSent(fn (ClientRequest $r) => $r->method() === 'POST' && str_contains($r->url(), '/5550001/subscribed_apps')
        && $r['override_callback_uri'] === 'https://crm.example.com/webhooks/whatsapp'
        && $r['verify_token'] === 'verify-me'
        && $r->hasHeader('Authorization', 'Bearer '.WA_TOKEN));
});

it('subscribes without an override when unchecked or when APP_URL is not https', function (string $appUrl, bool $override) {
    config(['app.url' => $appUrl]);
    fakeMetaGraph($this->routes);

    $this->actingAs($this->admin)->postJson('/settings/integrations/whatsapp/connect', [
        'waba_id' => '5550001', 'phone_number_id' => '1098765432', 'access_token' => WA_TOKEN, 'override_callback' => $override,
    ])->assertOk()->assertJsonPath('account.profile.override_callback', false);

    Http::assertSent(fn (ClientRequest $r) => $r->method() === 'POST' && str_contains($r->url(), '/5550001/subscribed_apps') && $r->data() === []);
})->with([['https://crm.example.com', false], ['http://127.0.0.1:8000', true]]);

it('rejects a number that is not in the WABA and a token that cannot see it', function () {
    fakeMetaGraph(array_merge($this->routes, ['GET 5550001/phone_numbers' => ['data' => [['id' => '2000']]]]));

    $this->actingAs($this->admin)->postJson('/settings/integrations/whatsapp/connect', [
        'waba_id' => '5550001', 'phone_number_id' => '1098765432', 'access_token' => WA_TOKEN,
    ])->assertStatus(422)->assertJsonPath('error', 'phone_not_in_waba');

    fakeMetaGraph(['GET 5550001/phone_numbers' => [['error' => ['message' => 'Unsupported get request. Object with ID does not exist', 'code' => 100]], 400]]);
    $this->postJson('/settings/integrations/whatsapp/phone-numbers', ['waba_id' => '5550001', 'access_token' => WA_TOKEN])
        ->assertStatus(422)->assertJsonPath('error', 'waba_not_accessible')
        ->assertJsonPath('detail', 'Unsupported get request. Object with ID does not exist');

    expect(ChannelAccount::count())->toBe(0);
});

it('validates the ids and token format', function () {
    $this->actingAs($this->admin)->postJson('/settings/integrations/whatsapp/connect', ['waba_id' => 'abc', 'phone_number_id' => '1', 'access_token' => 'short'])
        ->assertStatus(422)->assertJsonValidationErrors(['waba_id', 'phone_number_id', 'access_token']);
});

it('saves the account but reports a failed WABA subscription as a problem with a one-click fix', function () {
    fakeMetaGraph(array_merge($this->routes, [
        'POST 5550001/subscribed_apps' => [['error' => ['message' => 'Callback verification failed', 'code' => 2200]], 400],
        'GET 5550001/subscribed_apps' => ['data' => []],
    ]));

    $this->actingAs($this->admin)->postJson('/settings/integrations/whatsapp/connect', [
        'waba_id' => '5550001', 'phone_number_id' => '1098765432', 'access_token' => WA_TOKEN,
    ])->assertOk()
        ->assertJsonPath('subscribed', false)
        ->assertJsonPath('subscribe_error', 'Callback verification failed')
        ->assertJsonPath('account.health_status', 'problem')
        ->assertJsonPath('account.health.checks.1.code', 'not_subscribed')
        ->assertJsonPath('account.health.checks.1.fix', 'resubscribe');

    $account = ChannelAccount::where('platform', 'whatsapp')->sole();
    expect($account->status)->toBe('error');

    // The fix button: subscribe again, check again.
    fakeMetaGraph($this->routes);
    $this->postJson("/settings/integrations/{$account->id}/fix", ['action' => 'resubscribe'])->assertOk()
        ->assertJsonPath('account.health_status', 'ok')
        ->assertJsonPath('account.status', 'connected');
});
