<?php

namespace App\Media\Commands;

use App\Enums\AttachmentStatus;
use App\Enums\AttachmentType;
use App\Media\Jobs\MakeThumbnail;
use App\Models\MessageAttachment;
use Illuminate\Console\Command;

/** Thumbnails for stored images/stickers that predate Task 4b. Safe to re-run: only rows without one. */
class BackfillThumbnailsCommand extends Command
{
    protected $signature = 'media:thumbnails {--chunk=200 : Rows per batch} {--sync : Run inline instead of queueing}';

    protected $description = 'Create inbox thumbnails for stored images and stickers that have none';

    public function handle(): int
    {
        $queued = 0;
        MessageAttachment::query()
            ->where('status', AttachmentStatus::Stored->value)
            ->whereIn('type', [AttachmentType::Image->value, AttachmentType::Sticker->value])
            ->whereNotNull('path')->whereNull('thumb_path')
            ->chunkById(max(1, (int) $this->option('chunk')), function ($rows) use (&$queued) {
                foreach ($rows as $a) {
                    $this->option('sync') ? MakeThumbnail::dispatchSync($a->id) : MakeThumbnail::dispatch($a->id);
                    $queued++;
                }
            });

        $this->info("queued={$queued}");

        return self::SUCCESS;
    }
}
