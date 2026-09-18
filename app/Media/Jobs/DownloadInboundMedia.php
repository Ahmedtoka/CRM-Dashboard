<?php

namespace App\Media\Jobs;

use App\Enums\AttachmentStatus;
use App\Events\MessageUpdated;
use App\Media\InboundMediaFetcher;
use App\Media\MediaFetchFailed;
use App\Media\MediaStorage;
use App\Models\Message;
use App\Models\MessageAttachment;
use App\Support\SafeBroadcast;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Str;
use Throwable;

/**
 * Downloads one pending inbound attachment's bytes and stores them (spec §1.3:
 * inbound ingest never downloads inline, so this always runs on the `media`
 * queue after InboxIngestor records a pending row). Unique per attachment id
 * so a retry click and a still-queued original attempt can never race.
 */
class DownloadInboundMedia implements ShouldQueue, ShouldBeUnique
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    /** @var array<int, int> */
    public array $backoff = [10, 60];

    /**
     * Worst realistic case is the WhatsApp Graph media lookup (10s timeout,
     * 2 retries ≈ 30s) plus the CDN download itself (30s timeout) ≈ 60s; kept
     * a few seconds under the crm-media supervisor worker's --timeout=80 so
     * this job always self-terminates before the worker would kill it.
     */
    public int $timeout = 70;

    /**
     * The uniqueness lock expires on its own after 10 minutes (final fix wave M3),
     * so a worker killed mid-job can never leave the attachment un-retryable forever.
     */
    public int $uniqueFor = 600;

    public function __construct(public readonly int $attachmentId)
    {
        $this->onQueue('media');
    }

    public function uniqueId(): string
    {
        return (string) $this->attachmentId;
    }

    public function handle(InboundMediaFetcher $fetcher, MediaStorage $storage): void
    {
        $a = MessageAttachment::with('message.conversation.channelAccount')->find($this->attachmentId);

        if ($a === null || $a->status === AttachmentStatus::Stored) {
            return;
        }

        $fetched = $fetcher->fetch($a);
        $storage->storeBytes($a, $fetched->bytes, $fetched->mime, $fetched->filename);
        $this->broadcast($a);
    }

    public function failed(?Throwable $e): void
    {
        $a = MessageAttachment::find($this->attachmentId);

        if ($a === null || $a->status === AttachmentStatus::Stored) {
            return;
        }

        // Never store a raw exception message: MediaFetchFailed's messages are
        // controlled, code-style strings (download_http_404, host_not_allowed, ...);
        // anything else (a stray Guzzle/storage exception) could otherwise leak a
        // signed url, an appsecret_proof, or a bearer token into this column.
        $code = $e instanceof MediaFetchFailed ? $e->getMessage() : 'download_failed';

        $a->forceFill(['status' => AttachmentStatus::Failed, 'error' => Str::limit($code, 250)])->save();
        $this->broadcast($a);
    }

    private function broadcast(MessageAttachment $a): void
    {
        $message = Message::with(['user', 'mediaAttachments'])->find($a->message_id);

        if ($message !== null) {
            SafeBroadcast::send(new MessageUpdated($message));
        }
    }
}
