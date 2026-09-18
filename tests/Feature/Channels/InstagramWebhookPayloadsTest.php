<?php

use App\Channels\Adapters\InstagramAdapter;
use App\Channels\Data\DeliveryReceiptData;
use App\Channels\Data\InboundCommentData;
use App\Channels\Data\InboundMessageData;
use App\Enums\MessageStatus;

/**
 * Instagram Messaging (Messenger Platform, `instagram` webhook object) payloads as
 * Meta delivers them: entry.id is the Instagram professional account id, messaging
 * entry.time is in milliseconds, comment (changes) entry.time in seconds.
 */
function igMessaging(array $item, int $time = 1757671620000): array
{
    return ['object' => 'instagram', 'entry' => [[
        'id' => '17841400123456789',
        'time' => $time,
        'messaging' => [array_merge(['sender' => ['id' => '9012345678901234'], 'recipient' => ['id' => '17841400123456789'], 'timestamp' => $time], $item)],
    ]]];
}

it('normalizes an instagram image message', function () {
    $events = app(InstagramAdapter::class)->normalize(igMessaging(['message' => [
        'mid' => 'aWdfZAG1faXRlbToxOklHTWVzc2FnZAUlEOjE3ODQx',
        'attachments' => [['type' => 'image', 'payload' => ['url' => 'https://lookaside.fbsbx.com/ig_messaging_cdn/?asset_id=1&signature=abc']]],
    ]]));

    expect($events)->toHaveCount(1)->and($events[0])->toBeInstanceOf(InboundMessageData::class)
        ->and($events[0]->channelExternalId)->toBe('17841400123456789')
        ->and($events[0]->customerExternalId)->toBe('9012345678901234')
        ->and($events[0]->attachments)->toBe([['type' => 'image', 'url' => 'https://lookaside.fbsbx.com/ig_messaging_cdn/?asset_id=1&signature=abc']])
        ->and($events[0]->occurredAt->getTimestamp())->toBe(1757671620);
});

it('reads an instagram quick reply payload and a postback with its own mid', function () {
    $adapter = app(InstagramAdapter::class);

    $quick = $adapter->normalize(igMessaging(['message' => ['mid' => 'mid.qr', 'text' => 'المقاسات', 'quick_reply' => ['payload' => 'flow:sizes']]]))[0];
    expect($quick->body)->toBe('المقاسات')->and($quick->payload)->toBe('flow:sizes')->and($quick->externalMessageId)->toBe('mid.qr');

    $postback = $adapter->normalize(igMessaging(['postback' => ['mid' => 'aWdfZAG1-postback-mid', 'title' => 'ابدأ', 'payload' => 'menu:main_menu']]))[0];
    expect($postback->payload)->toBe('menu:main_menu')->and($postback->body)->toBe('ابدأ')
        ->and($postback->externalMessageId)->toBe('aWdfZAG1-postback-mid');
});

it('turns an instagram messaging_seen event into a read receipt for that message', function () {
    $events = app(InstagramAdapter::class)->normalize(igMessaging(['read' => ['mid' => 'aWdfZAG1-our-outgoing-mid']], 1757671700000));

    expect($events)->toHaveCount(1)->and($events[0])->toBeInstanceOf(DeliveryReceiptData::class)
        ->and($events[0]->externalMessageId)->toBe('aWdfZAG1-our-outgoing-mid')
        ->and($events[0]->status)->toBe(MessageStatus::Read)
        ->and($events[0]->occurredAt->getTimestamp())->toBe(1757671700);
});

it('ignores unsent (deleted) messages, echoes and reactions', function () {
    $adapter = app(InstagramAdapter::class);

    expect($adapter->normalize(igMessaging(['message' => ['mid' => 'mid.del', 'is_deleted' => true]])))->toBe([])
        ->and($adapter->normalize(igMessaging(['message' => ['mid' => 'mid.echo', 'text' => 'hi', 'is_echo' => true]])))->toBe([])
        ->and($adapter->normalize(igMessaging(['reaction' => ['mid' => 'mid.1', 'action' => 'react', 'reaction' => 'love', 'emoji' => '❤']])))->toBe([]);
});

it('normalizes an instagram comment whose entry time is in seconds', function () {
    $events = app(InstagramAdapter::class)->normalize(['object' => 'instagram', 'entry' => [[
        'id' => '17841400123456789',
        'time' => 1757671740,
        'changes' => [[
            'field' => 'comments',
            'value' => [
                'from' => ['id' => '9012345678901234', 'username' => 'nour.style'],
                'media' => ['id' => '17899988776655443', 'media_product_type' => 'FEED'],
                'id' => '17958812345678901',
                'text' => 'بكام القطعة دي؟',
            ],
        ]],
    ]]]);

    expect($events)->toHaveCount(1)->and($events[0])->toBeInstanceOf(InboundCommentData::class)
        ->and($events[0]->postExternalId)->toBe('17899988776655443')
        ->and($events[0]->commentExternalId)->toBe('17958812345678901')
        ->and($events[0]->customerName)->toBe('nour.style')
        ->and($events[0]->occurredAt->toDateString())->toBe('2025-09-12');
});

it('normalizes a reply to a comment with its parent id', function () {
    $events = app(InstagramAdapter::class)->normalize(['object' => 'instagram', 'entry' => [[
        'id' => '17841400123456789', 'time' => 1757671800,
        'changes' => [['field' => 'comments', 'value' => [
            'from' => ['id' => '901', 'username' => 'mariam'], 'media' => ['id' => '178999'], 'id' => '17960000', 'parent_id' => '17958812345678901', 'text' => 'وأنا كمان',
        ]]],
    ]]]);

    expect($events[0]->parentExternalId)->toBe('17958812345678901');
});
