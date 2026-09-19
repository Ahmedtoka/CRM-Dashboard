<?php

namespace App\Bot\Flows;

use App\Bot\Flow\ScriptPlaceholders;
use App\Bot\Knowledge\KnowledgeBase;

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
    ];

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

        return str_replace('{case_id}', (string) ($data['case_id'] ?? ''), $this->placeholders->render($body));
    }

    /**
     * A step text with the flow placeholders filled from the collected data (2026-09-19):
     * `{customer_first_name}` (the order's customer, set once she proved the order is hers —
     * "يا {customer_first_name}" is dropped when it is unknown), `{order_number}` (without "#";
     * the case number when there is no order), `{exchange_product_title}`, `{case_id}` and
     * `{time_greeting}`.
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

        return $this->placeholders->render(strtr($text, [
            '{customer_first_name}' => $first,
            '{order_number}' => $number !== '' ? $number : (string) ($data['case_id'] ?? ''),
            '{exchange_product_title}' => $product !== '' ? $product : self::UNKNOWN_PRODUCT,
            '{case_id}' => (string) ($data['case_id'] ?? ''),
        ]));
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
