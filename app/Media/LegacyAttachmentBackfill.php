<?php

namespace App\Media;

use Illuminate\Support\Facades\DB;

/**
 * One-time migration of legacy `messages.attachments` JSON into
 * `message_attachments` rows (spec §1, Dashboard Experience Task 1). Idempotent:
 * a message that already has attachment rows is skipped, so running this more
 * than once only picks up messages created since the last run.
 */
final class LegacyAttachmentBackfill
{
    public function run(): int
    {
        $created = 0;

        DB::table('messages')->whereNotNull('attachments')->orderBy('id')->chunkById(500, function ($rows) use (&$created) {
            $now = now();

            foreach ($rows as $row) {
                if (DB::table('message_attachments')->where('message_id', $row->id)->exists()) {
                    continue;
                }

                foreach ((array) json_decode((string) $row->attachments, true) as $item) {
                    $type = match ($item['type'] ?? null) {
                        'image' => 'image', 'audio' => 'audio', 'video' => 'video', 'file', 'document' => 'file', 'sticker' => 'sticker', default => null,
                    };
                    $url = $item['url'] ?? null;
                    $id = $item['id'] ?? null;

                    if ($type === null || ($url === null && $id === null)) {
                        continue;
                    }

                    DB::table('message_attachments')->insert([
                        'message_id' => $row->id, 'type' => $type, 'disk' => (string) config('crm.media.disk', 'media'),
                        'remote_url' => $url, 'remote_id' => $id !== null ? (string) $id : null, 'status' => 'pending',
                        'created_at' => $now, 'updated_at' => $now,
                    ]);
                    $created++;
                }
            }
        });

        return $created;
    }
}
