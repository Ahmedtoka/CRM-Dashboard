<?php

use App\Bot\Flows\ButtonMatcher;
use App\Models\ChannelAccount;
use App\Models\Conversation;
use App\Models\Message;

it('maps a payload, a number or an exact title to the last bot buttons', function () {
    $c = Conversation::factory()->for(ChannelAccount::factory(), 'channelAccount')->create();
    Message::factory()->for($c, 'conversation')->create(['direction' => 'out', 'sender_type' => 'bot', 'body' => 'اختاري', 'buttons' => [
        ['title' => 'المرتجع والاستبدال', 'payload' => 'flow:return_exchange'],
        ['title' => 'شكوى', 'payload' => 'flow:complaint'],
    ]]);
    $m = fn (array $attrs) => Message::factory()->for($c, 'conversation')->create(['direction' => 'in', 'sender_type' => 'customer'] + $attrs);

    expect(app(ButtonMatcher::class)->match($c, $m(['body' => 'x', 'payload' => 'menu:main_menu'])))->toBe('menu:main_menu')
        ->and(app(ButtonMatcher::class)->match($c, $m(['body' => '2'])))->toBe('flow:complaint')
        ->and(app(ButtonMatcher::class)->match($c, $m(['body' => '٢'])))->toBe('flow:complaint')
        ->and(app(ButtonMatcher::class)->match($c, $m(['body' => 'شكوي'])))->toBe('flow:complaint')
        ->and(app(ButtonMatcher::class)->match($c, $m(['body' => 'عايزة ارجع'])))->toBeNull()
        ->and(app(ButtonMatcher::class)->match($c, $m(['body' => '5'])))->toBeNull();
});
