<?php

namespace App\Http\Controllers\Web;

use App\Enums\AttachmentStatus;
use App\Enums\AttachmentType;
use App\Http\Controllers\Controller;
use App\Http\Resources\AttachmentResource;
use App\Media\Jobs\DownloadInboundMedia;
use App\Media\MediaResponder;
use App\Models\MessageAttachment;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Authorised media serving (spec §1, "Security"): a private session route
 * gated by conversation visibility, plus a short-lived signed public route
 * used when the media has to be handed to something that can't carry a
 * browser session — e.g. a channel provider fetching an attachment back
 * for an outbound send (Task 2) — never a browser embed. The actual
 * hardened file response is shared with `QuickReplyAttachmentController`
 * (Task 4) via `MediaResponder`.
 */
class MediaController extends Controller
{
    /** `?download=1` serves the same file as `attachment` with its real name (the gallery's download button). */
    public function show(Request $request, MessageAttachment $attachment): BinaryFileResponse
    {
        Gate::authorize('view', $attachment);

        return $this->file($attachment, $request->boolean('download'));
    }

    /** The inbox thumbnail; the original when none was made (yet). Same gate as show(). */
    public function thumb(MessageAttachment $attachment): BinaryFileResponse
    {
        Gate::authorize('view', $attachment);
        abort_unless($attachment->isStored(), 404);

        if ($attachment->thumb_path !== null && Storage::disk($attachment->disk)->exists($attachment->thumb_path)) {
            $mime = str_ends_with($attachment->thumb_path, '.webp') ? 'image/webp' : 'image/jpeg';

            return MediaResponder::file($attachment->disk, $attachment->thumb_path, AttachmentType::Image, $mime, null);
        }

        return $this->file($attachment);
    }

    public function publicShow(MessageAttachment $attachment): BinaryFileResponse
    {
        // Only attachments already linked to a message may be fetched anonymously —
        // an unsent upload (message_id null) stays session-gated to its uploader.
        abort_if($attachment->message_id === null, 404);

        return $this->file($attachment);
    }

    public function retry(MessageAttachment $attachment): AttachmentResource
    {
        Gate::authorize('retry', $attachment);

        $attachment->forceFill(['status' => AttachmentStatus::Pending, 'error' => null])->save();
        DownloadInboundMedia::dispatch($attachment->id);

        return new AttachmentResource($attachment->fresh());
    }

    private function file(MessageAttachment $a, bool $download = false): BinaryFileResponse
    {
        abort_unless($a->isStored(), 404);

        return MediaResponder::file($a->disk, (string) $a->path, $a->type, $a->mime, $a->original_name, $download);
    }
}
