<?php

use App\Enums\{Platform, SenderType};
use App\Inbox\WindowPolicy;
use App\Models\{ChannelAccount, Conversation};

function convOn(Platform $p, int $hoursAgo): Conversation {
    $acc = ChannelAccount::factory()->create(['platform' => $p]);
    return Conversation::factory()->for($acc, 'channelAccount')->create(['platform' => $p, 'last_customer_message_at' => now()->subHours($hoursAgo)]);
}

it('applies platform windows', function (Platform $p, int $hours, SenderType $s, string $mode) {
    expect(app(WindowPolicy::class)->evaluate(convOn($p, $hours), $s)->mode)->toBe($mode);
})->with([
    [Platform::WhatsApp, 2, SenderType::User, 'open'],
    [Platform::WhatsApp, 30, SenderType::User, 'template_only'],
    [Platform::Facebook, 30, SenderType::User, 'human_agent'],
    [Platform::Facebook, 30, SenderType::Bot, 'closed'],
    [Platform::Instagram, 200, SenderType::User, 'closed'],
]);
