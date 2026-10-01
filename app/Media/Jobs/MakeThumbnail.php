<?php

namespace App\Media\Jobs;

use App\Media\Thumbnailer;
use App\Models\MessageAttachment;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\SerializesModels;

/** Makes one attachment's inbox thumbnail. Idempotent: skips a row that already has one. */
class MakeThumbnail implements ShouldQueue
{
    use Dispatchable, Queueable, SerializesModels;

    public int $tries = 2;

    public function __construct(public readonly int $attachmentId)
    {
        $this->afterCommit();
    }

    public function handle(Thumbnailer $thumbnailer): void
    {
        $a = MessageAttachment::find($this->attachmentId);
        if ($a !== null && $a->thumb_path === null) {
            $thumbnailer->make($a);
        }
    }
}
