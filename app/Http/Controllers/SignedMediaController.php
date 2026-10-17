<?php

namespace App\Http\Controllers;

use App\Enums\AttachmentType;
use App\Media\MediaResponder;
use App\Models\MessageAttachment;
use App\Models\QuickReplyAttachment;
use Illuminate\Http\Request;
use Illuminate\Routing\Exceptions\InvalidSignatureException;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Media for the mobile app (API contract, "Media"): absolute signed temporary URLs handed
 * out only on authorised `api/*` responses (AttachmentResource, the saved-reply catalog,
 * case photos). The signature is the authorisation — no session, no listing — and the
 * attachment must still exist and be stored. `?download=1` may be appended by the app
 * after signing (it is ignored by the signature check) and forces Content-Disposition:
 * attachment, like the web gallery's download button. The routes run without the `web`
 * group (no session, no cookies), so ids are looked up here, after the signature check.
 */
class SignedMediaController extends Controller
{
    public function show(Request $request, string $attachment): BinaryFileResponse
    {
        $attachment = $this->message($request, $attachment);
        abort_unless($attachment->isStored(), 404);

        return MediaResponder::file($attachment->disk, (string) $attachment->path, $attachment->type, $attachment->mime, $attachment->original_name, $request->boolean('download'));
    }

    /** The small grid image; the original when none was made (yet). */
    public function thumb(Request $request, string $attachment): BinaryFileResponse
    {
        $attachment = $this->message($request, $attachment);
        abort_unless($attachment->isStored(), 404);

        if ($attachment->thumb_path !== null && Storage::disk($attachment->disk)->exists($attachment->thumb_path)) {
            $mime = str_ends_with($attachment->thumb_path, '.webp') ? 'image/webp' : 'image/jpeg';

            return MediaResponder::file($attachment->disk, $attachment->thumb_path, AttachmentType::Image, $mime, null);
        }

        return MediaResponder::file($attachment->disk, (string) $attachment->path, $attachment->type, $attachment->mime, $attachment->original_name);
    }

    public function quickReply(Request $request, string $attachment): BinaryFileResponse
    {
        $this->verify($request);
        $attachment = QuickReplyAttachment::query()->findOrFail((int) $attachment);

        return MediaResponder::file($attachment->disk, (string) $attachment->path, $attachment->type, $attachment->mime, $attachment->original_name, $request->boolean('download'));
    }

    /** Signature first, then the lookup: an unsigned caller learns nothing about which ids exist. */
    private function message(Request $request, string $id): MessageAttachment
    {
        $this->verify($request);

        return MessageAttachment::query()->findOrFail((int) $id);
    }

    /** The `signed` middleware's check, except that `download` may be added after signing. */
    private function verify(Request $request): void
    {
        if (! $request->hasValidSignatureWhileIgnoring(['download'])) {
            throw new InvalidSignatureException;
        }
    }
}
