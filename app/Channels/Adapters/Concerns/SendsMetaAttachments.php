<?php

namespace App\Channels\Adapters\Concerns;

use App\Channels\Data\SendResult;
use App\Enums\AttachmentType;
use App\Enums\Platform;
use App\Media\MediaPolicy;
use App\Media\MediaUrls;
use App\Models\ChannelAccount;
use App\Models\CustomerIdentity;
use App\Models\MessageAttachment;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Shared outbound attachment send for Messenger and Instagram (both already
 * hold `$this->graph` and implement `sendText()`/`platform()`): the media is
 * handed to Meta as a temporary signed public URL (spec §1.4), never
 * uploaded directly, and any caption follows as its own text message since
 * Meta attachments carry no caption field.
 */
trait SendsMetaAttachments
{
    public function sendAttachment(ChannelAccount $account, CustomerIdentity $to, MessageAttachment $attachment, ?string $caption = null, array $options = []): SendResult
    {
        if ($this->platform() === Platform::Instagram && $attachment->type === AttachmentType::File) {
            return SendResult::fail(MediaPolicy::INSTAGRAM_FILE);
        }

        $type = match ($attachment->type) {
            AttachmentType::Image, AttachmentType::Sticker => 'image',
            AttachmentType::Video => 'video',
            AttachmentType::Audio => 'audio',
            AttachmentType::File => 'file',
        };

        $payload = [
            'recipient' => ['id' => $to->external_id],
            'message' => ['attachment' => ['type' => $type, 'payload' => ['url' => MediaUrls::temporaryPublic($attachment), 'is_reusable' => false]]],
            'messaging_type' => isset($options['tag']) ? 'MESSAGE_TAG' : 'RESPONSE',
        ];

        if (isset($options['tag'])) {
            $payload['tag'] = $options['tag'];
        }

        $result = $this->graph->post($account, 'me/messages', $payload);

        // Meta attachments carry no caption: it follows as its own text message (spec §1.4).
        // The media itself is already sent at this point — a caption problem (a thrown
        // connection exception included) must never fail or retry the media send, since
        // SendOutboundMessage would otherwise re-post the same media on every retry.
        if ($result->success && $caption !== null && trim($caption) !== '') {
            try {
                $captionResult = $this->sendText($account, $to, $caption, $options);

                if (! $captionResult->success) {
                    Log::warning('meta.caption_send_failed', ['attachment_id' => $attachment->id, 'error' => $captionResult->error]);
                }
            } catch (Throwable $e) {
                // Never log the exception message: for a connection/timeout exception it
                // often embeds the full request URL, which for the caption's sendText()
                // call could echo back a signed media url. The attachment id plus the
                // exception's class name is enough to investigate from the logs.
                Log::warning('meta.caption_send_threw', ['attachment_id' => $attachment->id, 'exception' => $e::class]);
            }
        }

        return $result;
    }
}
