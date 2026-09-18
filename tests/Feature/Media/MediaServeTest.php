<?php

use App\Enums\{Platform, UserRole};
use App\Media\{MediaUrls, SampleMedia};
use App\Models\{ChannelAccount, Conversation, Message, MessageAttachment, User};
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

beforeEach(function () {
    Storage::fake('media');
    $acc = ChannelAccount::factory()->create(['platform' => Platform::WhatsApp]);
    $this->conv = Conversation::factory()->for($acc, 'channelAccount')->create();
    $this->message = Message::factory()->create(['conversation_id' => $this->conv->id]);
});

function storedAttachment(array $attrs, string $kind = 'image'): MessageAttachment {
    $path = 'inbound/2026/09/'.Str::uuid().'.bin';
    Storage::disk('media')->put($path, SampleMedia::bytes($kind));
    return MessageAttachment::factory()->stored()->create(array_merge(['path' => $path, 'mime' => SampleMedia::mime($kind),
        'type' => $kind === 'voice' ? 'audio' : ($kind === 'file' ? 'file' : $kind), 'original_name' => SampleMedia::filename($kind)], $attrs));
}

it('serves images inline to users who can see the conversation', function () {
    $a = storedAttachment(['message_id' => $this->message->id]);
    $mod = User::factory()->create(['role' => UserRole::Moderator]);
    $mod->userPlatforms()->create(['platform' => Platform::WhatsApp]);

    $this->actingAs($mod)->get(MediaUrls::show($a))->assertOk()
        ->assertHeader('Content-Type', 'image/png')
        ->assertHeader('X-Content-Type-Options', 'nosniff');
    expect($this->actingAs($mod)->get(MediaUrls::show($a))->headers->get('Content-Disposition'))->toStartWith('inline');

    $other = User::factory()->create(['role' => UserRole::Moderator]);
    $this->actingAs($other)->get(MediaUrls::show($a))->assertForbidden();
});

it('serves media with a private, never public, cache-control', function () {
    // BinaryFileResponse's Symfony constructor defaults to a public cache and calls
    // setPublic() *after* our own headers are applied, so this only stays private
    // because MediaController explicitly calls setPrivate() to undo that default.
    $a = storedAttachment(['message_id' => $this->message->id]);
    $admin = User::factory()->create(['role' => UserRole::Admin]);

    $cacheControl = (string) $this->actingAs($admin)->get(MediaUrls::show($a))->headers->get('Cache-Control');
    expect($cacheControl)->toContain('private')->not->toContain('public');
});

it('serves a filename containing slashes instead of 500ing', function () {
    // A slash in the disposition filename makes Symfony's setContentDisposition()
    // throw (a 500). Simulates a legacy/backfilled row whose original_name was
    // never sanitised at write time — serving still sanitises it defensively.
    $admin = User::factory()->create(['role' => UserRole::Admin]);
    $a = storedAttachment(['message_id' => $this->message->id, 'original_name' => 'فاتورة 1/2.pdf'], 'file');

    $response = $this->actingAs($admin)->get(MediaUrls::show($a));
    $response->assertOk();
    expect($response->headers->get('Content-Disposition'))->not->toContain('/2.pdf');
});

it('forces attachment/octet-stream when the sniffed mime is not on the type allow-list', function () {
    // A stored "image" whose bytes actually sniffed to text/html (e.g. a spoofed
    // upload) must never be served inline — inline + a real Content-Type would let
    // the browser render it, a stored-XSS vector.
    $admin = User::factory()->create(['role' => UserRole::Admin]);
    $a = storedAttachment(['message_id' => $this->message->id, 'mime' => 'text/html', 'type' => 'image']);

    $response = $this->actingAs($admin)->get(MediaUrls::show($a));
    $response->assertOk()->assertHeader('Content-Type', 'application/octet-stream');
    expect($response->headers->get('Content-Disposition'))->toStartWith('attachment');
});

it('serves a non-inline attachment with its real content-type when the mime is allowed for its type', function () {
    // Controller ruling: non-inline responses previously forced octet-stream even
    // for a mime that IS on the type's allow-list (e.g. a "file" PDF, which never
    // serves inline per AttachmentType::servesInline()). Only a disallowed/spoofed
    // mime should still be forced to octet-stream.
    $admin = User::factory()->create(['role' => UserRole::Admin]);
    $pdf = storedAttachment(['message_id' => $this->message->id], 'file');

    $response = $this->actingAs($admin)->get(MediaUrls::show($pdf));
    $response->assertOk()->assertHeader('Content-Type', 'application/pdf');
    expect($response->headers->get('Content-Disposition'))->toStartWith('attachment');
});

it('supports byte range requests for a video attachment', function () {
    $video = storedAttachment(['message_id' => $this->message->id], 'video');
    $admin = User::factory()->create(['role' => UserRole::Admin]);

    $this->actingAs($admin)->get(MediaUrls::show($video), ['Range' => 'bytes=0-3'])
        ->assertStatus(206)->assertHeader('Content-Range', 'bytes 0-3/'.strlen(SampleMedia::bytes('video')));
});

it('downloads files as attachments and supports byte ranges for audio', function () {
    $pdf = storedAttachment(['message_id' => $this->message->id], 'file');
    $admin = User::factory()->create(['role' => UserRole::Admin]);
    expect($this->actingAs($admin)->get(MediaUrls::show($pdf))->headers->get('Content-Disposition'))->toStartWith('attachment');

    $voice = storedAttachment(['message_id' => $this->message->id], 'voice');
    $this->actingAs($admin)->get(MediaUrls::show($voice), ['Range' => 'bytes=0-3'])
        ->assertStatus(206)->assertHeader('Content-Range', 'bytes 0-3/'.strlen(SampleMedia::bytes('voice')));
});

it('lets only the uploader open an unsent upload', function () {
    $owner = User::factory()->create();
    $a = storedAttachment(['message_id' => null, 'uploaded_by' => $owner->id]);
    $this->actingAs($owner)->get(MediaUrls::show($a))->assertOk();
    $this->actingAs(User::factory()->create(['role' => UserRole::Admin]))->get(MediaUrls::show($a))->assertForbidden();
});

it('serves a temporary signed public url without a session and rejects tampering', function () {
    $a = storedAttachment(['message_id' => $this->message->id]);
    $this->get(MediaUrls::temporaryPublic($a))->assertOk();
    $this->get(route('media.public', $a))->assertForbidden();
    $this->travel(61)->minutes();
    $this->get(MediaUrls::temporaryPublic($a))->assertOk(); // freshly signed again
});

it('rejects an expired signed url', function () {
    $a = storedAttachment(['message_id' => $this->message->id]);
    $url = MediaUrls::temporaryPublic($a);

    $this->travel(61)->minutes();

    $this->get($url)->assertForbidden();
});

it('rejects a tampered signature and a tampered attachment id', function () {
    $a = storedAttachment(['message_id' => $this->message->id]);
    $url = MediaUrls::temporaryPublic($a);

    $tamperedSignature = preg_replace('/signature=[0-9a-f]+/', 'signature=0000000000000000000000000000000000000000000000000000000000000000', $url);
    expect($tamperedSignature)->not->toBe($url);
    $this->get($tamperedSignature)->assertForbidden();

    $other = storedAttachment(['message_id' => $this->message->id]);
    $tamperedId = str_replace('/media/public/'.$a->id, '/media/public/'.$other->id, $url);
    $this->get($tamperedId)->assertForbidden();
});

it('refuses the public route for an attachment not yet linked to a message', function () {
    $owner = User::factory()->create();
    $a = storedAttachment(['message_id' => null, 'uploaded_by' => $owner->id]);

    $this->get(MediaUrls::temporaryPublic($a))->assertNotFound();
});
