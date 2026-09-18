<?php

namespace App\Media\Commands;

use App\Models\MessageAttachment;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

/**
 * Uploads never attached to a message (the moderator picked a file then
 * navigated away, or the send failed to claim it) are pruned after
 * `crm.media.orphan_hours` (spec §1.4) so the media disk doesn't grow
 * unbounded with abandoned uploads.
 */
class PruneMediaOrphans extends Command
{
    protected $signature = 'crm:prune-media-orphans';

    protected $description = 'Delete uploads never attached to a message within crm.media.orphan_hours';

    public function handle(): int
    {
        $cutoff = now()->subHours((int) config('crm.media.orphan_hours', 24));
        $count = 0;

        $candidates = [
            // A moderator's own unsent upload. An inbound attachment is created with
            // its message_id already set (InboundAttachmentRecorder::record()) and never
            // has uploaded_by, so whereNull('message_id') alone should already exclude
            // it — uploaded_by is required here too as a defensive second signal.
            fn () => MessageAttachment::query()->whereNull('message_id')->whereNotNull('uploaded_by'),
            // An unlinked outbound copy made without an uploader (the bot's size-chart
            // copy whose send never linked it — final fix wave I4). Inbound rows always
            // carry remote_url or remote_id, so requiring both null plus an outbound/
            // path keeps a pending inbound row safe even if its message_id were null.
            fn () => MessageAttachment::query()->whereNull('message_id')->whereNull('uploaded_by')
                ->whereNull('remote_url')->whereNull('remote_id')->where('path', 'like', 'outbound/%'),
        ];

        foreach ($candidates as $query) {
            $query()->where('created_at', '<', $cutoff)->chunkById(200, function ($rows) use (&$count) {
                foreach ($rows as $attachment) {
                    // Re-check message_id at delete time: if a send raced this command
                    // and just linked the row, the conditional delete affects 0 rows and
                    // we must not then still delete the (now-in-use) file underneath it.
                    $deleted = MessageAttachment::query()->whereKey($attachment->id)->whereNull('message_id')->delete();

                    if ($deleted === 1) {
                        if ($attachment->path) {
                            Storage::disk($attachment->disk)->delete($attachment->path);
                        }
                        $count++;
                    }
                }
            });
        }

        $this->info("Pruned {$count} orphan attachments.");

        return self::SUCCESS;
    }
}
