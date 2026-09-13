<?php

use App\Channels\Adapters\FakeChannelAdapter;
use App\Channels\Adapters\MetaGraphClient;
use App\Enums\{Handler, MessageStatus, Platform, UserRole};
use App\Events\UserNotified;
use App\Inbox\OutboundService;
use App\Models\{ChannelAccount, Conversation, Customer, CustomerIdentity, User};
use Illuminate\Support\Facades\{Event, Http, Queue};

beforeEach(function () {
    Event::fake();
    FakeChannelAdapter::reset();

    $this->account = ChannelAccount::factory()->create(['platform' => Platform::Facebook, 'status' => 'connected']);
    $cust = Customer::factory()->create();
    CustomerIdentity::factory()->for($cust)->create(['platform' => Platform::Facebook]);
    $this->conv = Conversation::factory()->for($cust)->for($this->account, 'channelAccount')->create([
        'platform' => Platform::Facebook, 'handler' => Handler::Human, 'last_customer_message_at' => now()->subMinute(),
    ]);
    $this->admin = User::factory()->create(['role' => UserRole::Admin]);
    $this->mod = User::factory()->create(['role' => UserRole::Moderator]);
    $this->mod->userPlatforms()->create(['platform' => Platform::Facebook]);
});

it('flags the channel and alerts admins when sending fails with an auth error', function () {
    FakeChannelAdapter::failNext('Error validating access token: Session has expired', authError: true);

    $message = app(OutboundService::class)->sendHuman($this->conv, $this->mod, 'أهلا');

    $account = $this->account->fresh();
    expect($message->fresh()->status)->toBe(MessageStatus::Failed)
        ->and($account->status)->toBe('error')
        ->and($account->last_error)->toContain('access token');

    Event::assertDispatched(UserNotified::class, fn (UserNotified $e) => $e->userId === $this->admin->id && $e->type === 'channel.error');
    Event::assertNotDispatched(UserNotified::class, fn (UserNotified $e) => $e->userId === $this->mod->id);

    // The admin alert strip picks it up through the shared prop.
    $this->actingAs($this->admin)->get('/reports/me')
        ->assertInertia(fn ($page) => $page->where('channelAlerts.0.id', $this->account->id));
});

it('recognises a Meta (#190) token error even without the structured flag', function () {
    FakeChannelAdapter::failNext('(#190) This access token has expired');

    app(OutboundService::class)->sendHuman($this->conv, $this->mod, 'أهلا');

    expect($this->account->fresh()->status)->toBe('error');
});

it('leaves the channel connected for ordinary send failures', function () {
    FakeChannelAdapter::failNext('(#10) Message sent outside of allowed window');

    app(OutboundService::class)->sendHuman($this->conv, $this->mod, 'أهلا');

    expect($this->account->fresh()->status)->toBe('connected');
    Event::assertNotDispatched(UserNotified::class);
});

it('maps Graph API code 190 and HTTP 401 responses to auth errors', function () {
    Http::fake([
        'graph.facebook.com/*/expired' => Http::response(['error' => ['message' => 'Session has expired', 'type' => 'OAuthException', 'code' => 190]], 400),
        'graph.facebook.com/*/unauthorized' => Http::response(['error' => ['message' => 'Unauthorized']], 401),
        'graph.facebook.com/*/window' => Http::response(['error' => ['message' => 'Outside window', 'code' => 10]], 400),
    ]);

    $client = app(MetaGraphClient::class);

    expect($client->post($this->account, 'expired', [])->authError)->toBeTrue()
        ->and($client->post($this->account, 'unauthorized', [])->authError)->toBeTrue()
        ->and($client->post($this->account, 'window', [])->authError)->toBeFalse();
});

it('records last_webhook_at when a webhook is accepted', function () {
    Queue::fake();
    $whatsapp = ChannelAccount::factory()->create(['platform' => Platform::WhatsApp, 'last_webhook_at' => null]);

    $this->postJson('/webhooks/whatsapp', ['fake' => true, 'events' => []])->assertOk();

    expect($whatsapp->fresh()->last_webhook_at)->not->toBeNull();
});
