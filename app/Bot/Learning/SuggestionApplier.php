<?php

namespace App\Bot\Learning;

use App\Bot\Flows\FlowDefinition;
use App\Bot\Flows\FlowDrafts;
use App\Models\BotFlow;
use App\Models\BotIntent;
use App\Models\BotKnowledgeEntry;
use App\Models\BotSuggestion;
use App\Models\User;
use DomainException;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Writes an approved suggestion (design §6). Everything happens in one
 * transaction: the suggestion is re-validated against the live catalog, the
 * change is written, and only then is the row stamped approved/applied.
 *
 * A `flow_step` suggestion never touches a published flow — it is saved to
 * the flow's DRAFT through `FlowDrafts::saveDraft`, so the owner still has
 * to review and publish it in مصمم الفلوهات. It may only change a step's
 * `text` and its options' `title`s, and the result must still pass
 * `FlowDefinition::validate`.
 *
 * On any failure — a stale catalog or an unexpected throwable — the row stays
 * `pending` and carries the reason in `error`, and the throwable is rethrown
 * so the caller can answer 422 with that same reason.
 */
final class SuggestionApplier
{
    public function __construct(
        private readonly SuggestionValidator $validator,
        private readonly FlowDrafts $drafts,
    ) {}

    /** @throws Throwable a DomainException when the suggestion no longer applies, the original throwable otherwise */
    public function apply(BotSuggestion $suggestion, User $by): void
    {
        try {
            DB::transaction(function () use ($suggestion, $by) {
                if ($suggestion->status !== 'pending') {
                    throw new DomainException('الاقتراح ده اتقرر فيه قبل كده.');
                }

                $error = $this->validator->validate([
                    'type' => $suggestion->type,
                    'target' => $suggestion->target,
                    'proposed' => $suggestion->proposed,
                ]);

                if ($error !== null) {
                    throw new DomainException($error);
                }

                match ($suggestion->type) {
                    'script_text' => $this->applyScriptText($suggestion),
                    'new_faq' => $this->applyNewFaq($suggestion),
                    'intent_keywords' => $this->applyIntentKeywords($suggestion),
                    'flow_step' => $this->applyFlowStep($suggestion, $by),
                };

                $suggestion->forceFill([
                    'status' => 'approved',
                    'decided_by_id' => $by->id,
                    'decided_at' => now(),
                    'applied_at' => now(),
                    'error' => null,
                ])->save();
            });
        } catch (Throwable $e) {
            // Anything at all — a stale catalog (DomainException) or a genuine
            // surprise like a vanished row or a DB error. The transaction rolled
            // back, so the row is pending again: re-read it and record why,
            // without reviving anything set above. The caller turns this into a
            // 422 with the same reason instead of a 500.
            if (! $e instanceof DomainException) {
                report($e);
            }

            $suggestion->refresh();

            if ($suggestion->status === 'pending') {
                $suggestion->forceFill(['error' => $e->getMessage()])->save();
            }

            throw $e;
        }
    }

    private function applyScriptText(BotSuggestion $suggestion): void
    {
        BotKnowledgeEntry::query()
            ->where('key', SuggestionValidator::scriptKey((string) $suggestion->target))
            ->firstOrFail()
            ->fill(['body' => trim((string) $suggestion->proposed['body'])])
            ->save();
    }

    private function applyNewFaq(BotSuggestion $suggestion): void
    {
        $proposed = $suggestion->proposed;
        $key = (string) $proposed['key'];

        BotKnowledgeEntry::create([
            'key' => SuggestionValidator::scriptKey($key),
            'title' => trim((string) $proposed['title']),
            'body' => trim((string) $proposed['body']),
            'is_active' => true,
            'is_template' => false,
            'sort' => ((int) BotKnowledgeEntry::query()->max('sort')) + 10,
        ]);

        BotIntent::create([
            'key' => $key,
            'group' => 'general',
            'label_ar' => trim((string) $proposed['title']),
            'label_en' => $key,
            'route' => 'answer',
            'priority' => 'low',
            'queue' => null,
            'script_keys' => [$key],
            'required_details' => [],
            'keywords' => $this->cleanList($proposed['keywords'] ?? []),
            'is_active' => true,
            'sort' => ((int) BotIntent::query()->max('sort')) + 10,
        ]);
    }

    private function applyIntentKeywords(BotSuggestion $suggestion): void
    {
        $intent = BotIntent::query()->where('key', $suggestion->target)->firstOrFail();

        $merged = $intent->keywords ?? [];

        foreach ($this->cleanList($suggestion->proposed['add'] ?? []) as $keyword) {
            if (! in_array($keyword, $merged, true)) {
                $merged[] = $keyword;
            }
        }

        $intent->fill(['keywords' => array_values($merged)])->save();
    }

    private function applyFlowStep(BotSuggestion $suggestion, User $by): void
    {
        [$flowKey, $stepId] = SuggestionValidator::splitFlowTarget($suggestion->target);

        $flow = BotFlow::query()->where('key', $flowKey)->firstOrFail();
        $definition = $this->drafts->draftFor($flow);
        $proposed = $suggestion->proposed;

        if (array_key_exists('text', $proposed)) {
            $definition['steps'][$stepId]['text'] = trim((string) $proposed['text']);
        }

        foreach ($proposed['options'] ?? [] as $option) {
            $definition['steps'][$stepId]['options'][$option['index']]['title'] = trim((string) $option['title']);
        }

        $errors = FlowDefinition::validate($definition);

        if ($errors !== []) {
            throw new DomainException('الفلو بقى مش صالح بعد التعديل: '.$errors[0]);
        }

        $this->drafts->saveDraft($flow, $definition, $by);
    }

    /** @return list<string> */
    private function cleanList(mixed $values): array
    {
        $clean = [];

        foreach (is_array($values) ? $values : [] as $value) {
            if (is_string($value) && trim($value) !== '' && ! in_array(trim($value), $clean, true)) {
                $clean[] = trim($value);
            }
        }

        return $clean;
    }
}
