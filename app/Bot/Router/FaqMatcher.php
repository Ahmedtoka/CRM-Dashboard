<?php

namespace App\Bot\Router;

use App\Bot\ArabicNormalizer;
use App\Bot\Flow\IntentCatalog;
use App\Bot\Flows\EntityExtractor;
use App\Bot\Flows\FlowPrompter;
use App\Models\BotIntent;

/**
 * Tier 0 FAQ (bot-hybrid.md §3.2): a short message that asks exactly one standing question —
 * opening hours, payment methods, the return policy… — is answered at once from the owner's
 * approved script, without any model. Only the intents in `crm.bot.router.faq_intents` (facts
 * that never depend on a product, an order or a place), only when no other intent matches, the
 * message has at most `faq_max_words` words, one question at most, no «وكمان» and no order number.
 */
class FaqMatcher
{
    public const DEFAULT_INTENTS = ['branches_hours', 'payment_info', 'return_policy', 'delivery_time', 'international_shipping', 'inspection_on_delivery', 'wholesale', 'careers', 'events', 'b2b_service_offer'];

    public function __construct(
        private readonly IntentCatalog $catalog,
        private readonly FlowPrompter $prompter,
        private readonly ArabicNormalizer $normalizer = new ArabicNormalizer,
    ) {}

    /** @return array{intent: string, reply: string}|null */
    public function match(string $text): ?array
    {
        $norm = $this->normalizer->normalize($this->normalizer->digitsToLatin($text));

        if ($norm === '' || count(preg_split('/\s+/u', trim($norm)) ?: []) > (int) config('crm.bot.router.faq_max_words', 12)
            || preg_match_all('/[?؟]/u', $text) > 1 || preg_match('/(^|\s)و\s?كمان(\s|$)/u', $norm)
            || EntityExtractor::orderRefs($text) !== []) {
            return null;
        }

        $hits = $this->catalog->active()->filter(fn (BotIntent $i) => collect($i->keywords ?? [])->contains(fn ($k) => $this->contains($norm, (string) $k)))->values();

        if ($hits->count() !== 1 || $hits[0]->route !== 'answer' || ! in_array($hits[0]->key, (array) config('crm.bot.router.faq_intents', self::DEFAULT_INTENTS), true)) {
            return null;
        }

        $reply = collect($hits[0]->script_keys ?? [])->map(fn ($k) => $this->prompter->script((string) $k))->filter()->implode("\n");

        return trim($reply) !== '' ? ['intent' => (string) $hits[0]->key, 'reply' => trim($reply)] : null;
    }

    private function contains(string $norm, string $keyword): bool
    {
        $k = $this->normalizer->normalize(trim($keyword));

        if ($k === '') {
            return false;
        }

        // Latin keywords match whole words only ("hi" never matches "this").
        if (preg_match('/^[\x20-\x7E]+$/', $k)) {
            return preg_match('/(?<![a-z0-9])'.preg_quote($k, '/').'(?![a-z0-9])/', $norm) === 1;
        }

        return str_contains($norm, $k);
    }
}
