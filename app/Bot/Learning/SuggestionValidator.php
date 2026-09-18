<?php

namespace App\Bot\Learning;

use App\Bot\Flows\FlowDrafts;
use App\Models\BotFlow;
use App\Models\BotIntent;
use App\Models\BotKnowledgeEntry;

/**
 * The gate every suggestion passes twice (design §6): once when `bot:learn`
 * stores it, once when `SuggestionApplier` is about to write it — the
 * catalog can change in between, so a suggestion that was valid last night
 * can be stale by the time the owner opens the page.
 *
 * `validate()` answers null when the suggestion may be applied, otherwise a
 * short Arabic reason the page shows on the card. Not final: tests stub it to
 * reach `SuggestionApplier`'s unexpected-failure path.
 */
class SuggestionValidator
{
    private const SCRIPT_PREFIX = 'script.';

    private const FAQ_KEY_PATTERN = '/^[a-z0-9_]{3,40}$/';

    /** A flow option title is a Meta quick-reply title: 20 characters, hard limit. */
    private const MAX_OPTION_TITLE = 20;

    /** Only these two keys of a step may be touched (task ruling). */
    private const FLOW_STEP_ALLOWED_KEYS = ['text', 'options'];

    public function __construct(private readonly FlowDrafts $drafts) {}

    /**
     * @param  array{type?: mixed, target?: mixed, proposed?: mixed}  $suggestion
     */
    public function validate(array $suggestion): ?string
    {
        $type = $suggestion['type'] ?? null;
        $target = is_string($suggestion['target'] ?? null) ? trim($suggestion['target']) : null;
        $proposed = $suggestion['proposed'] ?? null;

        if (! is_string($type) || ! in_array($type, ['script_text', 'new_faq', 'intent_keywords', 'flow_step'], true)) {
            return 'نوع اقتراح غير معروف.';
        }

        if (! is_array($proposed) || $proposed === []) {
            return 'الاقتراح مفيهوش تعديل مقترح.';
        }

        return match ($type) {
            'script_text' => $this->validateScriptText($target, $proposed),
            'new_faq' => $this->validateNewFaq($proposed),
            'intent_keywords' => $this->validateIntentKeywords($target, $proposed),
            'flow_step' => $this->validateFlowStep($target, $proposed),
        };
    }

    /** `return_policy` and `script.return_policy` both name the same entry. */
    public static function scriptKey(string $target): string
    {
        return str_starts_with($target, self::SCRIPT_PREFIX) ? $target : self::SCRIPT_PREFIX.$target;
    }

    /** @return array{0: string, 1: string}|null the flow key and step id of a `flow.step` target */
    public static function splitFlowTarget(?string $target): ?array
    {
        if ($target === null || ! str_contains($target, '.')) {
            return null;
        }

        $position = strrpos($target, '.');
        $flowKey = substr($target, 0, $position);
        $stepId = substr($target, $position + 1);

        return $flowKey === '' || $stepId === '' ? null : [$flowKey, $stepId];
    }

    private function validateScriptText(?string $target, array $proposed): ?string
    {
        if ($target === null || $target === '') {
            return 'الاقتراح مش محدد أي رد.';
        }

        if (! BotKnowledgeEntry::query()->where('key', self::scriptKey($target))->exists()) {
            return "الرد «{$target}» مش موجود.";
        }

        return $this->nonEmpty($proposed['body'] ?? null) ? null : 'النص المقترح فاضي.';
    }

    private function validateNewFaq(array $proposed): ?string
    {
        $key = $proposed['key'] ?? null;

        if (! is_string($key) || ! preg_match(self::FAQ_KEY_PATTERN, $key)) {
            return 'مفتاح السؤال الجديد غير صالح.';
        }

        if (! $this->nonEmpty($proposed['title'] ?? null) || mb_strlen(trim($proposed['title'])) > 120) {
            return 'عنوان السؤال الجديد فاضي أو أطول من 120 حرف.';
        }

        if (! $this->nonEmpty($proposed['body'] ?? null)) {
            return 'نص الرد الجديد فاضي.';
        }

        $keywords = $proposed['keywords'] ?? null;

        if (! is_array($keywords) || $this->cleanList($keywords) === []) {
            return 'السؤال الجديد من غير كلمات مفتاحية.';
        }

        if (BotKnowledgeEntry::query()->where('key', self::scriptKey($key))->exists()) {
            return "في رد بنفس المفتاح «{$key}» بالفعل.";
        }

        if (BotIntent::query()->where('key', $key)->exists()) {
            return "في نية بنفس المفتاح «{$key}» بالفعل.";
        }

        return null;
    }

    private function validateIntentKeywords(?string $target, array $proposed): ?string
    {
        if ($target === null || $target === '') {
            return 'الاقتراح مش محدد أي نية.';
        }

        if (! BotIntent::query()->where('key', $target)->exists()) {
            return "النية «{$target}» مش موجودة.";
        }

        $add = $proposed['add'] ?? null;

        if (! is_array($add) || $this->cleanList($add) === []) {
            return 'مفيش صيغ جديدة للإضافة.';
        }

        return null;
    }

    private function validateFlowStep(?string $target, array $proposed): ?string
    {
        $parts = self::splitFlowTarget($target);

        if ($parts === null) {
            return 'هدف الخطوة لازم يكون flow_key.step_id.';
        }

        [$flowKey, $stepId] = $parts;

        $flow = BotFlow::query()->where('key', $flowKey)->first();

        if (! $flow) {
            return "الفلو «{$flowKey}» مش موجود.";
        }

        $step = $this->drafts->draftFor($flow)['steps'][$stepId] ?? null;

        if (! is_array($step)) {
            return "الخطوة «{$stepId}» مش موجودة في الفلو «{$flowKey}».";
        }

        $extra = array_diff(array_keys($proposed), self::FLOW_STEP_ALLOWED_KEYS);

        if ($extra !== []) {
            return 'اقتراح الخطوة مسموح له يغيّر النص وعناوين الأزرار بس.';
        }

        if (array_key_exists('text', $proposed) && ! $this->nonEmpty($proposed['text'])) {
            return 'نص الخطوة المقترح فاضي.';
        }

        if (array_key_exists('options', $proposed)) {
            $error = $this->validateOptions($proposed['options'], is_array($step['options'] ?? null) ? $step['options'] : []);

            if ($error !== null) {
                return $error;
            }
        }

        return array_key_exists('text', $proposed) || array_key_exists('options', $proposed)
            ? null
            : 'اقتراح الخطوة مفيهوش نص ولا عناوين.';
    }

    private function validateOptions(mixed $options, array $current): ?string
    {
        if (! is_array($options) || $options === []) {
            return 'قائمة الأزرار المقترحة فاضية.';
        }

        foreach ($options as $option) {
            if (! is_array($option) || ! is_int($option['index'] ?? null) || ! $this->nonEmpty($option['title'] ?? null)) {
                return 'كل زرار لازم يكون فيه index وعنوان.';
            }

            if (! array_key_exists($option['index'], $current)) {
                return "الزرار رقم {$option['index']} مش موجود في الخطوة.";
            }

            if (mb_strlen(trim($option['title'])) > self::MAX_OPTION_TITLE) {
                return 'عنوان الزرار لازم يكون 20 حرف بالكتير.';
            }
        }

        return null;
    }

    private function nonEmpty(mixed $value): bool
    {
        return is_string($value) && trim($value) !== '';
    }

    /** @return list<string> */
    private function cleanList(array $values): array
    {
        $clean = [];

        foreach ($values as $value) {
            if (is_string($value) && trim($value) !== '') {
                $clean[] = trim($value);
            }
        }

        return array_values(array_unique($clean));
    }
}
