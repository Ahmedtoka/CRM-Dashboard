<?php

namespace App\Bot\Flows;

use App\Models\BotFlow;
use App\Models\BotKnowledgeEntry;

/**
 * Validates a `bot_flows.definition` array (Task 3, extended by the flow
 * designer Task 1). The engine that walks these flows is a later task — this
 * only checks the shape is well formed: every step has a known type, every
 * `next` / `branches[].next` / option `next` points at a real step or "end",
 * each type's required fields (field, options, case_type, script) are
 * present, step ids are well formed, and an optional `layout` (designer
 * node positions) only names existing steps with numeric coordinates.
 *
 * `validateReferences()` and `warnings()` are separate from `validate()`:
 * the former hits the database (menu/script targets) so it is not run by
 * plain unit tests without one, and the latter never blocks publishing.
 */
final class FlowDefinition
{
    private const STEP_ID_PATTERN = '/^[a-z0-9_]{1,40}$/';
    public const TYPES = [
        'menu', 'choice', 'text', 'name', 'phone', 'photo', 'order', 'branch',
        'branches_list', 'status', 'summary', 'record_case', 'script', 'handover', 'end',
    ];

    /** Step types that must carry a non-empty `field`. */
    private const FIELD_REQUIRED_TYPES = ['choice', 'text', 'name', 'phone', 'photo', 'order', 'branch'];

    private const CASE_TYPES = ['return_exchange', 'complaint', 'cancel_edit', 'delivery_followup'];

    /** @return list<string> error messages; empty means the definition is valid */
    public static function validate(array $def): array
    {
        $errors = [];

        $steps = $def['steps'] ?? null;
        if (! is_array($steps) || $steps === []) {
            $errors[] = "definition must have a non-empty 'steps' map";

            return $errors;
        }

        $start = $def['start'] ?? null;
        if (! is_string($start) || $start === '') {
            $errors[] = "definition must have a 'start' step key";
        } elseif (! array_key_exists($start, $steps)) {
            $errors[] = "start step '{$start}' not found in steps";
        }

        $stepKeys = array_keys($steps);

        foreach ($steps as $stepKey => $step) {
            $stepKey = (string) $stepKey;

            if (! preg_match(self::STEP_ID_PATTERN, $stepKey)) {
                $errors[] = "step id '{$stepKey}' must match ".self::STEP_ID_PATTERN;
            } elseif ($stepKey === 'end') {
                $errors[] = "step id 'end' is reserved";
            }

            if (! is_array($step)) {
                $errors[] = "step '{$stepKey}' must be an array";

                continue;
            }

            $errors = [...$errors, ...self::validateStep($stepKey, $step, $stepKeys)];
        }

        if (array_key_exists('layout', $def)) {
            $errors = [...$errors, ...self::validateLayout($def['layout'], $stepKeys)];
        }

        return $errors;
    }

    /** @return list<string> reference errors: menu/script targets checked against the database */
    public static function validateReferences(array $def): array
    {
        $errors = [];

        $steps = $def['steps'] ?? null;
        if (! is_array($steps)) {
            return $errors;
        }

        foreach ($steps as $stepKey => $step) {
            if (! is_array($step)) {
                continue;
            }

            if (($step['type'] ?? null) === 'script' && self::nonEmptyString($step['script'] ?? null) && ! self::scriptExists($step['script'])) {
                $errors[] = "step '{$stepKey}' references unknown script '{$step['script']}'";
            }

            if (! is_array($step['options'] ?? null)) {
                continue;
            }

            foreach ($step['options'] as $i => $option) {
                if (! is_array($option) || ! self::nonEmptyString($option['action'] ?? null)) {
                    continue;
                }

                $action = $option['action'];

                if (str_starts_with($action, 'flow:') || str_starts_with($action, 'menu:')) {
                    $key = substr($action, (int) strpos($action, ':') + 1);
                    if (! self::flowExists($key)) {
                        $errors[] = "step '{$stepKey}' option #{$i} references unknown flow '{$key}'";
                    }
                } elseif (str_starts_with($action, 'script:')) {
                    $key = substr($action, strlen('script:'));
                    if (! self::scriptExists($key)) {
                        $errors[] = "step '{$stepKey}' option #{$i} references unknown script '{$key}'";
                    }
                }
            }
        }

        return $errors;
    }

    /**
     * Menu buttons that open a flow which exists but is inactive (ruling
     * R-F9): live they are hidden, so the owner is warned. Hits the database;
     * never blocks publishing. Missing flows are `validateReferences` errors.
     *
     * @return list<string>
     */
    public static function referenceWarnings(array $def): array
    {
        $steps = $def['steps'] ?? null;
        if (! is_array($steps)) {
            return [];
        }

        $warnings = [];

        foreach ($steps as $step) {
            if (! is_array($step) || ($step['type'] ?? null) !== 'menu' || ! is_array($step['options'] ?? null)) {
                continue;
            }

            foreach ($step['options'] as $option) {
                $action = is_array($option) ? ($option['action'] ?? null) : null;

                if (! is_string($action) || ! (str_starts_with($action, 'flow:') || str_starts_with($action, 'menu:'))) {
                    continue;
                }

                $key = substr($action, (int) strpos($action, ':') + 1);

                if ($key !== '' && BotFlow::query()->where('key', $key)->where('is_active', false)->exists()) {
                    $title = is_string($option['title'] ?? null) ? $option['title'] : '';
                    $warnings[] = "الزرار «{$title}» بيوديكي لفلو مش شغال: {$key}";
                }
            }
        }

        return $warnings;
    }

    /**
     * The step that holds this definition's menu options (flow designer
     * ruling R-F3): the `start` step when its type is `menu`, else the
     * first step with type `menu`; null when the definition has no menu
     * step at all. Used instead of hardcoding a `menu` step id, since a
     * flow's designer is free to name it anything.
     */
    public static function menuStepKey(array $def): ?string
    {
        $steps = $def['steps'] ?? null;
        if (! is_array($steps)) {
            return null;
        }

        $start = $def['start'] ?? null;
        if (is_string($start) && is_array($steps[$start] ?? null) && ($steps[$start]['type'] ?? null) === 'menu') {
            return $start;
        }

        foreach ($steps as $stepKey => $step) {
            if (is_array($step) && ($step['type'] ?? null) === 'menu') {
                return (string) $stepKey;
            }
        }

        return null;
    }

    /**
     * Unreachable-step warnings (never block publishing): BFS from `start`
     * over `next`, every `branches[].next`, and any option `next` present
     * (menu options normally carry `action`, not `next`, so they correctly
     * do not extend reachability within the flow).
     *
     * @return list<string>
     */
    public static function warnings(array $def): array
    {
        $steps = $def['steps'] ?? null;
        $start = $def['start'] ?? null;
        if (! is_array($steps) || $steps === [] || ! is_string($start) || ! array_key_exists($start, $steps)) {
            return [];
        }

        $reachable = [$start => true];
        $queue = [$start];

        while ($queue !== []) {
            $current = array_shift($queue);
            $step = $steps[$current] ?? null;
            if (! is_array($step)) {
                continue;
            }

            foreach (self::targetsOf($step) as $target) {
                if ($target === 'end' || ! array_key_exists($target, $steps) || isset($reachable[$target])) {
                    continue;
                }

                $reachable[$target] = true;
                $queue[] = $target;
            }
        }

        $warnings = [];
        foreach (array_keys($steps) as $stepKey) {
            if (! isset($reachable[$stepKey])) {
                $warnings[] = "الخطوة {$stepKey} مش متوصلة بأي خطوة قبلها";
            }
        }

        return $warnings;
    }

    /** @return list<string> */
    private static function targetsOf(array $step): array
    {
        $targets = [];

        if (is_string($step['next'] ?? null)) {
            $targets[] = $step['next'];
        }

        foreach ($step['branches'] ?? [] as $branch) {
            if (is_array($branch) && is_string($branch['next'] ?? null)) {
                $targets[] = $branch['next'];
            }
        }

        foreach ($step['options'] ?? [] as $option) {
            if (is_array($option) && is_string($option['next'] ?? null)) {
                $targets[] = $option['next'];
            }
        }

        return $targets;
    }

    private static function flowExists(string $key): bool
    {
        return BotFlow::query()->where('key', $key)->exists();
    }

    private static function scriptExists(string $key): bool
    {
        return BotKnowledgeEntry::query()->where('key', 'script.'.$key)->exists();
    }

    /** @param  list<string>  $stepKeys @return list<string> */
    private static function validateLayout(mixed $layout, array $stepKeys): array
    {
        if (! is_array($layout)) {
            return ["'layout' must be an array"];
        }

        $errors = [];

        foreach ($layout as $stepKey => $pos) {
            if (! in_array($stepKey, $stepKeys, true)) {
                $errors[] = "layout references unknown step '{$stepKey}'";

                continue;
            }

            if (! is_array($pos) || ! is_numeric($pos['x'] ?? null) || ! is_numeric($pos['y'] ?? null)) {
                $errors[] = "layout for step '{$stepKey}' must have numeric x and y";
            }
        }

        return $errors;
    }

    /** @param  list<string>  $stepKeys @return list<string> */
    private static function validateStep(string $stepKey, array $step, array $stepKeys): array
    {
        $errors = [];

        $type = $step['type'] ?? null;
        if (! is_string($type) || ! in_array($type, self::TYPES, true)) {
            $errors[] = "step '{$stepKey}' has an unknown type";

            return $errors;
        }

        if (array_key_exists('next', $step)) {
            $errors = [...$errors, ...self::validateTarget($stepKey, 'next', $step['next'], $stepKeys)];
        }

        if (array_key_exists('branches', $step)) {
            $errors = [...$errors, ...self::validateBranches($stepKey, $step['branches'], $stepKeys)];
        }

        if (in_array($type, self::FIELD_REQUIRED_TYPES, true) && ! self::nonEmptyString($step['field'] ?? null)) {
            $errors[] = "step '{$stepKey}' of type '{$type}' requires a 'field'";
        }

        if ($type === 'record_case') {
            $caseType = $step['case_type'] ?? null;
            if (! is_string($caseType) || ! in_array($caseType, self::CASE_TYPES, true)) {
                $errors[] = "step '{$stepKey}' requires a 'case_type' in ".implode(',', self::CASE_TYPES);
            }
        }

        if ($type === 'script' && ! self::nonEmptyString($step['script'] ?? null)) {
            $errors[] = "step '{$stepKey}' of type 'script' requires a 'script' key";
        }

        if (in_array($type, ['menu', 'choice'], true)) {
            $errors = [...$errors, ...self::validateOptions($stepKey, $type, $step['options'] ?? null, $stepKeys)];
        }

        return $errors;
    }

    /** @param  list<string>  $stepKeys @return list<string> */
    private static function validateBranches(string $stepKey, mixed $branches, array $stepKeys): array
    {
        $errors = [];

        if (! is_array($branches)) {
            $errors[] = "step '{$stepKey}' has 'branches' that is not an array";

            return $errors;
        }

        foreach ($branches as $i => $branch) {
            if (! is_array($branch)) {
                $errors[] = "step '{$stepKey}' branch #{$i} must be an array";

                continue;
            }

            if (! self::nonEmptyString($branch['field'] ?? null)) {
                $errors[] = "step '{$stepKey}' branch #{$i} requires a 'field'";
            }

            if (! is_array($branch['in'] ?? null)) {
                $errors[] = "step '{$stepKey}' branch #{$i} requires an 'in' array";
            }

            $errors = [...$errors, ...self::validateTarget($stepKey, "branch #{$i} next", $branch['next'] ?? null, $stepKeys)];
        }

        return $errors;
    }

    /** @param  list<string>  $stepKeys @return list<string> */
    private static function validateOptions(string $stepKey, string $type, mixed $options, array $stepKeys): array
    {
        $errors = [];

        if (! is_array($options) || count($options) < 1 || count($options) > 13) {
            $errors[] = "step '{$stepKey}' of type '{$type}' must have between 1 and 13 options";

            return $errors;
        }

        $seenValues = [];

        foreach ($options as $i => $option) {
            if (! is_array($option)) {
                $errors[] = "step '{$stepKey}' option #{$i} must be an array";

                continue;
            }

            $title = $option['title'] ?? null;
            if (! is_string($title) || $title === '' || mb_strlen($title) > 20) {
                $errors[] = "step '{$stepKey}' option #{$i} requires a 'title' of at most 20 characters";
            }

            if ($type === 'choice') {
                $value = $option['value'] ?? null;

                if (! is_string($value) || trim($value) === '') {
                    $errors[] = "step '{$stepKey}' option #{$i} requires a 'value'";
                } elseif (isset($seenValues[$value])) {
                    $errors[] = "step '{$stepKey}' option #{$i} repeats the value '{$value}'";
                } else {
                    $seenValues[$value] = true;
                }
            }

            if ($type === 'menu' && ! self::nonEmptyString($option['action'] ?? null)) {
                $errors[] = "step '{$stepKey}' option #{$i} requires an 'action'";
            }

            if (array_key_exists('synonyms', $option)) {
                $synonyms = $option['synonyms'];
                if (! is_array($synonyms) || array_filter($synonyms, static fn ($s) => ! is_string($s)) !== []) {
                    $errors[] = "step '{$stepKey}' option #{$i} 'synonyms' must be an array of strings";
                }
            }

            if (array_key_exists('next', $option)) {
                $errors = [...$errors, ...self::validateTarget($stepKey, "option #{$i} next", $option['next'], $stepKeys)];
            }
        }

        return $errors;
    }

    /** @param  list<string>  $stepKeys @return list<string> */
    private static function validateTarget(string $stepKey, string $label, mixed $target, array $stepKeys): array
    {
        if (! is_string($target) || $target === '') {
            return ["step '{$stepKey}' {$label} must be a non-empty string"];
        }

        if ($target === 'end' || in_array($target, $stepKeys, true)) {
            return [];
        }

        return ["step '{$stepKey}' {$label} references unknown step '{$target}'"];
    }

    private static function nonEmptyString(mixed $value): bool
    {
        return is_string($value) && $value !== '';
    }
}
