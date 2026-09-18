<?php

use App\Channels\Data\InboundMessageData;
use App\Enums\Platform;
use App\Inbox\InboxIngestor;
use App\Models\BotSetting;
use App\Models\ChannelAccount;
use App\Models\CustomerIdentity;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    Event::fake();
    BotSetting::current()->update(['enabled' => false]);
});

function liveAccount(Platform $platform, string $externalId): ChannelAccount
{
    return ChannelAccount::factory()->create([
        'platform' => $platform,
        'external_id' => $externalId,
        'driver' => 'live',
        'credentials' => ['access_token' => 'page-token'],
    ]);
}

it('replaces a messenger sender id with the real name and picture', function () {
    liveAccount(Platform::Facebook, 'PAGE1');
    Http::fake(['graph.facebook.com/*/PSID-1*' => Http::response(['first_name' => 'Ahmed', 'last_name' => 'Azzab', 'profile_pic' => 'https://cdn.test/a.jpg', 'id' => 'PSID-1'])]);

    app(InboxIngestor::class)->ingestMessage(new InboundMessageData(Platform::Facebook, 'PAGE1', 'PSID-1', '', 'm1', 'السلام عليكم', CarbonImmutable::now()));

    $identity = CustomerIdentity::with('customer')->where('external_id', 'PSID-1')->first();
    expect($identity->display_name)->toBe('Ahmed Azzab')
        ->and($identity->customer->name)->toBe('Ahmed Azzab')
        ->and($identity->customer->avatar_url)->toBe('https://cdn.test/a.jpg');
    Http::assertSent(fn ($r) => str_contains($r->url(), 'fields=first_name%2Clast_name%2Cprofile_pic'));
});

it('uses name and username for instagram senders', function () {
    liveAccount(Platform::Instagram, 'IG1');
    Http::fake(['graph.facebook.com/*/IGSID-1*' => Http::response(['name' => 'Nour Ali', 'username' => 'nour.ali', 'id' => 'IGSID-1'])]);

    app(InboxIngestor::class)->ingestMessage(new InboundMessageData(Platform::Instagram, 'IG1', 'IGSID-1', '', 'm1', 'hi', CarbonImmutable::now()));

    $identity = CustomerIdentity::with('customer')->where('external_id', 'IGSID-1')->first();
    expect($identity->display_name)->toBe('Nour Ali')->and($identity->username)->toBe('nour.ali')->and($identity->customer->name)->toBe('Nour Ali');
});

it('never overwrites a name that is already set and never calls graph for it', function () {
    liveAccount(Platform::Facebook, 'PAGE1');
    Http::fake();

    app(InboxIngestor::class)->ingestMessage(new InboundMessageData(Platform::Facebook, 'PAGE1', 'PSID-2', 'Mona', 'm1', 'hi', CarbonImmutable::now()));

    expect(CustomerIdentity::where('external_id', 'PSID-2')->value('display_name'))->toBe('Mona');
    Http::assertNothingSent();
});

it('keeps the id when the lookup fails or the account uses the fake driver', function () {
    ChannelAccount::factory()->create(['platform' => Platform::Facebook, 'external_id' => 'FAKE', 'driver' => 'fake']);
    liveAccount(Platform::Facebook, 'PAGE1');
    Http::fake(['graph.facebook.com/*' => Http::response(['error' => ['message' => 'nope']], 400)]);

    app(InboxIngestor::class)->ingestMessage(new InboundMessageData(Platform::Facebook, 'FAKE', 'PSID-3', '', 'm1', 'hi', CarbonImmutable::now()));
    app(InboxIngestor::class)->ingestMessage(new InboundMessageData(Platform::Facebook, 'PAGE1', 'PSID-4', '', 'm2', 'hi', CarbonImmutable::now()));

    expect(CustomerIdentity::where('external_id', 'PSID-3')->value('display_name'))->toBe('PSID-3')
        ->and(CustomerIdentity::where('external_id', 'PSID-4')->value('display_name'))->toBe('PSID-4');
    Http::assertSentCount(2); // PSID-4 only (MetaGraphClient retry(2) = two attempts), never the fake account
});
