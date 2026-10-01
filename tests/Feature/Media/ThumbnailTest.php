<?php

use App\Enums\AttachmentStatus;
use App\Enums\AttachmentType;
use App\Enums\Platform;
use App\Enums\UserRole;
use App\Http\Resources\AttachmentResource;
use App\Media\Jobs\MakeThumbnail;
use App\Media\MediaStorage;
use App\Models\ChannelAccount;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\MessageAttachment;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

beforeEach(function () {
    Storage::fake('media');
    config(['queue.default' => 'sync']);
    $acc = ChannelAccount::factory()->create(['platform' => Platform::Instagram]);
    $this->conv = Conversation::factory()->for($acc, 'channelAccount')->create();
    $this->message = Message::factory()->create(['conversation_id' => $this->conv->id]);
});

function jpegUpload(int $w, int $h): UploadedFile
{
    $img = imagecreatetruecolor($w, $h);
    imagefilledrectangle($img, 0, 0, $w, $h, imagecolorallocate($img, 200, 30, 90));
    $file = tempnam(sys_get_temp_dir(), 'th').'.jpg';
    imagejpeg($img, $file, 90);

    return new UploadedFile($file, 'photo.jpg', 'image/jpeg', null, true);
}

function thumbSize(MessageAttachment $a): array
{
    $info = getimagesizefromstring(Storage::disk('media')->get($a->thumb_path));

    return [$info[0], $info[1]];
}

it('makes a 480px thumbnail of a large image when it is stored, keeping the aspect', function () {
    $admin = User::factory()->create(['role' => UserRole::Admin]);
    $a = app(MediaStorage::class)->storeUpload(jpegUpload(1600, 900), $admin)->fresh();

    expect($a->thumb_path)->not->toBeNull()->toStartWith('thumbs/');
    Storage::disk('media')->assertExists($a->thumb_path);
    [$w, $h] = thumbSize($a);
    expect($w)->toBe(480)->and(abs($h - 270))->toBeLessThanOrEqual(1);
    expect(Storage::disk('media')->size($a->thumb_path))->toBeLessThan(Storage::disk('media')->size($a->path));
});

it('never upscales a small image', function () {
    $admin = User::factory()->create(['role' => UserRole::Admin]);
    $a = app(MediaStorage::class)->storeUpload(jpegUpload(300, 200), $admin)->fresh();

    expect(thumbSize($a))->toBe([300, 200]);
});

it('keeps thumb_path null and only logs a warning when the bytes cannot be decoded', function () {
    Log::spy();
    $path = 'inbound/2026/10/'.Str::uuid().'.jpg';
    Storage::disk('media')->put($path, 'definitely not an image');

    $a = MessageAttachment::factory()->stored()->create(['message_id' => $this->message->id, 'path' => $path, 'mime' => 'image/jpeg']);

    expect($a->fresh()->thumb_path)->toBeNull();
    Log::shouldHaveReceived('warning')->withArgs(fn ($m) => $m === 'media.thumbnail_failed')->once();
});

it('serves the thumbnail to a user who can view the conversation and refuses one who cannot', function () {
    $mod = User::factory()->create(['role' => UserRole::Moderator]);
    $mod->userPlatforms()->create(['platform' => Platform::Instagram]);
    $other = User::factory()->create(['role' => UserRole::Moderator]);
    $other->userPlatforms()->create(['platform' => Platform::Facebook]);

    $a = app(MediaStorage::class)->storeUpload(jpegUpload(1600, 900), $mod);
    $a->forceFill(['message_id' => $this->message->id])->save();

    $res = $this->actingAs($mod)->get("/media/{$a->id}/thumb")->assertOk();
    expect($res->headers->get('Content-Type'))->toStartWith('image/');
    expect($res->headers->get('Content-Disposition'))->toStartWith('inline');
    $this->actingAs($other)->get("/media/{$a->id}/thumb")->assertForbidden();
});

it('falls back to the original on the thumb route when no thumbnail exists', function () {
    $mod = User::factory()->create(['role' => UserRole::Admin]);
    $path = 'inbound/2026/10/'.Str::uuid().'.png';
    Storage::disk('media')->put($path, base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg=='));
    $a = MessageAttachment::factory()->stored()->create(['message_id' => $this->message->id, 'path' => $path]);
    $a->forceFill(['thumb_path' => null])->saveQuietly();

    $this->actingAs($mod)->get("/media/{$a->id}/thumb")->assertOk()->assertHeader('Content-Type', 'image/png');
});

it('exposes a thumb_url that differs from url once a thumbnail exists', function () {
    $admin = User::factory()->create(['role' => UserRole::Admin]);
    $a = app(MediaStorage::class)->storeUpload(jpegUpload(1600, 900), $admin)->fresh();

    $data = (new AttachmentResource($a))->resolve();
    expect($data['thumb_url'])->toEndWith('/thumb')->and($data['thumb_url'])->not->toBe($data['url']);

    $plain = MessageAttachment::factory()->stored()->create(['message_id' => $this->message->id, 'type' => AttachmentType::Image]);
    $plain->forceFill(['thumb_path' => null])->saveQuietly();
    $d = (new AttachmentResource($plain->fresh()))->resolve();
    expect($d['thumb_url'])->toBe($d['url']);
});

it('backfills old rows with media:thumbnails --sync and queues nothing on the second run', function () {
    config(['queue.default' => 'sync']);
    $rows = collect(range(1, 3))->map(function () {
        $path = 'inbound/2026/09/'.Str::uuid().'.jpg';
        ob_start();
        imagejpeg(imagecreatetruecolor(800, 600));
        Storage::disk('media')->put($path, ob_get_clean());

        $a = MessageAttachment::factory()->stored()->create(['message_id' => $this->message->id, 'path' => $path, 'mime' => 'image/jpeg']);
        $a->forceFill(['thumb_path' => null])->saveQuietly();

        return $a;
    });
    // a stored audio row and a pending image row are not candidates
    MessageAttachment::factory()->stored()->create(['message_id' => $this->message->id, 'type' => AttachmentType::Audio])->forceFill(['thumb_path' => null])->saveQuietly();
    MessageAttachment::factory()->create(['message_id' => $this->message->id, 'status' => AttachmentStatus::Pending]);

    $this->artisan('media:thumbnails', ['--sync' => true])->expectsOutput('queued=3')->assertSuccessful();
    $rows->each(fn ($a) => expect($a->fresh()->thumb_path)->not->toBeNull());

    $this->artisan('media:thumbnails', ['--sync' => true])->expectsOutput('queued=0')->assertSuccessful();
});

it('dispatches the thumbnail job when created stored or newly stored, not on unrelated saves', function () {
    Queue::fake();

    $a = MessageAttachment::factory()->stored()->create(['message_id' => $this->message->id]);
    Queue::assertPushed(MakeThumbnail::class, 1);

    $a->forceFill(['original_name' => 'x.png'])->save();   // unrelated save of a stored row
    $a->forceFill(['error' => null])->save();
    Queue::assertPushed(MakeThumbnail::class, 1);

    $p = MessageAttachment::factory()->create(['message_id' => $this->message->id, 'status' => AttachmentStatus::Pending]);
    Queue::assertPushed(MakeThumbnail::class, 1); // pending: none
    $p->forceFill(['status' => AttachmentStatus::Stored, 'path' => 'inbound/2026/10/'.Str::uuid().'.png'])->save();
    Queue::assertPushed(MakeThumbnail::class, 2);
});

it('deletes the thumbnail file when pruning an orphan attachment', function () {
    $admin = User::factory()->create(['role' => UserRole::Admin]);
    $a = app(MediaStorage::class)->storeUpload(jpegUpload(1600, 900), $admin)->fresh();
    $a->forceFill(['created_at' => now()->subDays(3)])->saveQuietly();
    Storage::disk('media')->assertExists($a->thumb_path);

    $this->artisan('crm:prune-media-orphans')->assertSuccessful();

    Storage::disk('media')->assertMissing($a->thumb_path);
    Storage::disk('media')->assertMissing($a->path);
});
