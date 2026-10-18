<?php

namespace App\Bot\Brain\Feedback;

use App\Bot\Agent\StoreAgent;
use App\Bot\Learning\LearningCurrentState;
use App\Bot\Learning\SuggestionValidator;
use App\Models\BotLearningReport;
use App\Models\BotReplyFeedback;
use App\Models\BotSuggestion;
use App\Models\User;
use DomainException;
use Illuminate\Support\Facades\DB;

/**
 * «مراجعة ردود البوت» (b2): an admin turns one staff feedback item into a pending learning
 * suggestion — new knowledge (`new_faq`), an instruction for the agent (`agent_instruction`) or an
 * eval scenario (`eval_case`). Nothing changes for the bot until it is approved in «التعلم», by the
 * same approval as the nightly ideas.
 */
class FeedbackConverter
{
    public function __construct(private readonly SuggestionValidator $validator) {}

    /**
     * @param  'knowledge'|'instruction'|'eval'  $kind
     * @param  array<string, mixed>  $input  knowledge: title, body, keywords[]; instruction: line; eval: messages[], expect{}
     *
     * @throws DomainException with the validator's Arabic reason
     */
    public function convert(BotReplyFeedback $f, string $kind, array $input, User $by): BotSuggestion
    {
        if ($f->status !== 'open') {
            throw new DomainException('الملاحظة دي اتراجعت قبل كده.');
        }

        [$type, $target, $proposed, $reason] = match ($kind) {
            'knowledge' => ['new_faq', 'fb_'.$f->id, [
                'key' => 'fb_'.$f->id,
                'title' => trim((string) ($input['title'] ?? '')),
                'body' => trim((string) ($input['body'] ?? '')),
                'keywords' => array_values(array_filter(array_map(fn ($k) => trim((string) $k), (array) ($input['keywords'] ?? [])))),
            ], 'معلومة جديدة من ملاحظة على رد البوت'],
            'instruction' => ['agent_instruction', StoreAgent::INSTRUCTIONS_KEY, ['line' => trim((string) ($input['line'] ?? ''))], 'تعليمة جديدة من ملاحظة على رد البوت'],
            'eval' => ['eval_case', null, [
                'messages' => array_values(array_filter(array_map(fn ($m) => trim((string) $m), (array) ($input['messages'] ?? [])))),
                'expect' => $this->expect((array) ($input['expect'] ?? [])),
            ], 'سيناريو اختبار من ملاحظة على رد البوت'],
        };

        if (($error = $this->validator->validate(['type' => $type, 'target' => $target, 'proposed' => $proposed])) !== null) {
            throw new DomainException($error);
        }

        return DB::transaction(function () use ($f, $kind, $type, $target, $proposed, $reason, $by) {
            $report = BotLearningReport::query()->firstOrCreate(
                ['report_date' => now('Africa/Cairo')->toDateString()],
                ['summary' => '', 'stats' => []],
            );

            $suggestion = BotSuggestion::create([
                'report_id' => $report->id,
                'type' => $type,
                'source' => BotSuggestion::SOURCE_FEEDBACK,
                'target' => $target,
                'current' => LearningCurrentState::snapshot($type, $target),
                'proposed' => $proposed,
                'reason' => $reason.' (من '.($f->user?->name ?? 'الفريق').')',
                'evidence' => ['conversation_ids' => [$f->conversation_id], 'feedback_id' => $f->id, 'message_id' => $f->message_id],
                'status' => 'pending',
            ]);

            $f->forceFill(['status' => 'converted', 'converted_to' => $kind, 'suggestion_id' => $suggestion->id, 'reviewed_by' => $by->id, 'reviewed_at' => now()])->save();

            return $suggestion;
        });
    }

    public function dismiss(BotReplyFeedback $f, User $by): void
    {
        $f->forceFill(['status' => 'dismissed', 'reviewed_by' => $by->id, 'reviewed_at' => now()])->save();
    }

    /** @return array<string, mixed> only the checks the admin filled in */
    private function expect(array $raw): array
    {
        $expect = [];

        if (array_key_exists('handover', $raw) && $raw['handover'] !== null && $raw['handover'] !== '') {
            $expect['handover'] = filter_var($raw['handover'], FILTER_VALIDATE_BOOLEAN);
        }

        foreach (['says', 'never'] as $key) {
            $values = array_values(array_filter(array_map(fn ($v) => trim((string) $v), (array) ($raw[$key] ?? []))));

            if ($values !== []) {
                $expect[$key] = $values;
            }
        }

        return $expect;
    }
}
