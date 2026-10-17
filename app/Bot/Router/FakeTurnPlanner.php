<?php

namespace App\Bot\Router;

use App\Bot\ArabicNormalizer;
use App\Bot\Flows\EntityExtractor;
use App\Bot\Flows\Returns\ReturnPrefill;

/**
 * The planner without a model (bot-hybrid.md §3.3 fallback): keywords for each task kind, the
 * deterministic return reader (ReturnPrefill) for returns and exchanges, simple mood words. Used
 * when there is no API key, when the Haiku planner fails or is late, and by `bot:eval` in its
 * offline mode. Never invents an order number: it only reads hers.
 */
class FakeTurnPlanner implements TurnPlanner
{
    /** @var array<string, list<string>> kind => normalized phrases */
    private const KINDS = [
        'cancel' => ['الغي', 'الغاء', 'cancel'],
        'edit' => ['عدلي', 'تعديل', 'اعدل', 'اغير العنوان', 'غيري العنوان', 'ضيفي قطعه', 'اضيف قطعه'],
        'complaint' => ['اشتكي', 'شكوي', 'شكوه', 'complaint'],
        'order_status' => ['الاوردر فين', 'فين الاوردر', 'اوردري فين', 'وصل فين', 'الاوردر بتاعي', 'اتاخر', 'feen', 'tracking', 'تتبع'],
        'shipping_fee' => ['الشحن بكام', 'بكام الشحن', 'الشحن ل', 'مصاريف الشحن', 'التوصيل بكام', 'الشحن كام', 'بتوصلوا'],
        'branches' => ['فرع', 'فروع', 'branch'],
        'policy' => ['الشحن بياخد', 'بياخد كام يوم', 'ينفع ارجع', 'سياسه', 'انستاباي', 'فودافون كاش', 'الدفع', 'فاتحين', 'مواعيد', 'بينكمش', 'القماش', 'مقاسات'],
        'product' => ['بكام', 'عندكم', 'عايزه اطلب', 'السعر', 'متاح', 'مقاس'],
        'human' => ['اكلم حد', 'عايزه اكلم', 'موظف', 'حد يرد'],
    ];

    private const ANGRY = ['زفت', 'نصب', 'حرامي', 'فضيحه', 'هشتكي', 'حقي', 'محدش بيرد', 'اخركوا', 'اخركم', 'مهزله', 'وحش', '!!'];

    private const NEGATIVE = ['اتاخر', 'مشكله', 'زعلانه', 'مش كويس', 'مستنيه'];

    private const POLICY_EDGE = ['اتخصمت مرتين', 'اتخصم مرتين', 'ناقص', 'ناقصه', 'غلط', 'من 20 يوم', 'من ٢٠ يوم', 'عدي 14', 'عليها خصم', 'الاسترداد', 'فلوسي'];

    public function __construct(private readonly ArabicNormalizer $normalizer = new ArabicNormalizer) {}

    public function model(): ?string
    {
        return null;
    }

    public function plan(array $history, array $burst, array $state): TaskPlan
    {
        $text = implode("\n", $burst);
        $norm = $this->normalizer->normalize($this->normalizer->digitsToLatin($text));
        $refs = EntityExtractor::orderRefs($text);
        $tasks = [];

        $return = ReturnPrefill::fromText($text);

        if ($return['request_kind'] !== null) {
            $kind = $return['request_kind'] === 'mixed' ? 'return' : $return['request_kind'];
            $tasks[] = $this->task($kind, $return['order_refs'], array_map(fn ($h) => ['text' => $h['text'], 'qty' => null, 'action' => $h['action'], 'exchange_to' => $h['exchange_to']], $return['item_hints']));
        }

        foreach (self::KINDS as $kind => $phrases) {
            if ($this->has($norm, $phrases) && ! collect($tasks)->contains(fn ($t) => $t['kind'] === $kind)) {
                // A product/policy word inside a return or a question already planned adds nothing.
                if (in_array($kind, ['product', 'policy'], true) && $tasks !== [] && ! preg_match('/[?؟].*[?؟]|\sو(?:كمان|بكام|بتوصلوا|في|فين)\s/u', $norm)) {
                    continue;
                }

                $tasks[] = $this->task($kind, in_array($kind, ['cancel', 'edit', 'complaint', 'order_status'], true) ? $refs : [], [], $text);
            }
        }

        if ($tasks === []) {
            $tasks = [$this->task('other', [], [], $text, 0.5)];
        }

        $angry = $this->has($norm, self::ANGRY);

        return TaskPlan::fromArray([
            'tasks' => $tasks,
            'sentiment' => $angry ? 'angry' : ($this->has($norm, self::NEGATIVE) ? 'negative' : 'neutral'),
            'wants_human' => collect($tasks)->contains(fn ($t) => $t['kind'] === 'human'),
            'continues_previous' => false,
            'policy_edge' => $this->has($norm, self::POLICY_EDGE),
            'clarify' => null,
        ], $text, 'keywords');
    }

    /** @param  list<string>  $refs */
    private function task(string $kind, array $refs, array $items, ?string $question = null, float $confidence = 0.85): array
    {
        return ['kind' => $kind, 'order_refs' => $refs, 'items' => $items, 'governorate' => null, 'place' => null, 'product_query' => null, 'question' => $question, 'confidence' => $confidence];
    }

    /** @param  list<string>  $phrases */
    private function has(string $norm, array $phrases): bool
    {
        foreach ($phrases as $p) {
            $n = $this->normalizer->normalize($p);

            if ($n !== '' && str_contains($norm, $n)) {
                return true;
            }
        }

        return false;
    }
}
