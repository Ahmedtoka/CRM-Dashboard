<?php

namespace App\Bot\Flows;

use App\Bot\Flow\ScriptPlaceholders;
use App\Bot\Knowledge\KnowledgeBase;
use App\Bot\Language\BotTranslator;
use App\Bot\Language\KeptNames;
use App\Bot\Language\LanguageDetector;

/**
 * Builds what a waiting flow step says: the step text as written (no AI
 * rewrite) plus its buttons, the summary of collected data, and knowledge
 * script bodies with placeholders rendered. Sends nothing itself.
 */
final class FlowPrompter
{
    public const MAIN_MENU_BUTTON = ['title' => 'القائمة الرئيسية', 'payload' => 'menu:main_menu'];

    /** The choice value that means "refund the money": hidden when every picked item is exchange only. */
    public const REFUND_VALUE = 'refund';

    public const EXCHANGE_ONLY_NOTE = 'القطع اللي اخترتيها متاحة للاستبدال بس 🌸';

    /** `{exchange_product_title}` when she sent a photo or a link that did not match a product. */
    public const UNKNOWN_PRODUCT = 'المنتج اللي بعتيه';

    /** Human labels for collected data keys, in the order the owner reads them. */
    public const LABELS = [
        'order_number' => 'رقم الأوردر',
        'order_ref_text' => 'بيانات الأوردر',
        'selected_items' => 'القطع',
        'reason' => 'السبب',
        'request' => 'الطلب',
        'request_kind' => 'نوع الطلب',
        'exchange_product' => 'المنتج البديل',
        'product_photo' => 'صورة المنتج (✅)',
        'defect_photo' => 'صورة العيب (✅)',
        'complaint_type' => 'نوع الشكوى',
        'branch_name' => 'الفرع',
        'visit_date' => 'تاريخ الزيارة',
        'name' => 'الاسم',
        'phone' => 'الموبايل',
        'description' => 'التفاصيل',
        'edit_details' => 'التعديل المطلوب',
        // The owner's cancel/edit flow (2026-09-19).
        'cancel_reason' => 'سبب الإلغاء',
        'edit_kind' => 'التعديل',
        'item_changes' => 'تعديل القطع',
        'new_address' => 'العنوان الجديد',
        'new_phone' => 'الموبايل الجديد',
    ];

    /** Status-card values a step text may use (StatusStep sets them when it is entered). */
    public const ORDER_PLACEHOLDERS = ['order_date', 'order_items', 'order_status', 'order_eta', 'order_tracking'];

    /** A line holding one of these is left out when its value is empty (no window, no tracking). */
    private const LINE_PLACEHOLDERS = ['order_eta', 'order_tracking'];

    /** @var array<string, ?array> definitions already resolved this instance, keyed by flow key */
    private array $resolvedDefinitions = [];

    public function __construct(
        private readonly KnowledgeBase $knowledge,
        private readonly ScriptPlaceholders $placeholders,
        private readonly FlowDefinitionSource $definitions,
    ) {}

    /**
     * The prompt of a waiting step.
     *
     * @return array{text:string, buttons:list<array{title:string, payload:string}>}
     */
    public function prompt(string $flowKey, string $stepKey, array $step, array $data): array
    {
        $text = $this->renderText((string) ($step['text'] ?? ''), $data);

        return match ($step['type'] ?? null) {
            'menu' => ['text' => $text, 'buttons' => array_map(
                fn (array $o) => ['title' => (string) $o['title'], 'payload' => (string) $o['action']],
                $this->visibleMenuOptions($step),
            )],
            'choice' => [
                'text' => $this->refundHidden($step, $data) ? self::EXCHANGE_ONLY_NOTE."\n".$text : $text,
                'buttons' => $this->choiceButtons($flowKey, $stepKey, ['options' => $this->choiceOptions($step, $data)] + $step),
            ],
            'summary' => [
                'text' => trim($text."\n".implode("\n", $this->summaryLines($data))),
                'buttons' => [
                    ['title' => 'تمام، سجل', 'payload' => "step:{$flowKey}:{$stepKey}:confirm"],
                    ['title' => 'عايزة أعدل', 'payload' => "step:{$flowKey}:{$stepKey}:edit"],
                ],
            ],
            default => ['text' => $text, 'buttons' => []],
        };
    }

    /**
     * Menu options minus `script:<key>` actions whose script is inactive or
     * empty (ruling R4), and minus `flow:<key>` / `menu:<key>` actions whose
     * flow is missing or inactive for the current definition source (R-F9).
     *
     * @return list<array<string, mixed>>
     */
    public function visibleMenuOptions(array $step): array
    {
        return array_values(array_filter($step['options'] ?? [], function ($o) {
            $action = (string) ($o['action'] ?? '');

            if (str_starts_with($action, 'script:')) {
                return $this->script(substr($action, 7)) !== null;
            }

            if (str_starts_with($action, 'flow:') || str_starts_with($action, 'menu:')) {
                $key = substr($action, (int) strpos($action, ':') + 1);

                return $key !== '' && $this->definitionFor($key) !== null;
            }

            return true;
        }));
    }

    /**
     * Memoized per instance (this prompter is resolved fresh per request/sandbox
     * run, never shared): a flow's active/definition state cannot change mid-request,
     * so resolving it once per flow key here is safe and drops repeated queries a
     * single menu turn would otherwise run against FlowDefinitionSource.
     */
    private function definitionFor(string $key): ?array
    {
        if (! array_key_exists($key, $this->resolvedDefinitions)) {
            $this->resolvedDefinitions[$key] = $this->definitions->definition($key);
        }

        return $this->resolvedDefinitions[$key];
    }

    /** The rendered active script body, or null when missing, inactive or empty. */
    public function script(string $key, array $data = []): ?string
    {
        $body = trim((string) $this->knowledge->get('script.'.$key)?->body);

        if ($body === '') {
            return null;
        }

        $caseId = is_numeric($data['case_id'] ?? null) && (int) $data['case_id'] > 0 ? (string) (int) $data['case_id'] : '';

        return str_replace('{case_id}', $caseId, $this->placeholders->render($body));
    }

    /**
     * A step text with the flow placeholders filled from the collected data (2026-09-19):
     * `{customer_first_name}` (the order's customer, set once she proved the order is hers —
     * "يا {customer_first_name}" is dropped when it is unknown), `{order_number}` (without "#";
     * the case number when there is no order), `{exchange_product_title}`, `{case_id}` and
     * `{time_greeting}`; the status card's `{order_date}`, `{order_items}`, `{order_status}`,
     * `{order_eta}` and `{order_tracking}` — a line whose `{order_eta}`/`{order_tracking}` is
     * empty is dropped, and " — {order_items}" too when the count is unknown.
     */
    public function renderText(string $text, array $data): string
    {
        if (! str_contains($text, '{')) {
            return $text;
        }

        $first = is_scalar($data['customer_first_name'] ?? null) ? trim((string) $data['customer_first_name']) : '';

        if ($first === '') {
            $text = preg_replace('/\s*يا\s*\{customer_first_name\}/u', '', $text) ?? $text;
        }

        $number = is_scalar($data['order_number'] ?? null) ? ltrim(trim((string) $data['order_number']), '#') : '';
        $product = is_array($data['exchange_product'] ?? null) && is_scalar($data['exchange_product']['title'] ?? null)
            ? trim((string) $data['exchange_product']['title'])
            : '';

        $card = [];

        foreach (self::ORDER_PLACEHOLDERS as $key) {
            $value = is_scalar($data[$key] ?? null) ? trim((string) $data[$key]) : '';
            // The status card's own words («اتأكد وجاري تجهيزه») carry their stored English with
            // them, so the card is one cached text whatever the order's status is (§2).
            $card['{'.$key.'}'] = $value !== '' ? $this->withStoredEnglish($value) : $value;

            if ($value !== '') {
                continue;
            }

            if (in_array($key, self::LINE_PLACEHOLDERS, true)) {
                $text = rtrim(preg_replace('/^[^\n]*\{'.$key.'\}[^\n]*(\n|$)/mu', '', $text) ?? $text);
            } elseif ($key === 'order_items') {
                $text = preg_replace('/\s*[—-]\s*\{order_items\}/u', '', $text) ?? $text;
            }
        }

        // A case number is only shown when there is one: never "#0" (the sandbox's unsaved case, 2026-09-19).
        $caseId = is_numeric($data['case_id'] ?? null) && (int) $data['case_id'] > 0 ? (string) (int) $data['case_id'] : '';

        // Her own name and the product she picked are names, not text to translate
        // (design 2026-09-21 §4) — and registering them keeps «أهلاً يا سارة» and
        // «أهلاً يا منى» a single cached source.
        return $this->placeholders->render(strtr($text, $card + [
            '{customer_first_name}' => KeptNames::keep($first),
            '{order_number}' => $number !== '' ? $number : $caseId,
            '{exchange_product_title}' => $product !== '' ? KeptNames::keep($product) : KeptNames::swap(self::UNKNOWN_PRODUCT, 'the item you sent'),
            '{case_id}' => $caseId,
        ]));
    }

    /** A value that already has a stored English of its own travels with it (KeptNames::swap). */
    private function withStoredEnglish(string $value): string
    {
        $english = app(BotTranslator::class)->cached($value, LanguageDetector::EN);

        return $english !== null ? KeptNames::swap($value, $english) : $value;
    }

    /**
     * One line per piece of a cancel/edit request (the `item_changes` step): "🔁 فستان ليلى (أسود / M) × 1
     * ← عباية كتان — 1,200 ج.م — https://…", "🔁 … ← مقاس/لون جديد: «L»", "❌ شيل: طرحة شيفون × 2".
     *
     * @return list<string>
     */
    public static function changeLines(mixed $changes): array
    {
        $lines = [];

        foreach ((array) $changes as $change) {
            if (! is_array($change) || ! is_scalar($change['title'] ?? null) || trim((string) $change['title']) === '') {
                continue;
            }

            $item = self::itemsText([$change]);

            if (($change['action'] ?? null) === 'remove') {
                $lines[] = '❌ شيل: '.$item;

                continue;
            }

            $product = is_array($change['product'] ?? null) ? $change['product'] : null;
            $typed = is_scalar($change['new_option'] ?? null) ? trim((string) $change['new_option']) : '';

            if ($product !== null && is_scalar($product['title'] ?? null)) {
                $parts = [trim((string) $product['title']).(filled($product['variant_title'] ?? null) ? ' ('.$product['variant_title'].')' : '')];

                if (is_numeric($product['price'] ?? null)) {
                    $parts[] = number_format((float) $product['price'], fmod((float) $product['price'], 1.0) === 0.0 ? 0 : 2).' ج.م';
                }

                if (filled($product['url'] ?? null)) {
                    $parts[] = (string) $product['url'];
                }

                $to = implode(' — ', $parts);
            } elseif ($typed !== '') {
                $to = 'مقاس/لون جديد: «'.$typed.'»';
            } else {
                $to = ! empty($change['photo']) ? 'صورة للمنتج البديل' : 'البديل مش متحدد';
            }

            $lines[] = '🔁 تبديل: '.$item.' ← '.$to;
        }

        return $lines;
    }

    /** @return list<string> bullet lines for the labelled data, option values shown by their titles */
    public function summaryLines(array $data): array
    {
        $lines = [];

        foreach ($data as $key => $value) {
            $label = self::LABELS[$key] ?? null;

            if ($label === null || $value === null || $value === '' || $value === [] || $value === false) {
                continue;
            }

            if ($key === 'exchange_product') {
                if (is_array($value) && is_scalar($value['title'] ?? null)) {
                    $lines[] = '• '.$label.': '.$value['title'];
                }

                continue;
            }

            if ($key === 'selected_items') {
                if (($items = self::itemsText($value)) !== '') {
                    $lines[] = '• '.$label.': '.$items;
                }

                continue;
            }

            if ($key === 'item_changes') {
                foreach (self::changeLines($value) as $line) {
                    $lines[] = '• '.$line;
                }

                continue;
            }

            if (str_contains($label, '✅')) {
                $lines[] = '• '.$label;

                continue;
            }

            $shown = $data[$key.'_title'] ?? $value;

            if (is_scalar($shown)) {
                $lines[] = '• '.$label.': '.$shown;
            }
        }

        return $lines;
    }

    /**
     * A choice step's options for this conversation: the refund option is dropped when every
     * item she picked is exchange only (discounted — spec 2026-09-19 §2).
     *
     * @return list<array<string, mixed>>
     */
    public function choiceOptions(array $step, array $data): array
    {
        $options = array_values($step['options'] ?? []);

        if (! $this->refundHidden($step, $data)) {
            return $options;
        }

        return array_values(array_filter($options, fn ($o) => (string) ($o['value'] ?? '') !== self::REFUND_VALUE));
    }

    private function refundHidden(array $step, array $data): bool
    {
        $items = array_filter((array) ($data['selected_items'] ?? []), 'is_array');
        $options = $step['options'] ?? [];

        return $items !== []
            && collect($items)->every(fn ($i) => ($i['exchange_only'] ?? false) === true)
            && collect($options)->contains(fn ($o) => (string) ($o['value'] ?? '') === self::REFUND_VALUE)
            && collect($options)->contains(fn ($o) => (string) ($o['value'] ?? '') !== self::REFUND_VALUE);
    }

    /** "فستان ليلى (أسود / M) × 1 — استبدال بس، طرحة × 2" */
    public static function itemsText(mixed $items): string
    {
        $parts = [];

        foreach ((array) $items as $i) {
            if (! is_array($i) || ! is_scalar($i['title'] ?? null) || trim((string) $i['title']) === '') {
                continue;
            }

            $variant = is_scalar($i['variant'] ?? null) && trim((string) $i['variant']) !== '' ? ' ('.trim((string) $i['variant']).')' : '';
            $parts[] = trim((string) $i['title']).$variant.' × '.max(1, (int) ($i['qty'] ?? 1)).(($i['exchange_only'] ?? false) === true ? ' — استبدال بس' : '');
        }

        return implode('، ', $parts);
    }

    /** @return list<array{title:string, payload:string}> */
    private function choiceButtons(string $flowKey, string $stepKey, array $step): array
    {
        $options = $step['options'] ?? [];
        $buttons = array_map(
            fn (array $o) => ['title' => (string) $o['title'], 'payload' => "step:{$flowKey}:{$stepKey}:{$o['value']}"],
            array_values($options),
        );

        if (count($options) < 13) {
            $buttons[] = self::MAIN_MENU_BUTTON;
        }

        return $buttons;
    }
}
