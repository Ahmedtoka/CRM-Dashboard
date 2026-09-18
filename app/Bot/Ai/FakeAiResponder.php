<?php

namespace App\Bot\Ai;

use App\Bot\ArabicNormalizer;
use App\Enums\BotIntent;
use App\Enums\CommentIntent;
use Illuminate\Support\Str;

/**
 * Deterministic AI driver used when no Anthropic key is configured: keyword
 * based intent classification plus templated Egyptian replies from real
 * grounding data, so the demo works without an API key (spec §4.3, §5.7).
 */
class FakeAiResponder implements AiResponder, MessageClassifier
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

    public function classifyMessage(string $text): MessageClassification
    {
        $n = $this->normalizer->normalize($text);
        $negative = $this->containsAny($n, ['وحش', 'مشكله', 'زعلانه', 'نصب', 'اتاخر', 'مش كويس']);

        [$intent, $confidence] = match (true) {
            $this->containsAny($n, ['مشكله', 'شكوى', 'نصب', 'اتاخر', 'مرتجع بايظ']) => [BotIntent::Complaint, 0.9],
            $this->containsAny($n, ['الاوردر فين', 'اوردري', 'الطلب وصل', 'رقم الشحنه']) => [BotIntent::OrderStatus, 0.85],
            $this->containsAny($n, ['جدول المقاسات', 'المقاسات']) => [BotIntent::SizeChart, 0.9],
            $this->containsAny($n, ['الشحن', 'التوصيل', 'بيوصل']) => [BotIntent::Shipping, 0.9],
            $this->containsAny($n, ['استبدال', 'استرجاع', 'ارجاع', 'ابدل']) => [BotIntent::ExchangeReturn, 0.9],
            $this->containsAny($n, ['الدفع', 'فيزا', 'كاش', 'انستاباي']) => [BotIntent::Payment, 0.9],
            $this->containsAny($n, ['متاح', 'موجود', 'فيه مقاس', 'الوان']) => [BotIntent::Availability, 0.85],
            $this->containsAny($n, ['بكام', 'سعر', 'كام']) => [BotIntent::Price, 0.9],
            $this->containsAny($n, ['السلام', 'صباح الخير', 'مساء الخير', 'هاي', 'اهلا']) => [BotIntent::Greeting, 0.9],
            default => [BotIntent::Other, 0.4],
        };

        return new MessageClassification($intent, $negative ? 'negative' : 'neutral', $confidence);
    }

    public function reply(array $history, array $catalogLines, string $systemPrompt): AiReply
    {
        $first = $catalogLines[0] ?? null;

        if ($first === null) {
            return new AiReply('handover', '', 'fake');
        }

        if (str_starts_with($first, 'الشحن لـ')) {
            $delivery = collect($catalogLines)->first(fn ($l) => str_starts_with($l, '[مدة التوصيل]'));

            return new AiReply('reply', trim('أهلاً بيكي 🌸 '.$first.($delivery ? '. '.trim(Str::after($delivery, ']')) : '')), 'fake');
        }

        if (str_starts_with($first, '[')) {
            return new AiReply('reply', trim(Str::after($first, ']')), 'fake');
        }

        [$title, $price, $stock] = $this->parseFirstLine($first);

        return new AiReply('reply', 'أهلاً بيكي 🌸 '.$title.' بسعر '.$price.' جنيه.'.($stock !== '' ? ' المتاح: '.$stock : ''), 'fake');
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
     * Reads a grouped catalog line "{title} | {price} جنيه | {colour}: {sizes} | …".
     * For a price range the first number is used; it is present in the grounding.
     *
     * @return array{0: string, 1: string, 2: string}
     */
    private function parseFirstLine(string $line): array
    {
        $parts = array_map('trim', explode('|', $line));

        preg_match('/(\d+(?:\.\d+)?)/u', $parts[1] ?? '', $matches);

        return [$parts[0], $matches[1] ?? '', implode(' | ', array_slice($parts, 2))];
    }
}
