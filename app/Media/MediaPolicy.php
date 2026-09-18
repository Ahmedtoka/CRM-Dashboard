<?php

namespace App\Media;

use App\Enums\AttachmentType;
use App\Enums\Platform;
use App\Models\MessageAttachment;

/**
 * Upload and send-time media rules (spec §1.2): what types/sizes are
 * accepted globally, and again per platform before an outbound send (Task
 * 2). Every rejection message is Arabic and safe to surface to the user.
 */
final class MediaPolicy
{
    public const UNSUPPORTED = 'نوع الملف ده مش مدعوم';

    public const TOO_BIG = 'الملف أكبر من المسموح (:max ميجا)';

    public const PLATFORM_TOO_BIG = 'الملف أكبر من المسموح على :platform (:max ميجا)';

    public const PLATFORM_TYPE = 'النوع ده مش مدعوم على :platform';

    public const INSTAGRAM_FILE = 'إنستجرام مش بيقبل ملفات — ابعت صورة أو فيديو أو صوت بس';

    public const WHATSAPP_VOICE_UNSUPPORTED = 'صيغة الفويس مش مدعومة على واتساب';

    public const NOT_CLAIMABLE = 'فيه ملف مش موجود أو اتبعت قبل كده — ارفعه تاني';

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
        $type = $this->typeFor($mime) ?? throw new MediaRejected(self::UNSUPPORTED);
        $max = (int) config("crm.media.types.{$type->value}.max_bytes");

        if ($bytes > $max) {
            throw new MediaRejected(strtr(self::TOO_BIG, [':max' => (string) intdiv($max, 1024 * 1024)]));
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
            throw new MediaRejected(self::INSTAGRAM_FILE);
        }

        $rule = config("crm.media.platforms.{$platform->value}.{$a->type->value}");

        if ($rule === null) {
            throw new MediaRejected(strtr(self::PLATFORM_TYPE, [':platform' => $platform->label()]));
        }

        if ($rule['mimes'] !== null && ! in_array((string) $a->mime, $rule['mimes'], true)) {
            throw new MediaRejected(strtr(self::PLATFORM_TYPE, [':platform' => $platform->label()]));
        }

        if ((int) $a->size_bytes > (int) $rule['max_bytes']) {
            throw new MediaRejected(strtr(self::PLATFORM_TOO_BIG, [':platform' => $platform->label(), ':max' => (string) intdiv((int) $rule['max_bytes'], 1024 * 1024)]));
        }
    }
}
