<?php

use App\Channels\Adapters\WhatsAppAdapter;
use App\Channels\Data\DeliveryReceiptData;
use App\Channels\Data\InboundMessageData;
use App\Enums\MessageStatus;
use App\Enums\Platform;
use App\Models\ChannelAccount;
use App\Models\CustomerIdentity;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Support\Facades\Http;

/** A WhatsApp Cloud API webhook exactly as Meta sends it (`whatsapp_business_account`). */
function waWebhook(array $value): array
{
    return ['object' => 'whatsapp_business_account', 'entry' => [[
        'id' => '5550001',
        'changes' => [[
            'field' => 'messages',
            'value' => array_merge([
                'messaging_product' => 'whatsapp',
                'metadata' => ['display_phone_number' => '201001234567', 'phone_number_id' => '1098765432'],
            ], $value),
        ]],
    ]]];
}

function waMessage(array $message): array
{
    return waWebhook([
        'contacts' => [['profile' => ['name' => 'Mona Adel'], 'wa_id' => '201112223334']],
        'messages' => [array_merge(['from' => '201112223334', 'id' => 'wamid.HBgMMjAxMTEyMjIzMzM0FQIAEhgUM0EwRjk', 'timestamp' => '1757800000'], $message)],
    ]);
}

it('normalizes a text message with the contact name and phone', function () {
    $e = app(WhatsAppAdapter::class)->normalize(waMessage(['type' => 'text', 'text' => ['body' => 'عايزة أطلب الفستان']]))[0];

    expect($e)->toBeInstanceOf(InboundMessageData::class)
        ->and($e->channelExternalId)->toBe('1098765432')
        ->and($e->customerExternalId)->toBe('201112223334')
        ->and($e->customerPhone)->toBe('201112223334')
        ->and($e->customerName)->toBe('Mona Adel')
        ->and($e->body)->toBe('عايزة أطلب الفستان')
        ->and($e->externalMessageId)->toBe('wamid.HBgMMjAxMTEyMjIzMzM0FQIAEhgUM0EwRjk')
        ->and($e->occurredAt->getTimestamp())->toBe(1757800000);
});

it('normalizes an image with caption as a media id to download', function () {
    $e = app(WhatsAppAdapter::class)->normalize(waMessage(['type' => 'image', 'image' => [
        'caption' => 'ده متاح مقاس L؟', 'mime_type' => 'image/jpeg', 'sha256' => 'abc=', 'id' => '1234567890123456',
    ]]))[0];

    expect($e->body)->toBe('ده متاح مقاس L؟')
        ->and($e->attachments)->toBe([['type' => 'image', 'id' => '1234567890123456', 'mime_type' => 'image/jpeg']]);
});

it('reads interactive button / list replies and template quick-reply buttons as payloads', function () {
    $adapter = app(WhatsAppAdapter::class);

    $button = $adapter->normalize(waMessage([
        'context' => ['from' => '201001234567', 'id' => 'wamid.our-buttons'],
        'type' => 'interactive',
        'interactive' => ['type' => 'button_reply', 'button_reply' => ['id' => 'flow:return_exchange', 'title' => 'المرتجع والاستبدال']],
    ]))[0];
    expect($button->body)->toBe('المرتجع والاستبدال')->and($button->payload)->toBe('flow:return_exchange');

    $list = $adapter->normalize(waMessage([
        'type' => 'interactive',
        'interactive' => ['type' => 'list_reply', 'list_reply' => ['id' => 'flow:sizes', 'title' => 'جدول المقاسات', 'description' => '']],
    ]))[0];
    expect($list->body)->toBe('جدول المقاسات')->and($list->payload)->toBe('flow:sizes');

    $template = $adapter->normalize(waMessage(['type' => 'button', 'button' => ['payload' => 'order:confirm', 'text' => 'تأكيد الطلب']]))[0];
    expect($template->body)->toBe('تأكيد الطلب')->and($template->payload)->toBe('order:confirm');
});

it('turns a location into readable text and ignores reactions', function () {
    $adapter = app(WhatsAppAdapter::class);

    $location = $adapter->normalize(waMessage(['type' => 'location', 'location' => ['latitude' => 30.0444, 'longitude' => 31.2357, 'name' => 'Le Voile', 'address' => 'Nasr City']]))[0];
    expect($location->body)->toContain('Le Voile Nasr City')->toContain('https://maps.google.com/?q=30.0444,31.2357');

    expect($adapter->normalize(waMessage(['type' => 'reaction', 'reaction' => ['message_id' => 'wamid.x', 'emoji' => '👍']])))->toBe([]);
});

it('normalizes sent / delivered / read / failed statuses, keeping the failure reason', function () {
    $status = fn (string $s, array $extra = []) => array_merge([
        'id' => 'wamid.outgoing-1', 'status' => $s, 'timestamp' => '1757800100', 'recipient_id' => '201112223334',
        'conversation' => ['id' => 'c1', 'origin' => ['type' => 'service']],
        'pricing' => ['billable' => true, 'pricing_model' => 'PMP', 'category' => 'service'],
    ], $extra);

    $events = app(WhatsAppAdapter::class)->normalize(waWebhook(['statuses' => [
        $status('sent'), $status('delivered'), $status('read'),
        $status('failed', ['errors' => [['code' => 131047, 'title' => 'Re-engagement message', 'message' => 'Re-engagement message', 'error_data' => ['details' => 'Message failed to send because more than 24 hours have passed since the customer last replied to this number.']]]]),
    ]]));

    expect($events)->toHaveCount(4)->each->toBeInstanceOf(DeliveryReceiptData::class)
        ->and(array_map(fn ($e) => $e->status, $events))->toBe([MessageStatus::Sent, MessageStatus::Delivered, MessageStatus::Read, MessageStatus::Failed])
        ->and($events[0]->error)->toBeNull()
        ->and($events[3]->error)->toBe('(131047) Message failed to send because more than 24 hours have passed since the customer last replied to this number.');
});

it('stores the failure reason on the outgoing message', function () {
    $account = ChannelAccount::factory()->create(['platform' => 'whatsapp', 'driver' => 'live', 'external_id' => '1098765432']);
    $conversation = App\Models\Conversation::factory()->create(['channel_account_id' => $account->id]);
    $message = $conversation->messages()->create([
        'platform' => 'whatsapp', 'direction' => 'out', 'sender_type' => 'user', 'body' => 'hi',
        'external_id' => 'wamid.outgoing-1', 'status' => MessageStatus::Sent,
    ]);

    app(App\Inbox\InboxIngestor::class)->ingestReceipt(new DeliveryReceiptData(Platform::WhatsApp, 'wamid.outgoing-1', MessageStatus::Failed, now()->toImmutable(), '(131047) 24 hours passed'));

    expect($message->fresh()->status)->toBe(MessageStatus::Failed)->and($message->fresh()->error)->toBe('(131047) 24 hours passed');
});

describe('sending', function () {
    beforeEach(function () {
        Http::fake(['graph.facebook.com/*' => Http::response(['messaging_product' => 'whatsapp', 'messages' => [['id' => 'wamid.sent']]])]);
        $this->account = ChannelAccount::factory()->create(['platform' => 'whatsapp', 'driver' => 'live', 'external_id' => '1098765432', 'credentials' => ['access_token' => 'WA-TOKEN']]);
        $this->to = CustomerIdentity::factory()->create(['platform' => 'whatsapp', 'external_id' => '201112223334']);
    });

    it('sends up to three short bot buttons as interactive reply buttons', function () {
        $result = app(WhatsAppAdapter::class)->sendText($this->account, $this->to, 'تحبي نساعدك في إيه؟', ['quick_replies' => [
            ['title' => 'المقاسات', 'payload' => 'flow:sizes'],
            ['title' => 'الشحن', 'payload' => 'flow:shipping'],
        ]]);

        expect($result->success)->toBeTrue()->and($result->externalId)->toBe('wamid.sent');
        Http::assertSent(fn (ClientRequest $r) => str_contains($r->url(), '/1098765432/messages')
            && $r['type'] === 'interactive'
            && $r['interactive']['type'] === 'button'
            && $r['interactive']['body']['text'] === 'تحبي نساعدك في إيه؟'
            && $r['interactive']['action']['buttons'][1] === ['type' => 'reply', 'reply' => ['id' => 'flow:shipping', 'title' => 'الشحن']]);
    });

    it('sends four to ten buttons as a list, and falls back to numbered text beyond the limits', function () {
        $buttons = array_map(fn ($i) => ['title' => "اختيار {$i}", 'payload' => "p{$i}"], range(1, 5));
        app(WhatsAppAdapter::class)->sendText($this->account, $this->to, 'اختاري', ['quick_replies' => $buttons]);
        Http::assertSent(fn (ClientRequest $r) => ($r['interactive']['type'] ?? null) === 'list'
            && count($r['interactive']['action']['sections'][0]['rows']) === 5
            && $r['interactive']['action']['sections'][0]['rows'][0] === ['id' => 'p1', 'title' => 'اختيار 1']);

        $long = [['title' => str_repeat('ط', 30), 'payload' => 'x']];
        app(WhatsAppAdapter::class)->sendText($this->account, $this->to, 'اختاري', ['quick_replies' => $long]);
        Http::assertSent(fn (ClientRequest $r) => $r['type'] === 'text' && str_contains($r['text']['body'], '1- '.str_repeat('ط', 30)));
    });

    it('sends a template without variables with no components', function () {
        app(WhatsAppAdapter::class)->sendText($this->account, $this->to, '', ['template' => ['name' => 'follow_up', 'language' => 'ar_EG', 'params' => []]]);
        app(WhatsAppAdapter::class)->sendText($this->account, $this->to, '', ['template' => ['name' => 'order_ready', 'language' => 'ar_EG', 'params' => ['#1042']]]);

        Http::assertSent(fn (ClientRequest $r) => ($r['template']['name'] ?? null) === 'follow_up' && ! isset($r['template']['components']));
        Http::assertSent(fn (ClientRequest $r) => ($r['template']['name'] ?? null) === 'order_ready'
            && $r['template']['components'] === [['type' => 'body', 'parameters' => [['type' => 'text', 'text' => '#1042']]]]);
    });
});

it('downloads whatsapp media via the media id lookup then the url with the bearer token', function () {
    config(['crm.drivers.channels' => 'live']);
    $account = ChannelAccount::factory()->create(['platform' => 'whatsapp', 'driver' => 'live', 'external_id' => '1098765432', 'credentials' => ['access_token' => 'WA-TOKEN']]);
    $conversation = App\Models\Conversation::factory()->create(['channel_account_id' => $account->id]);
    $message = $conversation->messages()->create(['platform' => 'whatsapp', 'direction' => 'in', 'sender_type' => 'customer', 'body' => '', 'external_id' => 'wamid.in', 'status' => 'received']);
    $attachment = $message->mediaAttachments()->create(['type' => 'image', 'disk' => 'media', 'mime' => 'image/jpeg', 'remote_id' => '1234567890123456', 'status' => 'pending']);

    Http::fake([
        'graph.facebook.com/*' => Http::response(['messaging_product' => 'whatsapp', 'url' => 'https://lookaside.fbsbx.com/whatsapp_business/attachments/?mid=1234567890123456&ext=1&hash=x', 'mime_type' => 'image/jpeg', 'id' => '1234567890123456']),
        'lookaside.fbsbx.com/*' => Http::response('JPEGBYTES', 200, ['Content-Type' => 'image/jpeg']),
    ]);

    $fetched = app(App\Media\InboundMediaFetcher::class)->fetch($attachment);

    expect($fetched->bytes)->toBe('JPEGBYTES');
    Http::assertSent(fn (ClientRequest $r) => str_contains($r->url(), 'graph.facebook.com/v23.0/1234567890123456') && $r->hasHeader('Authorization', 'Bearer WA-TOKEN'));
    Http::assertSent(fn (ClientRequest $r) => str_starts_with($r->url(), 'https://lookaside.fbsbx.com/') && $r->hasHeader('Authorization', 'Bearer WA-TOKEN'));
});
