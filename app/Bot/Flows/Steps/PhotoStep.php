<?php

namespace App\Bot\Flows\Steps;

use App\Enums\AttachmentType;
use App\Models\Conversation;
use App\Models\Message;
use Illuminate\Support\Collection;

/**
 * Waits for an image: saves the burst's image attachment ids in `field`
 * (`['legacy']` when only the old `messages.attachments` json has one).
 *
 * «مش معايا صورة» / «I don't have a photo» moves on straight away; anything else
 * without an image gets ONE differently-worded nudge (never the same sentence twice,
 * design 2026-09-21 §3) and then continues with `field_missing`.
 */
final class PhotoStep extends BaseStep
{
    /** She is telling us there is no photo, in either language. */
    private const SKIP_PHRASES = [
        'مش معايا صوره', 'مش معايا صور', 'معنديش صوره', 'معنديش صور', 'مفيش صوره', 'مفيش صور',
        'مش هبعت صوره', 'مش قادره ابعت صوره', 'من غير صوره', 'بعدين', 'تخطي',
        'no photo', 'no picture', 'no image', 'dont have a photo', 'do not have a photo',
        'dont have photo', 'havent got a photo', 'cant send a photo', 'cannot send a photo',
        'skip', 'later', 'no pics', 'no pic',
    ];

    /** The one nudge, translated for an English chat by the outbound translation layer. */
    private const NUDGE = 'محتاجين صورة للقطعة عشان الفريق يشوف المشكلة 🙏 لو مش معاكي صورة دلوقتي اكتبي «مش معايا صورة» ونكمل.';

    /** Whether a single message carries an image: a media attachment, or a legacy `attachments` json entry. */
    public static function hasImage(Message $m): bool
    {
        if ($m->mediaAttachments()->where('type', AttachmentType::Image->value)->exists()) {
            return true;
        }

        return collect((array) $m->attachments)->contains(fn ($a) => is_array($a) && ($a['type'] ?? null) === 'image');
    }

    public function answer(Conversation $c, array $state, array $step, string $text, Collection $burst): ?StepOutcome
    {
        $ids = [];
        $legacy = false;

        foreach ($burst as $m) {
            /** @var Message $m */
            array_push($ids, ...$m->mediaAttachments()->where('type', AttachmentType::Image->value)->pluck('id')->map(fn ($id) => (int) $id)->all());
            $legacy = $legacy || collect((array) $m->attachments)->contains(fn ($a) => is_array($a) && ($a['type'] ?? null) === 'image');
        }

        if ($ids === [] && ! $legacy) {
            return null;
        }

        $field = (string) $step['field'];

        return StepOutcome::continue([$field => $ids !== [] ? $ids : ['legacy'], $field.'_missing' => null]);
    }

    public function unresolved(Conversation $c, array $state, array $step, string $text): StepOutcome
    {
        if (self::saysNoPhoto($text) || $state['retries'] >= 1) {
            return StepOutcome::continue([(string) $step['field'].'_missing' => true]);
        }

        // A different sentence, never the same prompt twice, and it tells her how to skip.
        return StepOutcome::wait([['text' => self::NUDGE]], $state['retries'] + 1);
    }

    /** Public so the engine's off-flow check can tell "no photo" from a real question. */
    public static function saysNoPhoto(string $text): bool
    {
        $clean = mb_strtolower(trim(preg_replace('/[^\p{L}\p{N}\s]+/u', ' ', $text) ?? ''));
        $clean = preg_replace('/\s+/u', ' ', strtr($clean, ['أ' => 'ا', 'إ' => 'ا', 'آ' => 'ا', 'ة' => 'ه', 'ى' => 'ي', "'" => ''])) ?? '';

        foreach (self::SKIP_PHRASES as $phrase) {
            if ($clean !== '' && str_contains($clean, $phrase)) {
                return true;
            }
        }

        return false;
    }
}
