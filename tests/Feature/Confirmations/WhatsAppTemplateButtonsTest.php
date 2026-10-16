<?php

use App\Channels\Adapters\WhatsAppAdapter;
use App\Models\ChannelAccount;
use App\Models\CustomerIdentity;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Support\Facades\Http;

// Slice W2: confirmation templates carry quick-reply button payloads (oc:{id}:{action}).

beforeEach(function () {
    Http::fake(['graph.facebook.com/*' => Http::response(['messaging_product' => 'whatsapp', 'messages' => [['id' => 'wamid.sent']]])]);
    $this->account = ChannelAccount::factory()->create(['platform' => 'whatsapp', 'driver' => 'live', 'external_id' => '1098765432', 'credentials' => ['access_token' => 'WA-TOKEN']]);
    $this->to = CustomerIdentity::factory()->create(['platform' => 'whatsapp', 'external_id' => '201112223334']);
});

it('adds one quick-reply button component per payload, after the body', function () {
    app(WhatsAppAdapter::class)->sendText($this->account, $this->to, '', ['template' => [
        'name' => 'order_confirm_ar',
        'language' => 'ar',
        'params' => ['ليلى', '#1042'],
        'buttons' => ['oc:7:confirm', 'oc:7:edit', 'oc:7:cancel'],
    ]]);

    Http::assertSent(fn (ClientRequest $r) => ($r['template']['name'] ?? null) === 'order_confirm_ar'
        && $r['template']['components'] === [
            ['type' => 'body', 'parameters' => [['type' => 'text', 'text' => 'ليلى'], ['type' => 'text', 'text' => '#1042']]],
            ['type' => 'button', 'sub_type' => 'quick_reply', 'index' => '0', 'parameters' => [['type' => 'payload', 'payload' => 'oc:7:confirm']]],
            ['type' => 'button', 'sub_type' => 'quick_reply', 'index' => '1', 'parameters' => [['type' => 'payload', 'payload' => 'oc:7:edit']]],
            ['type' => 'button', 'sub_type' => 'quick_reply', 'index' => '2', 'parameters' => [['type' => 'payload', 'payload' => 'oc:7:cancel']]],
        ]);
});

it('sends button components alone for a template without body variables', function () {
    app(WhatsAppAdapter::class)->sendText($this->account, $this->to, '', ['template' => [
        'name' => 'buttons_only', 'language' => 'ar', 'params' => [], 'buttons' => ['oc:1:confirm'],
    ]]);

    Http::assertSent(fn (ClientRequest $r) => ($r['template']['name'] ?? null) === 'buttons_only'
        && $r['template']['components'] === [
            ['type' => 'button', 'sub_type' => 'quick_reply', 'index' => '0', 'parameters' => [['type' => 'payload', 'payload' => 'oc:1:confirm']]],
        ]);
});

it('keeps a template without buttons exactly as before', function () {
    app(WhatsAppAdapter::class)->sendText($this->account, $this->to, '', ['template' => ['name' => 'follow_up', 'language' => 'ar_EG', 'params' => []]]);
    app(WhatsAppAdapter::class)->sendText($this->account, $this->to, '', ['template' => ['name' => 'order_ready', 'language' => 'ar_EG', 'params' => ['#1042'], 'buttons' => []]]);

    Http::assertSent(fn (ClientRequest $r) => ($r['template']['name'] ?? null) === 'follow_up' && ! isset($r['template']['components']));
    Http::assertSent(fn (ClientRequest $r) => ($r['template']['name'] ?? null) === 'order_ready'
        && $r['template']['components'] === [['type' => 'body', 'parameters' => [['type' => 'text', 'text' => '#1042']]]]);
});
