<?php

namespace App\Media;

use App\Enums\AttachmentType;
use App\Enums\Platform;
use App\Models\MessageAttachment;

/**
 * Upload and send-time media rules (spec §1.2): what types/sizes are
 * accepted globally, and again per platform before an outbound send (Task
 * 2).
 *
 * The constants are `errors.media.*` translation keys, not finished copy:
 * a rejection is rendered in the requesting user's language, so every
 * throw site translates the key with `__()` right before it is shown.
 */
final class MediaPolicy
{
    public const UNSUPPORTED = 'errors.media.unsupported';

    public const TOO_BIG = 'errors.media.too_big';

    public const PLATFORM_TOO_BIG = 'errors.media.platform_too_big';

    public const PLATFORM_TYPE = 'errors.media.platform_type';

    public const INSTAGRAM_FILE = 'errors.media.instagram_file';

    public const WHATSAPP_VOICE_UNSUPPORTED = 'errors.media.whatsapp_voice_unsupported';

    public const NOT_CLAIMABLE = 'errors.media.not_claimable';

    public function typeFor(string $mime): ?AttachmentType
    {
        foreach (config('crm.media.types', []) as $type => $rule) {
            if (in_array($mime, $rule['mimes'], true)) {
                return AttachmentType::from($type);
            }
        }

        return null;
    }

    public function assertUploadable(string $mime, int $bytes): AttachmentType
    {
        $type = $this->typeFor($mime) ?? throw new MediaRejected(__(self::UNSUPPORTED));
        $max = (int) config("crm.media.types.{$type->value}.max_bytes");

        if ($bytes > $max) {
            throw new MediaRejected(__(self::TOO_BIG, ['max' => (string) intdiv($max, 1024 * 1024)]));
        }

        return $type;
    }

    /**
     * Channel checks again before send (spec §1.2). WhatsApp webm voice is
     * decided by the adapter (transcode or fail on the bubble).
     */
    public function assertSendable(MessageAttachment $a, Platform $platform): void
    {
        if ($platform === Platform::Instagram && $a->type === AttachmentType::File) {
            throw new MediaRejected(__(self::INSTAGRAM_FILE));
        }

        $rule = config("crm.media.platforms.{$platform->value}.{$a->type->value}");

        if ($rule === null) {
            throw new MediaRejected(__(self::PLATFORM_TYPE, ['platform' => $platform->label()]));
        }

        if ($rule['mimes'] !== null && ! in_array((string) $a->mime, $rule['mimes'], true)) {
            throw new MediaRejected(__(self::PLATFORM_TYPE, ['platform' => $platform->label()]));
        }

        if ((int) $a->size_bytes > (int) $rule['max_bytes']) {
            throw new MediaRejected(__(self::PLATFORM_TOO_BIG, ['platform' => $platform->label(), 'max' => (string) intdiv((int) $rule['max_bytes'], 1024 * 1024)]));
        }
    }
}
