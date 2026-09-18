<?php

namespace App\Media;

use App\Enums\AttachmentStatus;
use App\Enums\AttachmentType;
use App\Models\Message;

/**
 * Records pending MessageAttachment rows from the raw inbound attachment
 * shape produced by a channel adapter's normalize() — {type, url?|id?|fixture?,
 * mime_type?, filename?, voice?, sticker_id?}. Actual bytes are fetched later
 * by DownloadInboundMedia so ingestion never blocks on a platform download
 * (spec §1.3: inbound p95 target unchanged).
 */
final class InboundAttachmentRecorder
{
    /**
     * @param  array<int, array<string, mixed>>  $raw
     * @return list<int>
     */
    public function record(Message $message, array $raw): array
    {
        $ids = [];

        foreach ($raw as $item) {
            $type = match ($item['type'] ?? null) {
                'image' => AttachmentType::Image, 'audio', 'voice' => AttachmentType::Audio, 'video' => AttachmentType::Video,
                'file', 'document' => AttachmentType::File, 'sticker' => AttachmentType::Sticker, default => null,
            };
            $url = $item['url'] ?? null;
            $id = isset($item['id']) && $item['id'] !== '' ? (string) $item['id'] : null;
            $fixture = $item['fixture'] ?? null;

            if ($type === null || ($url === null && $id === null && $fixture === null)) {
                continue;
            }

            $ids[] = $message->mediaAttachments()->create([
                'type' => $type, 'disk' => (string) config('crm.media.disk', 'media'),
                'mime' => isset($item['mime_type']) ? trim(explode(';', (string) $item['mime_type'])[0]) : null,
                'original_name' => MediaStorage::sanitizeFilename($item['filename'] ?? null),
                'remote_url' => $fixture !== null ? 'fixture:'.$fixture : $url,
                'remote_id' => $id,
                'status' => AttachmentStatus::Pending,
            ])->id;
        }

        return $ids;
    }
}
