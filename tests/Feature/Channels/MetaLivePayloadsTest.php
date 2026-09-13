<?php

use App\Channels\Adapters\{MessengerAdapter, InstagramAdapter};
use App\Channels\Data\{InboundMessageData, InboundCommentData};

$fx = fn (string $n) => json_decode(file_get_contents(base_path("tests/Fixtures/meta/{$n}.json")), true);

it('normalizes messenger text and image, ignores echoes', function () use ($fx) {
    $a = app(MessengerAdapter::class);
    expect($a->normalize($fx('messenger_text'))[0])->toBeInstanceOf(InboundMessageData::class)
        ->and($a->normalize($fx('messenger_image'))[0]->attachments[0]['type'])->toBe('image')
        ->and($a->normalize($fx('messenger_echo')))->toBe([]);
});

it('normalizes a messenger sticker as a sticker attachment', function () use ($fx) {
    $a = app(MessengerAdapter::class);
    $events = $a->normalize($fx('messenger_sticker'));

    expect($events[0])->toBeInstanceOf(InboundMessageData::class)
        ->and($events[0]->attachments[0]['type'])->toBe('sticker')
        ->and($events[0]->attachments[0]['sticker_id'])->toBe('369239263222822');
});

it('applies a message-level sticker_id only when there is a single (or no) attachment', function () use ($fx) {
    $a = app(MessengerAdapter::class);
    $events = $a->normalize($fx('messenger_sticker_message_level'));

    expect($events[0])->toBeInstanceOf(InboundMessageData::class)
        ->and($events[0]->attachments[0]['type'])->toBe('sticker')
        ->and($events[0]->attachments[0]['sticker_id'])->toBe('369239263222822')
        ->and($events[0]->attachments[0]['url'])->toBeNull();
});

it('normalizes a messenger postback with the title as body', function () use ($fx) {
    $a = app(MessengerAdapter::class);
    $events = $a->normalize($fx('messenger_postback'));

    expect($events[0])->toBeInstanceOf(InboundMessageData::class)
        ->and($events[0]->body)->toBe('عرض المنتجات');
});

it('ignores edited and page-authored comments', function () use ($fx) {
    $a = app(MessengerAdapter::class);
    expect($a->normalize($fx('feed_comment_add'))[0])->toBeInstanceOf(InboundCommentData::class)
        ->and($a->normalize($fx('feed_comment_edited')))->toBe([])
        ->and($a->normalize($fx('feed_comment_by_page')))->toBe([]);
});

it('normalizes instagram messages, comments and story mentions; ignores echoes', function () use ($fx) {
    $a = app(InstagramAdapter::class);
    expect($a->normalize($fx('instagram_message'))[0])->toBeInstanceOf(InboundMessageData::class)
        ->and($a->normalize($fx('instagram_message_echo')))->toBe([])
        ->and($a->normalize($fx('instagram_comment'))[0])->toBeInstanceOf(InboundCommentData::class)
        ->and($a->normalize($fx('instagram_story_mention'))[0]->attachments[0]['type'])->toBe('story_mention');
});

it('ignores instagram comments authored by the account itself', function () use ($fx) {
    $a = app(InstagramAdapter::class);

    expect($a->normalize($fx('instagram_comment_by_account')))->toBe([]);
});

it('adds appsecret_proof to graph calls', function () {
    config(['crm.meta.app_secret' => 'sec', 'crm.drivers.channels' => 'live']);
    \Illuminate\Support\Facades\Http::fake(['graph.facebook.com/*' => \Illuminate\Support\Facades\Http::response(['recipient_id' => '1', 'message_id' => 'm'])]);
    $acc = \App\Models\ChannelAccount::factory()->create(['platform' => 'facebook', 'driver' => 'live', 'credentials' => ['access_token' => 'tok']]);
    $to = \App\Models\CustomerIdentity::factory()->create(['platform' => 'facebook', 'external_id' => 'PSID']);
    app(MessengerAdapter::class)->sendText($acc, $to, 'hi');
    \Illuminate\Support\Facades\Http::assertSent(fn ($r) => str_contains($r->url(), 'appsecret_proof='.hash_hmac('sha256', 'tok', 'sec')));
});

it('sends an instagram message using the linked facebook page token', function () {
    config(['crm.meta.app_secret' => 'sec', 'crm.drivers.channels' => 'live']);
    \Illuminate\Support\Facades\Http::fake(['graph.facebook.com/*' => \Illuminate\Support\Facades\Http::response(['recipient_id' => '1', 'message_id' => 'm'])]);

    $page = \App\Models\ChannelAccount::factory()->create(['platform' => 'facebook', 'driver' => 'live', 'credentials' => ['access_token' => 'page-tok']]);
    $ig = \App\Models\ChannelAccount::factory()->create([
        'platform' => 'instagram',
        'driver' => 'live',
        'external_id' => '17841400123456789',
        'credentials' => ['linked_facebook_account_id' => $page->id],
    ]);
    $to = \App\Models\CustomerIdentity::factory()->create(['platform' => 'instagram', 'external_id' => 'IGSID']);

    app(InstagramAdapter::class)->sendText($ig, $to, 'أهلا');

    \Illuminate\Support\Facades\Http::assertSent(fn ($r) => str_contains($r->url(), 'appsecret_proof='.hash_hmac('sha256', 'page-tok', 'sec'))
        && $r->hasHeader('Authorization', 'Bearer page-tok'));
});
