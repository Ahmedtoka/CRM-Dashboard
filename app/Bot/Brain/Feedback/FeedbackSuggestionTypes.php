<?php

namespace App\Bot\Brain\Feedback;

use App\Bot\Agent\StoreAgent;
use App\Models\BotKnowledgeEntry;
use App\Models\BotSuggestion;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * The two learning suggestion types born from staff feedback (b2, 2026-10-19), validated and
 * applied by the same approval as the nightly ideas («التعلم»):
 *
 * - `agent_instruction`: one line added to the owner's instructions for the agent
 *   (`agent_instructions` knowledge entry) — the «قاعدة» of the brief; the old rule editor is gone.
 *   target `agent_instructions`, proposed `{line}`.
 * - `eval_case`: a scenario `php artisan bot:eval` checks from then on (bot_eval_cases).
 *   proposed `{messages: list<string>, expect: {handover?: bool, says?: list<string>, never?: list<string>}}`.
 */
class FeedbackSuggestionTypes
{
    public const TYPES = ['agent_instruction', 'eval_case'];

    public const INSTRUCTION_MAX = 400;

    public function validate(string $type, ?string $target, array $proposed): ?string
    {
        return match ($type) {
            'agent_instruction' => $this->validateInstruction($target, $proposed),
            'eval_case' => $this->validateEvalCase($proposed),
            default => 'نوع اقتراح غير معروف.',
        };
    }

    public function apply(BotSuggestion $s, User $by): void
    {
        match ($s->type) {
            'agent_instruction' => $this->applyInstruction($s),
            'eval_case' => $this->applyEvalCase($s, $by),
        };
    }

    /** The «قبل» side: the instructions as they are now. */
    public static function currentInstructions(): ?array
    {
        $body = BotKnowledgeEntry::query()->where('key', StoreAgent::INSTRUCTIONS_KEY)->value('body');

        return $body !== null ? ['body' => (string) $body] : null;
    }

    private function validateInstruction(?string $target, array $proposed): ?string
    {
        if ($target !== StoreAgent::INSTRUCTIONS_KEY) {
            return 'التعليمة لازم تكون لتعليمات البوت.';
        }

        $line = trim((string) ($proposed['line'] ?? ''));

        if (mb_strlen($line) < 5 || mb_strlen($line) > self::INSTRUCTION_MAX) {
            return 'التعليمة لازم تكون من 5 لـ '.self::INSTRUCTION_MAX.' حرف.';
        }

        return null;
    }

    private function validateEvalCase(array $proposed): ?string
    {
        $messages = $proposed['messages'] ?? null;

        if (! is_array($messages) || $messages === [] || count($messages) > 5
            || collect($messages)->contains(fn ($m) => ! is_string($m) || trim($m) === '' || mb_strlen($m) > 1000)) {
            return 'السيناريو محتاج من رسالة لـ 5 رسايل.';
        }

        $expect = $proposed['expect'] ?? null;

        if (! is_array($expect) || array_diff(array_keys($expect), ['handover', 'says', 'never']) !== []) {
            return 'المتوقع في السيناريو مش مفهوم.';
        }

        if (array_key_exists('handover', $expect) && ! is_bool($expect['handover'])) {
            return 'المتوقع في السيناريو مش مفهوم.';
        }

        foreach (['says', 'never'] as $key) {
            if (array_key_exists($key, $expect) && (! is_array($expect[$key]) || collect($expect[$key])->contains(fn ($v) => ! is_string($v) || trim($v) === ''))) {
                return 'المتوقع في السيناريو مش مفهوم.';
            }
        }

        if (! array_key_exists('handover', $expect) && empty($expect['says']) && empty($expect['never'])) {
            return 'السيناريو محتاج حاجة واحدة على الأقل يتأكد منها.';
        }

        return null;
    }

    private function applyInstruction(BotSuggestion $s): void
    {
        $line = trim((string) $s->proposed['line']);
        $entry = BotKnowledgeEntry::query()->firstOrNew(['key' => StoreAgent::INSTRUCTIONS_KEY], [
            'title' => 'تعليمات البوت', 'body' => '', 'is_active' => true, 'is_template' => false, 'sort' => 0,
        ]);
        $body = rtrim((string) $entry->body);
        $entry->body = ltrim($body."\n- ".$line);
        $entry->save();
    }

    private function applyEvalCase(BotSuggestion $s, User $by): void
    {
        DB::table('bot_eval_cases')->insert([
            'feedback_id' => $s->evidence['feedback_id'] ?? null,
            'category' => 'feedback',
            'messages' => json_encode(array_values($s->proposed['messages']), JSON_UNESCAPED_UNICODE),
            'expect' => json_encode($s->proposed['expect'], JSON_UNESCAPED_UNICODE),
            'is_active' => true,
            'created_by' => $by->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
