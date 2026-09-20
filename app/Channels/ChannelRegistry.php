<?php

namespace App\Channels;

use App\Channels\Adapters\FakeChannelAdapter;
use App\Channels\Adapters\InstagramAdapter;
use App\Channels\Adapters\MessengerAdapter;
use App\Channels\Adapters\TestChannelAdapter;
use App\Channels\Adapters\WhatsAppAdapter;
use App\Channels\Contracts\ChannelAdapter;
use App\Enums\Platform;
use App\Models\ChannelAccount;
use App\TestLinks\TestScope;

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

        return $this->adapter($account->platform);
    }

    public function adapter(Platform $platform): ChannelAdapter
    {
        if ($platform === Platform::TikTok) {
            return new FakeChannelAdapter($platform);
        }

        if (config('crm.drivers.channels') === 'fake') {
            return new FakeChannelAdapter($platform);
        }

        // A live account that is in use wins over a simulator/demo account kept on file:
        // a demo seed creates a fake account per platform with a lower id, and a real
        // Instagram/WhatsApp account connected later from Settings → Integrations must
        // not have its Meta webhooks parsed (and its replies "sent") by the fake adapter.
        $hasLive = ChannelAccount::where('platform', $platform)
            ->where('driver', 'live')
            ->where('status', '!=', 'disconnected')
            ->exists();

        if (! $hasLive) {
            // Test-link accounts are not a channel at all, so they never decide this.
            $account = ChannelAccount::where('platform', $platform)
                ->where('driver', '!=', TestScope::DRIVER)
                ->first();

            if ($account && $account->driver === 'fake') {
                return new FakeChannelAdapter($platform);
            }
        }

        return match ($platform) {
            Platform::Facebook => app(MessengerAdapter::class),
            Platform::Instagram => app(InstagramAdapter::class),
            Platform::WhatsApp => app(WhatsAppAdapter::class),
            Platform::TikTok => new FakeChannelAdapter($platform),
        };
    }

    public function account(Platform $platform): ChannelAccount
    {
        return ChannelAccount::where('platform', $platform)
            ->where('driver', '!=', TestScope::DRIVER)
            ->firstOrFail();
    }
}
