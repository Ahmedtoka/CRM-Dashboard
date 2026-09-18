<?php

namespace App\Channels;

use App\Channels\Adapters\FakeChannelAdapter;
use App\Channels\Adapters\InstagramAdapter;
use App\Channels\Adapters\MessengerAdapter;
use App\Channels\Adapters\WhatsAppAdapter;
use App\Channels\Contracts\ChannelAdapter;
use App\Enums\Platform;
use App\Models\ChannelAccount;

class ChannelRegistry
{
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
            $account = ChannelAccount::where('platform', $platform)->first();

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
        return ChannelAccount::where('platform', $platform)->firstOrFail();
    }
}
