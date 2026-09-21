<?php

use App\Enums\AttachmentStatus;
use App\Enums\AttachmentType;
use App\Media\MediaPolicy;
use App\Media\MediaRejected;
use App\Media\MediaStorage;
use App\Media\SampleMedia;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

beforeEach(fn () => Storage::fake('media'));

function sampleUpload(string $kind, string $name, ?string $clientMime = null): UploadedFile
{
    $path = tempnam(sys_get_temp_dir(), 'crm');
    file_put_contents($path, SampleMedia::bytes($kind));

    return new UploadedFile($path, $name, $clientMime ?? SampleMedia::mime($kind), null, true);
}

it('stores an upload under a random outbound path with sniffed mime and dimensions', function () {
    $user = User::factory()->create();
    $a = app(MediaStorage::class)->storeUpload(sampleUpload('image', 'dress.png', 'application/octet-stream'), $user);

    expect($a->type)->toBe(AttachmentType::Image)
        ->and($a->status)->toBe(AttachmentStatus::Stored)
        ->and($a->mime)->toBe('image/png')
        ->and([$a->width, $a->height])->toBe([1, 1])
        ->and($a->original_name)->toBe('dress.png')
        ->and($a->message_id)->toBeNull()
        ->and($a->uploaded_by)->toBe($user->id)
        ->and($a->path)->toMatch('#^outbound/\d{4}/\d{2}/[0-9a-f-]{36}\.png$#');
    Storage::disk('media')->assertExists($a->path);
});

it('detects pdf, ogg voice and mp4 by magic bytes', function (string $kind, string $mime, AttachmentType $type) {
    $a = app(MediaStorage::class)->storeUpload(sampleUpload($kind, 'x.bin', 'application/octet-stream'), User::factory()->create());
    expect($a->mime)->toBe($mime)->and($a->type)->toBe($type);
})->with([
    ['file', 'application/pdf', AttachmentType::File],
    ['voice', 'audio/ogg', AttachmentType::Audio],
    ['video', 'video/mp4', AttachmentType::Video],
]);

it('rejects executables and oversized files with a translated message', function () {
    $path = tempnam(sys_get_temp_dir(), 'crm');
    file_put_contents($path, "MZ\x90\x00".str_repeat("\x00", 60));
    expect(fn () => app(MediaStorage::class)->storeUpload(new UploadedFile($path, 'a.jpg', 'image/jpeg', null, true), User::factory()->create()))
        ->toThrow(MediaRejected::class, __(MediaPolicy::UNSUPPORTED));

    config(['crm.media.types.image.max_bytes' => 10]);
    expect(fn () => app(MediaStorage::class)->storeUpload(sampleUpload('image', 'big.png'), User::factory()->create()))
        ->toThrow(MediaRejected::class);
});

it('strips slashes and backslashes from a filename so serving can never 500', function () {
    expect(MediaStorage::sanitizeFilename('فاتورة 1/2.pdf'))->toBe('فاتورة 1_2.pdf')
        ->and(MediaStorage::sanitizeFilename('a\\b/c.txt'))->toBe('a_b_c.txt')
        ->and(MediaStorage::sanitizeFilename(null))->toBeNull();
});
