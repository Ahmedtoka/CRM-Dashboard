<?php

namespace App\Bot\Ai;

use App\Bot\ArabicNormalizer;
use App\Enums\CommentIntent;

/**
 * Deterministic AI driver used when no Anthropic key is configured: keyword
 * based intent classification plus templated Egyptian replies from real
 * catalog data, so the demo works without an API key (spec §5.7).
 */
class FakeAiResponder implements AiResponder
{
    public function __construct(private readonly ArabicNormalizer $normalizer) {}

    public function classify(string $text): Classification
    {
        $normalized = $this->normalizer->normalize($text);

        [$intent, $confidence] = match (true) {
            $this->containsAny($normalized, ['بكام', 'سعر', 'كام']) => [CommentIntent::Question, 0.9],
            $this->containsAny($normalized, ['عايز اطلب', 'اطلب', 'اوردر', 'احجز']) => [CommentIntent::Buy, 0.9],
            $this->containsAny($normalized, ['مشكله', 'وحش', 'اتاخر', 'مرتجع', 'شكوى', 'نصب']) => [CommentIntent::Complaint, 0.9],
            $this->containsAny($normalized, ['http', 'ربح', 'اشتغل من البيت']) => [CommentIntent::Spam, 0.95],
            default => [CommentIntent::Other, 0.4],
        };

        return new Classification($intent, $confidence, false);
    }

    public function reply(array $history, array $catalogLines, string $systemPrompt): AiReply
    {
        if ($catalogLines === []) {
            return new AiReply('handover', '', 'fake');
        }

        [$title, $price] = $this->parseFirstLine($catalogLines[0]);

        $text = 'أهلاً بيكي 🌸 المتاح عندنا: '.$title.' بسعر '.$price.' جنيه. تحبي أحجزلك؟';

        return new AiReply('reply', $text, 'fake');
    }

    /**
     * @param  string[]  $needles
     */
    private function containsAny(string $normalizedHaystack, array $needles): bool
    {
        foreach ($needles as $needle) {
            if (str_contains($normalizedHaystack, $this->normalizer->normalize($needle))) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array{0: string, 1: string}
     */
    private function parseFirstLine(string $line): array
    {
        $title = trim(explode('|', $line)[0]);

        preg_match('/(\d+(?:\.\d+)?)\s*جنيه/u', $line, $matches);

        return [$title, $matches[1] ?? ''];
    }
}
