<?php

use App\Channels\Integrations\ConnectionHealthCheck;
use App\Enums\UserRole;
use App\Models\ChannelAccount;
use App\Models\Conversation;
use App\Models\User;
use App\Models\UserNotification;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;

beforeEach(function () {
    Sleep::fake();
    Http::preventStrayRequests();
    config(['crm.meta.app_id' => '111222', 'crm.meta.app_secret' => 'app-sec', 'crm.meta.graph_version' => 'v23.0', 'app.url' => 'https://crm.example.com']);
    $this->admin = User::factory()->create(['role' => UserRole::Admin]);
    $this->supervisor = User::factory()->create(['role' => UserRole::Supervisor]);
    $this->fb = ChannelAccount::factory()->create([
        'platform' => 'facebook', 'driver' => 'live', 'name' => 'Le Voile Stores', 'external_id' => '4590',
        'credentials' => ['access_token' => 'PAGE-TOKEN'], 'connected_at' => now()->subDays(10),
    ]);
    $this->healthy = [
        'GET debug_token' => ['data' => ['is_valid' => true, 'expires_at' => 0, 'scopes' => ['pages_messaging', 'pages_manage_metadata', 'pages_read_engagement', 'pages_manage_engagement', 'pages_read_user_content']]],
        'GET 4590/subscribed_apps' => ['data' => [['id' => '111222', 'subscribed_fields' => ['messages', 'messaging_postbacks', 'feed']]]],
    ];
});

function healthCheckCode(array $result, string $key): ?string
{
    return collect($result['checks'])->firstWhere('key', $key)['code'] ?? null;
}

it('reports a healthy page with recent messages as ok', function () {
    fakeMetaGraph($this->healthy);
    Conversation::factory()->create(['channel_account_id' => $this->fb->id, 'last_customer_message_at' => now()->subHours(2)]);

    $result = app(ConnectionHealthCheck::class)->run($this->fb);

    expect($result['status'])->toBe('ok')
        ->and(healthCheckCode($result, 'token'))->toBe('token_valid_forever')
        ->and(healthCheckCode($result, 'webhooks'))->toBe('subscribed')
        ->and(healthCheckCode($result, 'inbound'))->toBe('recent')
        ->and($this->fb->fresh()->health_status)->toBe('ok')
        ->and($this->fb->fresh()->health_checked_at)->not->toBeNull();

    // debug_token is authorised with the app token.
    Http::assertSent(fn ($r) => str_contains($r->url(), '/debug_token') && str_contains(urldecode($r->url()), 'access_token=111222|app-sec')
        && str_contains($r->url(), 'input_token=PAGE-TOKEN'));
});

it('warns when no customer message arrived for more than 48 hours', function () {
    fakeMetaGraph($this->healthy);
    Conversation::factory()->create(['channel_account_id' => $this->fb->id, 'last_customer_message_at' => now()->subHours(60)]);

    $result = app(ConnectionHealthCheck::class)->run($this->fb);

    expect($result['status'])->toBe('warning')->and(healthCheckCode($result, 'inbound'))->toBe('stale')
        ->and($this->fb->fresh()->status)->toBe('connected');
});

it('warns about an expiring token and missing comment permissions, never a problem', function () {
    fakeMetaGraph(array_merge($this->healthy, [
        'GET debug_token' => ['data' => ['is_valid' => true, 'expires_at' => now()->addDays(3)->getTimestamp(), 'scopes' => ['pages_messaging', 'pages_manage_metadata']]],
    ]));

    $result = app(ConnectionHealthCheck::class)->run($this->fb);
    expect($result['status'])->toBe('warning')->and(healthCheckCode($result, 'token'))->toBe('token_expiring');

    fakeMetaGraph(array_merge($this->healthy, [
        'GET debug_token' => ['data' => ['is_valid' => true, 'expires_at' => 0, 'scopes' => ['pages_messaging', 'pages_manage_metadata']]],
    ]));
    $result = app(ConnectionHealthCheck::class)->run($this->fb->fresh());
    expect(healthCheckCode($result, 'token'))->toBe('missing_recommended_scopes')
        ->and(collect($result['checks'])->firstWhere('key', 'token')['missing'])->toBe(['pages_read_engagement', 'pages_manage_engagement', 'pages_read_user_content']);
});

it('flags an invalid token and a missing subscription as problems, alerts and notifies admins once', function () {
    fakeMetaGraph([
        'GET debug_token' => ['data' => ['is_valid' => false, 'error' => ['message' => 'Error validating access token: The session has been invalidated.']]],
        'GET 4590/subscribed_apps' => ['data' => [['id' => '999', 'subscribed_fields' => ['messages']]]],
    ]);

    $result = app(ConnectionHealthCheck::class)->run($this->fb);

    expect($result['status'])->toBe('problem')
        ->and(healthCheckCode($result, 'token'))->toBe('token_invalid')
        ->and(collect($result['checks'])->firstWhere('key', 'token')['fix'])->toBe('reconnect')
        ->and(healthCheckCode($result, 'webhooks'))->toBe('not_subscribed')
        ->and(collect($result['checks'])->firstWhere('key', 'webhooks')['fix'])->toBe('resubscribe');

    $fb = $this->fb->fresh();
    // Stable codes are stored (the scheduler runs in the default locale); the sentence
    // is chosen when a page renders the row.
    expect($fb->status)->toBe('error')
        ->and($fb->last_error)->toBe('problem:token_invalid · problem:not_subscribed')
        ->and(ConnectionHealthCheck::problemText($fb->last_error))
        ->toBe(__('labels.channel_problem.token_invalid').' · '.__('labels.channel_problem.not_subscribed'))
        ->and(ConnectionHealthCheck::problemText('Some raw Graph API message'))->toBe('Some raw Graph API message');

    $notes = UserNotification::where('type', 'channel.problem')->get();
    expect($notes)->toHaveCount(1)
        ->and($notes[0]->user_id)->toBe($this->admin->id)
        ->and($notes[0]->data['channel_account_id'])->toBe($this->fb->id)
        ->and($notes[0]->data['codes'])->toBe(['token_invalid', 'not_subscribed']);

    // Still broken the next day: no second notification.
    app(ConnectionHealthCheck::class)->run($fb);
    expect(UserNotification::where('type', 'channel.problem')->count())->toBe(1);

    // Shown to admins in the alert strip.
    $this->actingAs($this->admin)->get('/settings/integrations')
        ->assertInertia(fn ($page) => $page->where('channelAlerts.0.id', $this->fb->id)
            ->where('channelAlerts.0.last_error', __('labels.channel_problem.token_invalid').' · '.__('labels.channel_problem.not_subscribed')));

    // Recovered: back to connected, and a later break notifies again.
    fakeMetaGraph($this->healthy);
    app(ConnectionHealthCheck::class)->run($fb->fresh());
    expect($fb->fresh()->status)->toBe('connected')->and($fb->fresh()->last_error)->toBeNull();

    fakeMetaGraph(array_merge($this->healthy, ['GET debug_token' => ['data' => ['is_valid' => false]]]));
    app(ConnectionHealthCheck::class)->run($fb->fresh());
    expect(UserNotification::where('type', 'channel.problem')->count())->toBe(2);
});

it('treats an unreachable Graph API as a warning, not a problem', function () {
    Http::fake(fn () => throw new ConnectionException('cURL error 28: timed out for https://graph.facebook.com/v23.0/debug_token?access_token=111222|app-sec'));

    $result = app(ConnectionHealthCheck::class)->run($this->fb);

    expect($result['status'])->toBe('warning')
        ->and(healthCheckCode($result, 'token'))->toBe('check_failed')
        ->and(json_encode($result))->not->toContain('app-sec')
        ->and(UserNotification::count())->toBe(0);
});

it('flags a page without the messages webhook field', function () {
    fakeMetaGraph(array_merge($this->healthy, ['GET 4590/subscribed_apps' => ['data' => [['id' => '111222', 'subscribed_fields' => ['feed']]]]]));

    $result = app(ConnectionHealthCheck::class)->run($this->fb);

    expect(healthCheckCode($result, 'webhooks'))->toBe('missing_fields')->and($result['status'])->toBe('problem');
});

it('checks instagram through its linked page and notices a changed link', function () {
    $ig = ChannelAccount::factory()->create(['platform' => 'instagram', 'driver' => 'live', 'external_id' => '1784100', 'credentials' => ['linked_facebook_account_id' => $this->fb->id], 'connected_at' => now()]);
    fakeMetaGraph([
        'GET debug_token' => ['data' => ['is_valid' => true, 'expires_at' => 0, 'scopes' => ['instagram_basic', 'instagram_manage_messages', 'instagram_manage_comments', 'pages_manage_metadata', 'pages_read_engagement']]],
        'GET 4590/subscribed_apps' => ['data' => [['id' => '111222', 'subscribed_fields' => ['messages']]]],
        'GET 4590' => ['instagram_business_account' => ['id' => '1784999', 'username' => 'someone.else']],
    ]);

    $result = app(ConnectionHealthCheck::class)->run($ig);

    expect(healthCheckCode($result, 'link'))->toBe('instagram_changed')
        ->and(collect($result['checks'])->firstWhere('key', 'link')['detail'])->toBe('@someone.else')
        ->and($result['status'])->toBe('problem');
});

it('checks the WABA subscription and refreshes the number quality', function () {
    $wa = ChannelAccount::factory()->create(['platform' => 'whatsapp', 'driver' => 'live', 'external_id' => '1098', 'credentials' => ['access_token' => 'WA-TOKEN'], 'profile' => ['waba_id' => '5550001', 'quality_rating' => 'GREEN'], 'connected_at' => now()]);
    fakeMetaGraph([
        'GET debug_token' => ['data' => ['is_valid' => true, 'expires_at' => 0, 'scopes' => ['whatsapp_business_messaging', 'whatsapp_business_management']]],
        'GET 5550001/subscribed_apps' => ['data' => [['whatsapp_business_api_data' => ['id' => '111222', 'name' => 'ARENA']]]],
        'GET 1098' => ['id' => '1098', 'display_phone_number' => '+20 100', 'verified_name' => 'Le Voile', 'quality_rating' => 'RED'],
    ]);

    $result = app(ConnectionHealthCheck::class)->run($wa);

    expect(healthCheckCode($result, 'webhooks'))->toBe('subscribed')
        ->and(healthCheckCode($result, 'phone'))->toBe('quality_low')
        ->and($result['status'])->toBe('warning')
        ->and($wa->fresh()->profile['quality_rating'])->toBe('RED');
});

it('runs from the Test button and returns the refreshed card', function () {
    fakeMetaGraph($this->healthy);
    Conversation::factory()->create(['channel_account_id' => $this->fb->id, 'last_customer_message_at' => now()->subHour()]);

    $this->actingAs($this->admin)->postJson("/settings/integrations/{$this->fb->id}/test")->assertOk()
        ->assertJsonPath('account.health_status', 'ok')
        ->assertJsonPath('account.health.checks.0.key', 'token')
        ->assertJsonMissingPath('account.credentials');
});

it('runs daily over live connected accounts only', function () {
    fakeMetaGraph($this->healthy);
    Conversation::factory()->create(['channel_account_id' => $this->fb->id, 'last_customer_message_at' => now()->subHour()]);
    ChannelAccount::factory()->create(['platform' => 'facebook', 'driver' => 'fake']);
    ChannelAccount::factory()->create(['platform' => 'whatsapp', 'driver' => 'live', 'status' => 'disconnected']);

    $this->artisan('channels:health')->expectsOutputToContain('Le Voile Stores: ok')->assertSuccessful();

    expect(ChannelAccount::whereNotNull('health_status')->pluck('id')->all())->toBe([$this->fb->id]);

    $event = collect(app(Schedule::class)->events())->first(fn ($e) => str_contains((string) $e->command, 'channels:health'));
    expect($event)->not->toBeNull()->and($event->expression)->toBe('0 8 * * *')->and($event->timezone)->toBe('Africa/Cairo');
});
