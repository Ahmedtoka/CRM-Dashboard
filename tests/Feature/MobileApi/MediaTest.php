<?php

use App\Enums\AttachmentStatus;
use App\Enums\Platform;
use App\Enums\UserRole;
use App\Events\MessageCreated;
use App\Http\Resources\SupportCaseResource;
use App\Inbox\QuickReplyCatalog;
use App\Media\MediaUrls;
use App\Media\SampleMedia;
use App\Models\ChannelAccount;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\MessageAttachment;
use App\Models\QuickReply;
use App\Models\QuickReplyAttachment;
use App\Models\SupportCase;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;

beforeEach(function () {
    Storage::fake('media');
    $acc = ChannelAccount::factory()->create(['platform' => Platform::WhatsApp]);
    $this->conv = Conversation::factory()->for($acc, 'channelAccount')->create(['platform' => Platform::WhatsApp]);
    $this->message = Message::factory()->create(['conversation_id' => $this->conv->id]);
    $this->mod = User::factory()->create(['role' => UserRole::Moderator]);
    $this->mod->userPlatforms()->create(['platform' => Platform::WhatsApp]);
});

function mbStored(array $attrs = [], string $kind = 'image'): MessageAttachment
{
    $path = 'inbound/2026/10/'.Str::uuid().'.bin';
    Storage::disk('media')->put($path, SampleMedia::bytes($kind));

    return MessageAttachment::factory()->stored()->create(array_merge([
        'path' => $path, 'mime' => SampleMedia::mime($kind),
        'type' => $kind === 'file' ? 'file' : $kind, 'original_name' => SampleMedia::filename($kind),
    ], $attrs));
}

/** The path + query of an absolute URL, for the test client. */
function mbPath(string $url): string
{
    $p = parse_url($url);

    return $p['path'].(isset($p['query']) ? '?'.$p['query'] : '');
}

it('gives absolute signed urls on the api and keeps relative session urls on the web', function () {
    $a = mbStored(['message_id' => $this->message->id]);

    Sanctum::actingAs($this->mod);
    $api = $this->getJson("/api/v1/conversations/{$this->conv->id}")->assertOk()->json('messages.0.attachments.0');

    expect($api['url'])->toStartWith(url('/media/s/'.$a->id))->toContain('signature=')->toContain('expires=')
        ->and($api['thumb_url'])->toContain('signature=');

    $web = $this->actingAs($this->mod)->getJson("/inbox/conversations/{$this->conv->id}")->assertOk()->json('messages.0.attachments.0');
    expect($web['url'])->toBe('/media/'.$a->id)
        ->and($web['thumb_url'])->toBe('/media/'.$a->id);
});

it('serves a signed url without a session or cookies', function () {
    $a = mbStored(['message_id' => $this->message->id]);

    $res = $this->get(mbPath(MediaUrls::signedShow($a)))->assertOk()
        ->assertHeader('Content-Type', 'image/png')
        ->assertHeader('X-Content-Type-Options', 'nosniff');

    expect($res->headers->get('Content-Disposition'))->toStartWith('inline')
        ->and($res->headers->getCookies())->toBe([]);
});

it('forces a download with ?download=1 appended after signing', function () {
    $a = mbStored(['message_id' => $this->message->id]);

    $res = $this->get(mbPath(MediaUrls::signedShow($a)).'&download=1')->assertOk();

    expect($res->headers->get('Content-Disposition'))->toStartWith('attachment');
});

it('refuses tampered, unsigned and expired urls with 403', function () {
    $a = mbStored(['message_id' => $this->message->id]);
    $b = mbStored(['message_id' => $this->message->id]);
    $url = mbPath(MediaUrls::signedShow($a));

    $this->get(str_replace('/media/s/'.$a->id, '/media/s/'.$b->id, $url))->assertForbidden();
    $this->get('/media/s/'.$a->id)->assertForbidden();
    $this->get('/media/s/999999')->assertForbidden();

    $this->travel(121)->minutes();
    $this->get($url)->assertForbidden();
});

it('uses the configured lifetime for signed urls', function () {
    config(['crm.mobile.media_url_ttl_minutes' => 5]);
    $a = mbStored(['message_id' => $this->message->id]);
    $url = mbPath(MediaUrls::signedShow($a));

    $this->travel(4)->minutes();
    $this->get($url)->assertOk();
    $this->travel(2)->minutes();
    $this->get($url)->assertForbidden();
});

it('returns 404 for a signed url whose attachment is gone', function () {
    $a = mbStored(['message_id' => $this->message->id]);
    $url = mbPath(MediaUrls::signedShow($a));
    $a->delete();

    $this->get($url)->assertNotFound();
});

it('serves the signed thumbnail, falling back to the original', function () {
    $a = mbStored(['message_id' => $this->message->id]);
    Storage::disk('media')->put('thumbs/x.webp', SampleMedia::bytes('image'));
    $a->forceFill(['thumb_path' => 'thumbs/x.webp'])->save();

    $thumb = MediaUrls::signedThumb($a);
    expect($thumb)->toContain('/media/s/'.$a->id.'/thumb');
    $this->get(mbPath($thumb))->assertOk()->assertHeader('Content-Type', 'image/webp');

    $plain = mbStored(['message_id' => $this->message->id]);
    expect(MediaUrls::signedThumb($plain))->toContain('/media/s/'.$plain->id.'?');
});

it('has no url while an attachment is pending or failed', function () {
    MessageAttachment::factory()->create(['message_id' => $this->message->id, 'status' => AttachmentStatus::Pending, 'path' => null]);

    Sanctum::actingAs($this->mod);
    $att = $this->getJson("/api/v1/conversations/{$this->conv->id}")->assertOk()->json('messages.0.attachments.0');

    expect($att['url'])->toBeNull()->and($att['thumb_url'])->toBeNull();
});

it('signs saved-reply attachment thumbnails on the api catalog only', function () {
    Storage::disk('media')->put('replies/a.png', SampleMedia::bytes('image'));
    $reply = QuickReply::factory()->create(['platforms' => []]);
    $qa = QuickReplyAttachment::factory()->create(['quick_reply_id' => $reply->id, 'path' => 'replies/a.png']);

    Sanctum::actingAs($this->mod);
    $thumb = $this->getJson('/api/v1/quick-replies')->assertOk()->json('data.0.attachments.0.thumb_url');

    expect($thumb)->toStartWith(url('/quick-reply-attachments/s/'.$qa->id))->toContain('signature=');
    $this->get(mbPath($thumb))->assertOk()->assertHeader('Content-Type', 'image/png');
    $this->get('/quick-reply-attachments/s/'.$qa->id)->assertForbidden();

    app()->instance('request', Request::create('/inbox/conversations/'.$this->conv->id));
    expect(app(QuickReplyCatalog::class)->toArray($this->mod)[0]['attachments'][0]['thumb_url'])->toBe('/quick-reply-attachments/'.$qa->id);
});

it('signs case photos on api requests and keeps session urls on the web', function () {
    $a = mbStored(['message_id' => $this->message->id]);
    $case = SupportCase::factory()->create(['conversation_id' => $this->conv->id, 'photo_attachment_ids' => [$a->id]]);

    app()->instance('request', Request::create('/api/v1/cases/'.$case->id));
    $api = (new SupportCaseResource($case))->resolve(request());
    expect($api['photos'][0]['url'])->toStartWith(url('/media/s/'.$a->id))->toContain('signature=');

    app()->instance('request', Request::create('/cases/'.$case->id));
    $web = (new SupportCaseResource($case))->resolve(request());
    expect($web['photos'][0]['url'])->toBe('/media/'.$a->id);
});

it('builds web-style urls inside an api request when asked (payloads shared with web clients)', function () {
    $a = mbStored(['message_id' => $this->message->id]);
    app()->instance('request', Request::create('/api/v1/conversations/1'));

    expect(MediaUrls::forRequest($a))->toContain('signature=')
        ->and(MediaUrls::sessionUrls(fn () => MediaUrls::forRequest($a)))->toBe('/media/'.$a->id)
        ->and(MediaUrls::forRequest($a))->toContain('signature=');
});

it('broadcasts session urls in the message payload even when the send came from the api', function () {
    $a = mbStored(['message_id' => $this->message->id]);
    app()->instance('request', Request::create('/api/v1/conversations/'.$this->conv->id.'/messages', 'POST'));

    $payload = MessageCreated::payload($this->message->fresh());

    expect($payload['attachments'][0]['url'])->toBe('/media/'.$a->id);
});

it('refuses tampered, unsigned, expired and path-swapped thumb and saved-reply urls', function () {
    $a = mbStored(['message_id' => $this->message->id]);
    Storage::disk('media')->put('thumbs/t.webp', SampleMedia::bytes('image'));
    $a->forceFill(['thumb_path' => 'thumbs/t.webp'])->save();
    $b = mbStored(['message_id' => $this->message->id]);
    Storage::disk('media')->put('replies/q.png', SampleMedia::bytes('image'));
    $qa = QuickReplyAttachment::factory()->create(['path' => 'replies/q.png']);
    $qb = QuickReplyAttachment::factory()->create(['path' => 'replies/q.png']);

    $thumb = mbPath(MediaUrls::signedThumb($a));
    $show = mbPath(MediaUrls::signedShow($a));
    $reply = mbPath(MediaUrls::signedQuickReply($qa));

    $this->get($thumb)->assertOk();
    $this->get($reply)->assertOk();

    // Tampered id, unsigned, and a show signature replayed on the thumb path.
    $this->get(str_replace('/media/s/'.$a->id.'/', '/media/s/'.$b->id.'/', $thumb))->assertForbidden();
    $this->get('/media/s/'.$a->id.'/thumb')->assertForbidden();
    $this->get(str_replace('/media/s/'.$a->id.'?', '/media/s/'.$a->id.'/thumb?', $show))->assertForbidden();
    $this->get(str_replace('/s/'.$qa->id.'?', '/s/'.$qb->id.'?', $reply))->assertForbidden();
    $this->get('/quick-reply-attachments/s/'.$qa->id)->assertForbidden();
    $this->get($reply.'&extra=1')->assertForbidden();

    $this->travel(121)->minutes();
    $this->get($thumb)->assertForbidden();
    $this->get($reply)->assertForbidden();
});
