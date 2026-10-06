<?php

use App\Channels\Adapters\FakeChannelAdapter;
use App\Channels\Adapters\MessengerAdapter;
use App\Channels\ChannelRegistry;
use App\Channels\Jobs\ProcessWebhookEvent;
use App\Enums\Platform;
use App\Models\ChannelAccount;
use App\Models\Conversation;
use App\Models\WebhookEvent;
use App\Simulator\LoadTest\LoadTestChannels;
use App\Simulator\Simulator;
use Illuminate\Support\Facades\Http;

/** The real page / account / number, connected live (created first, so it has the lower id). */
function ltRealAccounts(): array
{
    return collect([Platform::Facebook, Platform::Instagram, Platform::WhatsApp])->mapWithKeys(fn (Platform $p) => [$p->value => ChannelAccount::factory()->create([
        'platform' => $p, 'external_id' => 'REAL-'.$p->value, 'driver' => 'live', 'status' => 'connected',
        'credentials' => ['page_access_token' => 'real-token', 'access_token' => 'real-token', 'phone_number_id' => '111'],
    ])])->all();
}

it('never returns a real account from ensureAccount, even when the real one is the only one on file', function (Platform $p) {
    config(['crm.drivers.channels' => 'live']);
    $real = ltRealAccounts();

    $account = app(Simulator::class)->ensureAccount($p);

    expect($account->id)->not->toBe($real[$p->value]->id)
        ->and($account->external_id)->toBe('loadtest-'.$p->value)
        ->and($account->is_load_test)->toBeTrue()
        ->and($account->driver)->toBe('fake')
        ->and($account->name)->toStartWith('تيست — ');

    // Idempotent: the same row every time, never a second one.
    expect(app(Simulator::class)->ensureAccount($p)->id)->toBe($account->id)
        ->and(ChannelAccount::where('platform', $p->value)->count())->toBe(2);
})->with([Platform::Facebook, Platform::Instagram, Platform::WhatsApp]);

it('names the three test channels in Arabic', function () {
    $names = LoadTestChannels::ensureAll()->mapWithKeys(fn (ChannelAccount $a) => [$a->platform->value => $a->name])->all();

    expect($names)->toBe([
        'facebook' => 'تيست — ماسنجر',
        'instagram' => 'تيست — إنستجرام',
        'whatsapp' => 'تيست — واتساب',
    ]);
});

it('lands simulated messages on the test account under the live driver, never on the real page', function () {
    config(['crm.drivers.channels' => 'live']);
    Http::preventStrayRequests();
    $real = ltRealAccounts();

    $m = app(Simulator::class)->customerMessage(Platform::Facebook, 'lt-c1', 'منى', 'السلام عليكم');

    expect($m->conversation->channel_account_id)->not->toBe($real['facebook']->id)
        ->and($m->conversation->channelAccount->is_load_test)->toBeTrue()
        ->and(Conversation::where('channel_account_id', $real['facebook']->id)->exists())->toBeFalse()
        ->and(WebhookEvent::latest('id')->first()->status)->toBe('processed');
});

it('gives a load-test account the fake adapter whatever the global driver, and never lets it decide a platform', function () {
    config(['crm.drivers.channels' => 'live']);
    $test = LoadTestChannels::account(Platform::Facebook); // created FIRST: the lowest id on the platform
    $real = ChannelAccount::factory()->create(['platform' => Platform::Facebook, 'external_id' => 'REAL-fb', 'driver' => 'live']);
    $registry = app(ChannelRegistry::class);

    expect($registry->adapterFor($test))->toBeInstanceOf(FakeChannelAdapter::class)
        ->and($registry->adapterFor($real))->toBeInstanceOf(MessengerAdapter::class)
        ->and($registry->adapter(Platform::Facebook))->toBeInstanceOf(MessengerAdapter::class)
        ->and($registry->account(Platform::Facebook)->id)->toBe($real->id);
});

it('does not treat a load-test payload as fake unless every event names a load-test account', function () {
    config(['crm.drivers.channels' => 'live']);
    Http::preventStrayRequests();
    $real = ltRealAccounts();
    LoadTestChannels::account(Platform::Facebook);

    $event = WebhookEvent::create([
        'provider' => 'facebook', 'event_type' => 'message', 'dedupe_key' => 'x1', 'signature_valid' => true, 'status' => 'received',
        'payload' => ['fake' => true, 'loadtest' => true, 'events' => [[
            'type' => 'message', 'id' => 'evil-1', 'channel_id' => 'REAL-facebook', 'customer_id' => 'c9', 'name' => 'X', 'text' => 'hi', 'at' => now()->toIso8601String(),
        ]]],
    ]);

    ProcessWebhookEvent::dispatchSync($event->id);

    expect(Conversation::count())->toBe(0)
        ->and(Conversation::where('channel_account_id', $real['facebook']->id)->exists())->toBeFalse();
});
