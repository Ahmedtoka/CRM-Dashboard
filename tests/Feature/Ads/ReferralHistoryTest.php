<?php

use App\Analytics\ActivityLogger;
use App\Channels\Adapters\MessengerAdapter;
use App\Channels\Data\AdReferralData;
use App\Enums\ActorType;
use App\Enums\Platform;
use App\Inbox\AdAttribution;
use App\Inbox\InboxIngestor;
use App\Inbox\Jobs\EnrichAdAttribution;
use App\Models\ActivityLog;
use App\Models\AdAccount;
use App\Models\BotSetting;
use App\Models\ChannelAccount;
use App\Models\Conversation;
use App\Models\ConversationAdReferral;
use App\Models\Customer;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;

/*
 * A4 (a-d, R-08): every ad referral of a conversation is kept in conversation_ad_referrals (append-only), so the
 * order attribution can credit the latest ad before the order instead of the conversation's first ad.
 */
beforeEach(function () {
    Event::fake();
    Http::preventStrayRequests();
    Queue::fake([EnrichAdAttribution::class]);
    BotSetting::current()->update(['enabled' => false]);
});

function rhEvent(string $mid, string $adId, int $ms): object
{
    return app(MessengerAdapter::class)->normalize(['object' => 'page', 'entry' => [['id' => 'PAGE1', 'time' => $ms, 'messaging' => [
        ['sender' => ['id' => 'U1'], 'recipient' => ['id' => 'PAGE1'], 'timestamp' => $ms, 'message' => ['mid' => $mid, 'text' => 'بكام؟', 'referral' => ['source' => 'ADS', 'type' => 'OPEN_THREAD', 'ad_id' => $adId]]],
    ]]]])[0];
}

it('writes one referral row per ad referral and a duplicate webhook does not duplicate it', function () {
    ChannelAccount::factory()->create(['platform' => Platform::Facebook, 'external_id' => 'PAGE1', 'driver' => 'live', 'credentials' => ['access_token' => 'tok']]);

    app(InboxIngestor::class)->ingestMessage(rhEvent('mid.1', '120001', 1757671200000));
    app(InboxIngestor::class)->ingestMessage(rhEvent('mid.1', '120001', 1757671200000)); // the same webhook again

    $c = Conversation::sole();
    expect(ConversationAdReferral::count())->toBe(1)
        ->and(ConversationAdReferral::sole())->toMatchArray(['conversation_id' => $c->id, 'customer_id' => $c->customer_id, 'ad_external_id' => '120001']);

    // A later ad on the already-attributed conversation keeps the first ad on the conversation but is kept in the history.
    app(InboxIngestor::class)->ingestMessage(rhEvent('mid.2', '120002', 1757671300000));
    expect($c->fresh()->ad_id)->toBe('120001')
        ->and(ConversationAdReferral::orderBy('id')->pluck('ad_external_id')->all())->toBe(['120001', '120002']);
});

it('does not duplicate a referral applied twice in the same second', function () {
    CarbonImmutable::setTestNow('2026-09-26 10:00:00');
    $c = Conversation::factory()->create();
    $ref = new AdReferralData(source: 'ADS', adId: '777');

    app(AdAttribution::class)->apply($c, $ref, true);
    app(AdAttribution::class)->apply($c->fresh(), $ref, true);

    expect(ConversationAdReferral::count())->toBe(1);
    CarbonImmutable::setTestNow();
});

it('never lets a referral row failure break the inbox', function () {
    $c = Conversation::factory()->create();
    Schema::drop('conversation_ad_referrals'); // the insert fails
    app(AdAttribution::class)->apply($c, new AdReferralData(source: 'ADS', adId: '888'), true);

    expect($c->fresh()->ad_id)->toBe('888');
});

it('keeps referral rows append-only', function () {
    $c = Conversation::factory()->create();
    $r = ConversationAdReferral::create(['conversation_id' => $c->id, 'customer_id' => $c->customer_id, 'ad_external_id' => '1', 'referred_at' => now()]);

    expect(fn () => $r->update(['ad_external_id' => '2']))->toThrow(LogicException::class);
});

it('rebuilds referral rows from the activity log idempotently', function () {
    $customer = Customer::factory()->create();
    $c = Conversation::factory()->create(['customer_id' => $customer->id]);
    $log = fn (string $at, array $meta) => tap(app(ActivityLogger::class)->log(ActorType::System, null, ActivityLogger::CONVERSATION_REFERRAL, $c, $c, $meta))
        ->forceFill(['created_at' => $at])->save();
    $log('2026-09-26 10:00:00', ['source' => 'ADS', 'ad_id' => '111']);
    $log('2026-09-27 11:00:00', ['source' => 'ADS', 'ad_id' => '222']);
    $log('2026-09-27 12:00:00', ['source' => 'SHORTLINK', 'ref' => 'bio', 'ad_id' => null]); // not an ad
    $log('2026-09-20 09:00:00', ['source' => 'ADS', 'ad_id' => '000']);                      // before --since

    $this->artisan('ads:backfill-referrals')->assertSuccessful();
    $this->artisan('ads:backfill-referrals')->assertSuccessful();

    expect(ConversationAdReferral::count())->toBe(2)
        ->and(ConversationAdReferral::orderBy('referred_at')->get(['ad_external_id', 'customer_id', 'referred_at'])->map(fn ($r) => [$r->ad_external_id, $r->customer_id, $r->referred_at->format('Y-m-d H:i:s')])->all())
        ->toBe([['111', $customer->id, '2026-09-26 10:00:00'], ['222', $customer->id, '2026-09-27 11:00:00']]);

    $this->artisan('ads:backfill-referrals', ['--since' => '2026-09-01'])->assertSuccessful();
    expect(ConversationAdReferral::count())->toBe(3)->and(ActivityLog::count())->toBe(4);
});

it('starts chat_complete_from at the later of the chat floor and the history start', function () {
    expect(config('crm.ads.chat_complete_from_floor'))->toBe('2026-09-25')
        ->and(config('crm.ads.inbox_window_days'))->toBe(7);

    $acc = AdAccount::factory()->create();
    $migration = require database_path('migrations/2026_10_06_100100_add_chat_complete_from_to_ad_accounts.php');
    $migration->up(); // idempotent: fills only rows that have none

    expect($acc->fresh()->chat_complete_from?->toDateString())->toBe('2026-09-25');
});
