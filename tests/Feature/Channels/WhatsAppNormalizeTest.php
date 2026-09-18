<?php

use App\Channels\Adapters\WhatsAppAdapter;
use App\Channels\Data\{InboundMessageData, DeliveryReceiptData};
use App\Enums\MessageStatus;

it('normalizes whatsapp cloud api messages and statuses', function () {
    $events = app(WhatsAppAdapter::class)->normalize(['object'=>'whatsapp_business_account','entry'=>[['id'=>'WABA','changes'=>[['field'=>'messages','value'=>[
        'metadata'=>['phone_number_id'=>'PN1'],
        'contacts'=>[['profile'=>['name'=>'Mona'],'wa_id'=>'201001234567']],
        'messages'=>[['from'=>'201001234567','id'=>'wamid.1','timestamp'=>'1757671200','type'=>'text','text'=>['body'=>'عايزة اطلب']]],
        'statuses'=>[['id'=>'wamid.out','status'=>'read','timestamp'=>'1757671300','recipient_id'=>'201001234567']],
    ]]]]]]);
    expect($events[0])->toBeInstanceOf(InboundMessageData::class)
        ->and($events[0]->customerPhone)->toBe('201001234567')->and($events[0]->customerName)->toBe('Mona')
        ->and($events[1])->toBeInstanceOf(DeliveryReceiptData::class)->and($events[1]->status)->toBe(MessageStatus::Read);
});

it('normalizes a whatsapp sticker message into a sticker attachment', function () {
    $events = app(WhatsAppAdapter::class)->normalize(['object'=>'whatsapp_business_account','entry'=>[['id'=>'WABA','changes'=>[['field'=>'messages','value'=>[
        'metadata'=>['phone_number_id'=>'PN1'],
        'contacts'=>[['profile'=>['name'=>'Mona'],'wa_id'=>'201001234567']],
        'messages'=>[['from'=>'201001234567','id'=>'wamid.2','timestamp'=>'1757671200','type'=>'sticker','sticker'=>['id'=>'MEDIA123','mime_type'=>'image/webp']]],
    ]]]]]]);
    expect($events[0])->toBeInstanceOf(InboundMessageData::class)
        ->and($events[0]->body)->toBe('')
        ->and($events[0]->attachments)->toBe([['type' => 'sticker', 'id' => 'MEDIA123']]);
});

it('normalizes whatsapp image with caption and voice notes', function () {
    $payload = fn (array $message) => ['entry' => [['changes' => [['field' => 'messages', 'value' => [
        'metadata' => ['phone_number_id' => 'PN1'], 'contacts' => [['wa_id' => '201001234567', 'profile' => ['name' => 'Mona']]],
        'messages' => [array_merge(['from' => '201001234567', 'id' => 'wamid.1', 'timestamp' => '1757800000'], $message)],
    ]]]]]];
    $adapter = app(App\Channels\Adapters\WhatsAppAdapter::class);

    $image = $adapter->normalize($payload(['type' => 'image', 'image' => ['id' => 'IMG1', 'mime_type' => 'image/jpeg', 'caption' => 'ده متاح؟']]))[0];
    expect($image->body)->toBe('ده متاح؟')->and($image->attachments)->toBe([['type' => 'image', 'id' => 'IMG1', 'mime_type' => 'image/jpeg']]);

    $voice = $adapter->normalize($payload(['type' => 'audio', 'audio' => ['id' => 'AUD1', 'mime_type' => 'audio/ogg; codecs=opus', 'voice' => true]]))[0];
    expect($voice->attachments)->toBe([['type' => 'audio', 'id' => 'AUD1', 'mime_type' => 'audio/ogg; codecs=opus', 'voice' => true]]);

    $doc = $adapter->normalize($payload(['type' => 'document', 'document' => ['id' => 'DOC1', 'mime_type' => 'application/pdf', 'filename' => 'invoice.pdf']]))[0];
    expect($doc->attachments)->toBe([['type' => 'file', 'id' => 'DOC1', 'mime_type' => 'application/pdf', 'filename' => 'invoice.pdf']]);
});
