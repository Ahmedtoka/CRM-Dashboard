<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Media\MediaResponder;
use App\Models\QuickReplyAttachment;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Serves a saved reply's own attachment (spec §2.1) — used by the picker's
 * thumbnail before the reply is ever rendered/sent. Shares the same hardened
 * response (private cache, sandboxed CSP, nosniff, mime-allowlist-gated
 * content-type) as `MediaController::show()` (Task 1) via `MediaResponder`.
 */
class QuickReplyAttachmentController extends Controller
{
    public function show(QuickReplyAttachment $attachment): BinaryFileResponse
    {
        Gate::authorize('use', $attachment->reply);

        return MediaResponder::file($attachment->disk, (string) $attachment->path, $attachment->type, $attachment->mime, $attachment->original_name);
    }
}
