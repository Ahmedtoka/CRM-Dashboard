<?php

namespace App\Channels;

use App\Channels\Adapters\FakeChannelAdapter;
use App\Channels\Adapters\InstagramAdapter;
use App\Channels\Adapters\MessengerAdapter;
use App\Channels\Adapters\TestChannelAdapter;
use App\Channels\Adapters\TikTokAdapter;
use App\Channels\Adapters\WhatsAppAdapter;
use App\Channels\Contracts\ChannelAdapter;
use App\Enums\Platform;
use App\Models\ChannelAccount;
use App\TestLinks\TestScope;
use Illuminate\Support\Facades\Log;

class ChannelRegistry
{
    /**
     * The adapter that owns one account's traffic. Prefer this over `adapter()`
     * wherever the account is known: a team test link's account (`driver = test`,
     * design 2026-09-21 §3) must never reach Meta, however its platform reads.
     */
    public function adapterFor(?ChannelAccount $account): ChannelAdapter
    {
        if ($account === null) {
            return $this->adapter(Platform::Facebook);
        }

        if ($account->driver === TestScope::DRIVER) {
            return new TestChannelAdapter;
        }

        // A «تيست» channel of the production load test (2026-10-07): whatever the global driver,
        // its sends, typing and attachments are recorded by the fake adapter and never reach Meta.
        if ($account->is_load_test) {
            return new FakeChannelAdapter($account->platform);
        }

        // TikTok (E1) is routed per account: a demo/fake TikTok channel stays fake next to the live ad-comments channel,
        // and the live one never falls back to the fake adapter (TikTokAdapter refuses when it is disconnected).
        if ($account->platform === Platform::TikTok) {
            return $account->driver === 'live' && config('crm.drivers.channels') !== 'fake'
                ? app(TikTokAdapter::class)
                : new FakeChannelAdapter(Platform::TikTok);
        }

        return $this->adapter($account->platform);
    }

    public function adapter(Platform $platform): ChannelAdapter
    {
        if (config('crm.drivers.channels') === 'fake') {
            return new FakeChannelAdapter($platform);
        }

        // A live account that is in use wins over a simulator/demo account kept on file:
        // a demo seed creates a fake account per platform with a lower id, and a real
        // Instagram/WhatsApp account connected later from Settings → Integrations must
        // not have its Meta webhooks parsed (and its replies "sent") by the fake adapter.
        // One read of the platform's few accounts decides both (Task 4a: the thread detail ran two).
        // Test-link accounts and the load-test channels are not a real channel, so they never decide this.
        $accounts = ChannelAccount::query()->where('platform', $platform)
            ->where('driver', '!=', TestScope::DRIVER)
            ->where('is_load_test', false)
            ->orderBy('id')
            ->get(['id', 'driver', 'status']);

        $hasLive = $accounts->contains(fn (ChannelAccount $a) => $a->driver === 'live' && $a->status !== 'disconnected');

        if (! $hasLive && $accounts->first()?->driver === 'fake') {
            return new FakeChannelAdapter($platform);
        }

        return match ($platform) {
            Platform::Facebook => app(MessengerAdapter::class),
            Platform::Instagram => app(InstagramAdapter::class),
            Platform::WhatsApp => app(WhatsAppAdapter::class),
            // Phase 1 (E1): ad comments only, through the Marketing API; DMs and video comments wait for TikTok (E2).
            Platform::TikTok => app(TikTokAdapter::class),
        };
    }

    /**
     * The connected (not disconnected) account an inbound event names by its page /
     * Instagram / WhatsApp phone-number id; throws (and logs) when there is none, so
     * the event is dropped instead of landing on another account (audit A3).
     */
    public function connectedAccount(Platform $platform, string $externalId): ChannelAccount
    {
        $account = $externalId === '' ? null : ChannelAccount::where('platform', $platform)
            ->where('external_id', $externalId)
            ->where('status', '!=', 'disconnected')
            ->first();

        if ($account === null) {
            Log::warning('webhook.unknown_channel_account', ['platform' => $platform->value, 'channel_external_id' => $externalId]);

            throw new UnknownChannelAccount($platform, $externalId);
        }

        return $account;
    }

    public function account(Platform $platform): ChannelAccount
    {
        return ChannelAccount::where('platform', $platform)
            ->where('driver', '!=', TestScope::DRIVER)
            ->where('is_load_test', false)
            ->firstOrFail();
    }
}
