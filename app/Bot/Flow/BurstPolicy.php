<?php

namespace App\Bot\Flow;

use App\Bot\ArabicNormalizer;

/** How long the bot waits after the customer's last message before answering the burst (spec §2.1). */
final class BurstPolicy
{
    // ArabicNormalizer already unifies ة/ه, ى/ي and diacritics, so only one
    // spelling of each hold word is needed here.
    // "هبعتلك" (overnight refinement change 5): a promise to send more.
    // Fix round 1, issue 3: bare "لسه" ("still/not yet") is dropped -- it false-positived
    // on complete complaints like "لسه موصلش الاوردر". Only "لسه هبعت"/"لسه بكتب" ("still
    // about to send"/"still writing") are kept as still-typing signals.
    private const HOLD_WORDS = ['استني', 'ثانيه', 'لحظه', 'wait', 'sec', 'هبعتلك', 'لسه هبعت', 'لسه بكتب'];

    /**
     * Overnight refinement change 5: a message that opens with one of these
     * connector words followed by more text ("و كمان...", "برضو...") is still
     * typing -- she is adding to what she already said, not finishing it.
     * برضو/بردو are both kept: alternate spellings ArabicNormalizer does not unify.
     */
    private const UNFINISHED_CONNECTOR_PREFIXES = ['وكمان', 'بردو', 'برضو', 'و'];

    /** A reply of just one of these words is complete on its own (normalized spellings). */
    private const CONFIRMATION_WORDS = ['تمام', 'اه', 'ايوه', 'ماشي', 'اوكي', 'ok', 'تم'];

    /**
     * Short greetings and thanks are complete on their own (speed fix 2026-09-16): without this
     * the "two words or fewer" rule held "مساء الخير" or "شكرا ليكي" for the full max wait.
     * A message of up to three words that opens with one of these words counts as finished,
     * so variants like "مساء الفل" or "شكرا يا قمر" are covered too. Compared after
     * normalizing both sides and dropping punctuation and emoji.
     */
    private const GREETING_OPENERS = ['السلام', 'سلام', 'مساء', 'صباح', 'اهلا', 'هاي', 'hi', 'hello', 'hey', 'hii'];

    private const THANKS_OPENERS = ['شكرا', 'متشكر', 'متشكرة', 'تسلم', 'تسلمي', 'مرسي', 'ميرسي', 'thanks', 'thank', 'thx'];

    private const GREETING_MAX_WORDS = 3;

    /**
     * Round 4 (2026-10-24): a request with no object yet — «عايزة ارجع», «عايزة اشتكي», «عندي
     * مشكلة» — is the first half of what she is typing (chat 214: the piece and the order came in the
     * next message, 3–10 s later, and the flow had already started empty). A message of up to three
     * words that ENDS with one of these (normalized) waits for the rest.
     */
    private const REQUEST_STUBS = ['ارجع', 'ابدل', 'اشتكي', 'اعرف', 'اسال', 'اغير', 'اعدل', 'الغي', 'اطلب', 'اتابع', 'استبدل', 'استرجع', 'ارجعه', 'ارجعها',
        'عندي مشكله', 'عندي سؤال', 'عندي استفسار', 'ممكن سؤال', 'ممكن استفسار', 'عندي شكوي', 'عندي شكوه', 'في مشكله', 'فيه مشكله'];

    private const REQUEST_STUB_MAX_WORDS = 3;

    /**
     * 'greeting' or 'thanks' when the text is only a short greeting or thanks (no question mark,
     * at most $maxWords words, opening with one of the opener words), else null. TurnRunner uses
     * it with 2 words to answer a lone greeting/thanks without the understanding call.
     */
    public function socialIntent(string $text, int $maxWords = self::GREETING_MAX_WORDS): ?string
    {
        if (preg_match('/[?؟]/u', $text)) {
            return null;
        }

        $norm = $this->normalizer->normalize(trim($this->normalizer->digitsToLatin($text)));
        $bare = trim((string) preg_replace('/\s+/u', ' ', (string) preg_replace('/[^\p{L}\p{N}\s]+/u', '', $norm)));
        $words = preg_split('/\s/u', $bare, -1, PREG_SPLIT_NO_EMPTY);

        // «مساء الفل يا قمر»: a trailing «يا …» is how she addresses us, not more words (bot hybrid #2).
        if (count($words) > 2 && $words[count($words) - 2] === 'يا') {
            $words = array_slice($words, 0, -2);
        }

        if ($words === [] || count($words) > $maxWords) {
            return null;
        }

        foreach (['greeting' => self::GREETING_OPENERS, 'thanks' => self::THANKS_OPENERS] as $intent => $openers) {
            foreach ($openers as $opener) {
                if ($words[0] === $this->normalizer->normalize($opener)) {
                    return $intent;
                }
            }
        }

        return null;
    }

    public function __construct(private readonly ArabicNormalizer $normalizer = new ArabicNormalizer) {}

    public function waitSeconds(array $burstTexts, bool $lastHasAttachment, int $base, int $max): int
    {
        $last = trim((string) end($burstTexts));

        return $this->looksUnfinished($last, $lastHasAttachment) ? max($base, $max) : $base;
    }

    private function looksUnfinished(string $text, bool $attachment): bool
    {
        if ($attachment) {
            return true;
        }

        // Final fix wave I8: an order number, phone, email or a lone "تمام" answers what was asked.
        if ($this->isFinishedShortReply($text)) {
            return false;
        }

        $norm = $this->normalizer->normalize($text);

        foreach (self::HOLD_WORDS as $w) {
            if (str_contains($norm, $w)) {
                return true;
            }
        }

        // Fix round 1, issue 2: a question is a complete thought even when it opens
        // with a connector ("و السعر كام؟") -- the connector rule never overrides that.
        if (! preg_match('/[?؟]$/u', rtrim($text)) && $this->startsWithUnfinishedConnector($norm)) {
            return true;
        }

        if (preg_match('/(\.\.\.|…|،|,|\sو)$/u', $text)) {
            return true;
        }

        if ($this->isRequestStub($norm)) {
            return true;
        }

        // A connector on its own ("و", "وكمان") has more coming.
        foreach (self::UNFINISHED_CONNECTOR_PREFIXES as $prefix) {
            if (trim($norm) === $this->normalizer->normalize($prefix)) {
                return true;
            }
        }

        // Speed (2026-09-16): a short message on its own ("بكام", "عنوان الفرع") is no longer held
        // for the max wait; only the explicit still-typing signals above extend it.
        return false;
    }

    /**
     * The (already normalized) text opens with one of UNFINISHED_CONNECTOR_PREFIXES
     * immediately followed by a word boundary, with more text still after it -- the
     * bare connector alone ("و") or a longer word that happens to start with the same
     * letters ("وحش") does not count.
     */
    private function startsWithUnfinishedConnector(string $normalized): bool
    {
        foreach (self::UNFINISHED_CONNECTOR_PREFIXES as $prefix) {
            $p = $this->normalizer->normalize($prefix);

            if (preg_match('/^'.preg_quote($p, '/').'(?=\s|$)/u', $normalized) !== 1) {
                continue;
            }

            if (trim(mb_substr($normalized, mb_strlen($p))) !== '') {
                return true;
            }
        }

        return false;
    }

    /** «عايزة ارجع» / «عندي مشكلة»: a short request that has not said what it is about yet. */
    private function isRequestStub(string $normalized): bool
    {
        $bare = trim((string) preg_replace('/\s+/u', ' ', (string) preg_replace('/[^\p{L}\p{N}\s]+/u', ' ', $normalized)));
        $words = preg_split('/\s/u', $bare, -1, PREG_SPLIT_NO_EMPTY) ?: [];

        if ($words === [] || count($words) > self::REQUEST_STUB_MAX_WORDS) {
            return false;
        }

        foreach (self::REQUEST_STUBS as $stub) {
            $s = $this->normalizer->normalize($stub);

            if ($bare === $s || str_ends_with($bare, ' '.$s)) {
                return true;
            }
        }

        return false;
    }

    /** Only digits (optional #), a phone, an email, or one known confirmation word. */
    private function isFinishedShortReply(string $text): bool
    {
        $latin = trim($this->normalizer->digitsToLatin($text));

        if ($latin === '') {
            return false;
        }

        if (preg_match('/^#?\s*\d+$/u', $latin) || preg_match('/^\+?[\d \-]{8,16}$/', $latin)) {
            return true;
        }

        if (preg_match('/^[A-Za-z0-9._%+\-]+@[A-Za-z0-9.\-]+\.[A-Za-z]{2,}$/', $latin)) {
            return true;
        }

        $norm = $this->normalizer->normalize($latin);

        if (in_array($norm, self::CONFIRMATION_WORDS, true)) {
            return true;
        }

        if ($this->socialIntent($text) !== null) {
            return true;
        }

        return false;
    }
}
