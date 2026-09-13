<?php

use App\Channels\Adapters\MessengerAdapter;
use App\Channels\Data\{InboundMessageData, InboundCommentData, DeliveryReceiptData};

it('normalizes messenger message, delivery and feed comment', function () {
    $a = app(MessengerAdapter::class);
    $events = $a->normalize(['object'=>'page','entry'=>[[
        'id'=>'PAGE1','time'=>1757671200000,
        'messaging'=>[
            ['sender'=>['id'=>'PSID1'],'recipient'=>['id'=>'PAGE1'],'timestamp'=>1757671200000,'message'=>['mid'=>'mid.1','text'=>'متاح؟']],
            ['sender'=>['id'=>'PSID1'],'recipient'=>['id'=>'PAGE1'],'delivery'=>['mids'=>['mid.out'],'watermark'=>1757671200000]],
        ],
        'changes'=>[['field'=>'feed','value'=>['item'=>'comment','verb'=>'add','comment_id'=>'C1','post_id'=>'PAGE1_P1','message'=>'بكام','from'=>['id'=>'U1','name'=>'Sara'],'created_time'=>1757671200]]],
    ]]]);
    expect($events[0])->toBeInstanceOf(InboundMessageData::class)->and($events[0]->body)->toBe('متاح؟')
        ->and($events[1])->toBeInstanceOf(DeliveryReceiptData::class)->and($events[1]->externalMessageId)->toBe('mid.out')
        ->and($events[2])->toBeInstanceOf(InboundCommentData::class)->and($events[2]->commentExternalId)->toBe('C1');
});
