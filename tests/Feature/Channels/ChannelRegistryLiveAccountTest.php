<?php

use App\Channels\Adapters\FakeChannelAdapter;
use App\Channels\Adapters\WhatsAppAdapter;
use App\Channels\ChannelRegistry;
use App\Enums\Platform;
use App\Models\ChannelAccount;

beforeEach(fn () => config(['crm.drivers.channels' => 'live']));

it('uses the live adapter once a live account is connected next to a demo account', function () {
    ChannelAccount::factory()->create(['platform' => 'whatsapp', 'driver' => 'fake']);
    expect(app(ChannelRegistry::class)->adapter(Platform::WhatsApp))->toBeInstanceOf(FakeChannelAdapter::class);

    $live = ChannelAccount::factory()->create(['platform' => 'whatsapp', 'driver' => 'live', 'status' => 'connected']);
    expect(app(ChannelRegistry::class)->adapter(Platform::WhatsApp))->toBeInstanceOf(WhatsAppAdapter::class);

    // Disconnected again: back to the demo account's simulator.
    $live->update(['status' => 'disconnected']);
    expect(app(ChannelRegistry::class)->adapter(Platform::WhatsApp))->toBeInstanceOf(FakeChannelAdapter::class);
});
