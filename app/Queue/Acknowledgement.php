<?php

namespace App\Queue;

use App\Bot\ArabicNormalizer;
use App\Models\Message;

/**
 * Spec 2026-09-30 §1: a customer message whose WHOLE content is thanks or approval is an
 * acknowledgement, not a request. It qualifies when it is made of:
 *  - words from `config('crm.queue.acknowledgements')` (with fillers like «يا فندم», «جداً»), or
 *  - emoji / punctuation only, or
 *  - stickers only (a Messenger like is a sticker).
 *
 * It is a real message when it has anything else: another word
 * («شكراً، طب المقاس L موجود؟»), a question mark, a photo or any other attachment, or a button
 * tap. Reactions never arrive as messages (the adapters drop them). The one place the rule lives.
 */
final class Acknowledgement
{
    /** @var array{0: list<list<string>>, 1: array<string, true>}|null normalized phrases (longest first) and fillers */
    private ?array $lists = null;

    public function __construct(private readonly ArabicNormalizer $normalizer) {}

    public function matches(Message $m): bool
    {
        if (filled($m->payload)) {
            return false; // a button tap is a choice, not a thanks
        }

        return $this->matchesContent((string) $m->body, (array) ($m->attachments ?? []));
    }

    /** @param  array<int, mixed>  $attachments  the inbound attachment list as the channel adapters normalize it */
    public function matchesContent(string $body, array $attachments = []): bool
    {
        foreach ($attachments as $a) {
            if (! is_array($a) || ! self::isSticker($a)) {
                return false; // a photo, a voice note, a file: something to look at
            }
        }

        $text = trim($body);

        if ($text === '') {
            return $attachments !== []; // a sticker or a like on its own
        }

        if (preg_match('/[?؟]/u', $text) === 1) {
            return false;
        }

        $words = $this->words($text);

        return $words === [] || $this->onlyThanks($words); // emoji / punctuation only, or thanks words
    }

    /** @return list<string> the normalized words, emoji and punctuation dropped */
    private function words(string $text): array
    {
        $t = $this->normalizer->normalize($text);
        $t = (string) preg_replace('/[^\p{L}\p{N}\s]+/u', ' ', $t);
        $t = (string) preg_replace('/([a-z])\1{2,}/u', '$1', $t); // «thanksss», «okkk»

        return preg_split('/\s+/u', trim($t), -1, PREG_SPLIT_NO_EMPTY) ?: [];
    }

    /** @param  list<string>  $words */
    private function onlyThanks(array $words): bool
    {
        [$phrases, $fillers] = $this->lists();
        $single = [];

        foreach ($phrases as $p) {
            if (count($p) === 1) {
                $single[$p[0]] = true;
            }
        }

        $thanked = false;

        for ($i = 0, $n = count($words); $i < $n;) {
            foreach ($phrases as $p) {
                if (array_slice($words, $i, count($p)) === $p) {
                    $i += count($p);
                    $thanked = true;

                    continue 2;
                }
            }

            $w = $words[$i];

            // «وشكرا», «وتسلمي»: the «و» written onto the thanks word.
            if (mb_strlen($w) > 2 && str_starts_with($w, 'و') && isset($single[mb_substr($w, 1)])) {
                $i++;
                $thanked = true;

                continue;
            }

            if (! isset($fillers[$w])) {
                return false;
            }

            $i++;
        }

        return $thanked;
    }

    /** @return array{0: list<list<string>>, 1: array<string, true>} */
    private function lists(): array
    {
        if ($this->lists !== null) {
            return $this->lists;
        }

        $phrases = collect((array) config('crm.queue.acknowledgements.phrases', []))
            ->map(fn ($p) => $this->words((string) $p))
            ->filter()
            ->unique(fn (array $p) => implode(' ', $p))
            ->sortByDesc(fn (array $p) => count($p))
            ->values()
            ->all();

        $fillers = collect((array) config('crm.queue.acknowledgements.fillers', []))
            ->flatMap(fn ($f) => $this->words((string) $f))
            ->mapWithKeys(fn (string $w) => [$w => true])
            ->all();

        return $this->lists = [$phrases, $fillers];
    }

    /**
     * A sticker as our channels deliver it: `type: sticker` (Messenger / Instagram once normalized,
     * WhatsApp), a Meta `payload.sticker_id`, or a top-level `sticker_id`.
     *
     * @param  array<string, mixed>  $a
     */
    private static function isSticker(array $a): bool
    {
        return ($a['type'] ?? null) === 'sticker' || ! empty($a['payload']['sticker_id'] ?? null) || ! empty($a['sticker_id'] ?? null);
    }
}
