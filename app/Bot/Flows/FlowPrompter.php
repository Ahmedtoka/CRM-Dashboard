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

    /** Human labels for collected data keys, in the order the owner reads them. */
    public const LABELS = [
        'order_number' => 'رقم الأوردر',
        'order_ref_text' => 'بيانات الأوردر',
        'reason' => 'السبب',
        'request' => 'الطلب',
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
        $text = (string) ($step['text'] ?? '');

        return match ($step['type'] ?? null) {
            'menu' => ['text' => $text, 'buttons' => array_map(
                fn (array $o) => ['title' => (string) $o['title'], 'payload' => (string) $o['action']],
                $this->visibleMenuOptions($step),
            )],
            'choice' => ['text' => $text, 'buttons' => $this->choiceButtons($flowKey, $stepKey, $step)],
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

    /** @return list<string> bullet lines for the labelled data, option values shown by their titles */
    public function summaryLines(array $data): array
    {
        $lines = [];

        foreach ($data as $key => $value) {
            $label = self::LABELS[$key] ?? null;

            if ($label === null || $value === null || $value === '' || $value === [] || $value === false) {
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
