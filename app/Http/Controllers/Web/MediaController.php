<?php

namespace App\Http\Controllers\Web;

use App\Enums\AttachmentStatus;
use App\Http\Controllers\Controller;
use App\Http\Resources\AttachmentResource;
use App\Media\Jobs\DownloadInboundMedia;
use App\Media\MediaResponder;
use App\Models\MessageAttachment;
use Illuminate\Support\Facades\Gate;
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
    public function show(MessageAttachment $attachment): BinaryFileResponse
    {
        Gate::authorize('view', $attachment);

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

    private function file(MessageAttachment $a): BinaryFileResponse
    {
        abort_unless($a->isStored(), 404);

        return MediaResponder::file($a->disk, (string) $a->path, $a->type, $a->mime, $a->original_name);
    }
}
