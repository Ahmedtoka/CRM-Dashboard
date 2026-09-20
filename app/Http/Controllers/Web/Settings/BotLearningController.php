<?php

namespace App\Http\Controllers\Web\Settings;

use App\Bot\Learning\LearningCurrentState;
use App\Bot\Learning\LearningOutcome;
use App\Bot\Learning\LearningScope;
use App\Bot\Learning\SuggestionApplier;
use App\Bot\Learning\SuggestionValidator;
use App\Bot\Learning\TranscriptBuilder;
use App\Http\Controllers\Concerns\RespondsWithData;
use App\Http\Controllers\Controller;
use App\Models\BotFlow;
use App\Models\BotIntent;
use App\Models\BotKnowledgeEntry;
use App\Models\BotLearningNote;
use App\Models\BotLearningReport;
use App\Models\BotSuggestion;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\Response as HttpResponse;
use Throwable;

/**
 * "تعلم البوت" (design §6, Task 9): the nightly reports and the suggestions
 * waiting for the owner. Nothing the review proposed is live until it is
 * approved here — and a `flow_step` approval only writes the flow's draft,
 * which still has to be published in مصمم الفلوهات.
 */
class BotLearningController extends Controller
{
    use RespondsWithData;

    private const REPORTS = 14;

    /** Inline reviews the run button may do before building the report. */
    private const RUN_BACKFILL = 10;

    public function index(Request $request): Response
    {
        $reports = BotLearningReport::query()
            ->withCount(['suggestions as pending_count' => fn ($q) => $q->where('status', 'pending')])
            ->orderByDesc('report_date')
            ->limit(self::REPORTS)
            ->get(['id', 'report_date', 'summary', 'stats', 'model', 'input_tokens', 'output_tokens']);

        // A report older than the 14 listed can still be opened by id from a link.
        $requested = (int) $request->query('report');
        $selected = $requested > 0
            ? BotLearningReport::query()->find($requested)
            : $reports->first();

        // Learning on the team's test links (design 2026-09-21 §5): the page can show
        // only what came from real customers, or only what the team's runs produced.
        $source = in_array($request->query('source'), BotLearningNote::SOURCES, true)
            ? (string) $request->query('source')
            : null;

        [$today, $notes] = $this->today($source);

        return Inertia::render('settings/BotLearning', [
            'reports' => $reports,
            'report' => $selected ? $this->reportPayload($selected, $source) : null,
            'today' => $today,
            'todayNotes' => $notes,
            'source' => $source,
            // Arabic names for the intent keys a report's stats list (top_intents).
            'intentLabels' => BotIntent::query()->pluck('label_ar', 'key'),
        ]);
    }

    /**
     * Learning v2 §2 "cost controls": today's (Cairo) per-conversation reviews
     * for the header chips, and their notes flattened for the "ملاحظات النهارده"
     * tab — each note carries its conversation id for the inbox link.
     *
     * @return array{0: array{reviewed:int, notes:int, cost_usd:float, cap:int}, 1: list<array<string, mixed>>}
     */
    private function today(?string $source = null): array
    {
        [$from, $to] = app(TranscriptBuilder::class)->window(CarbonImmutable::now(TranscriptBuilder::TZ));

        $all = BotLearningNote::query()
            ->with('channelAccount:id,name')
            ->whereIn('channel_account_id', LearningScope::channelAccountIds())
            ->where('created_at', '>=', $from)->where('created_at', '<', $to)
            ->orderByDesc('id')
            ->get();

        $rows = $source === null ? $all : $all->where('source', $source)->values();

        $notes = [];

        foreach ($rows as $row) {
            foreach ((array) $row->notes as $i => $note) {
                if (! is_array($note)) {
                    continue;
                }

                $notes[] = [
                    'id' => "{$row->id}-{$i}",
                    'conversation_id' => $row->conversation_id,
                    'source' => (string) ($row->source ?: BotLearningNote::SOURCE_LIVE),
                    'channel_account' => $row->channelAccount?->name,
                    'kind' => (string) ($note['kind'] ?? ''),
                    'summary' => (string) ($note['summary'] ?? ''),
                    'quote' => (string) ($note['quote'] ?? ''),
                    'agent_answer' => isset($note['agent_answer']) ? (string) $note['agent_answer'] : null,
                    'created_at' => $row->created_at?->toIso8601String(),
                ];
            }
        }

        return [[
            'reviewed' => $rows->count(),
            'notes' => count($notes),
            'cost_usd' => round((float) $rows->sum('cost_usd'), 4),
            'cap' => (int) config('crm.learning.max_reviews_per_day', 200),
            'live' => $all->where('source', BotLearningNote::SOURCE_LIVE)->count(),
            'test' => $all->where('source', BotLearningNote::SOURCE_TEST)->count(),
        ], $notes];
    }

    /**
     * The "تشغيل التعلم الآن" button: reviews today, not yesterday. The command
     * runs in this process and publishes a `LearningOutcome`, so the page can
     * tell a written report from a quiet day from a failed analyst — a failure
     * must never toast success.
     */
    public function run(Request $request): HttpResponse
    {
        $date = CarbonImmutable::now(TranscriptBuilder::TZ)->toDateString();

        // A web request cannot wait for 30 inline reviews; the nightly run backfills the rest.
        $exitCode = Artisan::call('bot:learn', ['--date' => $date, '--backfill' => self::RUN_BACKFILL]);
        $output = trim(Artisan::output());

        $outcome = app()->bound(LearningOutcome::class)
            ? app(LearningOutcome::class)
            : new LearningOutcome(
                $exitCode === 0 ? LearningOutcome::SKIPPED : LearningOutcome::FAILED,
                $date,
                message: $output,
            );

        if ($outcome->status === LearningOutcome::FAILED || $exitCode !== 0) {
            return response()->json([
                'message' => 'المراجعة فشلت: '.($outcome->message ?: $output ?: 'خطأ غير معروف'),
            ], HttpResponse::HTTP_UNPROCESSABLE_ENTITY);
        }

        return $this->done($request, $outcome->toArray() + ['output' => $output]);
    }

    public function approve(Request $request, BotSuggestion $suggestion, SuggestionApplier $applier): HttpResponse
    {
        try {
            $applier->apply($suggestion, $request->user());
        } catch (Throwable $e) {
            // The applier already recorded the reason and left the row pending;
            // an unexpected throwable is a 422 here, never a 500 on the page.
            return response()->json([
                'message' => $e instanceof DomainException ? $e->getMessage() : 'التطبيق فشل: '.$e->getMessage(),
            ], HttpResponse::HTTP_UNPROCESSABLE_ENTITY);
        }

        return $this->done($request, $this->suggestionPayload($suggestion->fresh()));
    }

    public function reject(Request $request, BotSuggestion $suggestion): HttpResponse
    {
        if ($suggestion->status !== 'pending') {
            return response()->json(['message' => 'الاقتراح ده اتقرر فيه قبل كده.'], HttpResponse::HTTP_UNPROCESSABLE_ENTITY);
        }

        $suggestion->update([
            'status' => 'rejected',
            'decided_by_id' => $request->user()->id,
            'decided_at' => now(),
        ]);

        return $this->done($request, $this->suggestionPayload($suggestion->fresh()));
    }

    private function reportPayload(BotLearningReport $report, ?string $source = null): array
    {
        return array_merge($report->toArray(), [
            'suggestions' => $report->suggestions()
                ->when($source !== null, fn ($q) => $q->where('source', $source))
                ->with('decidedBy:id,name')
                ->orderByRaw("case when status = 'pending' then 0 else 1 end")
                ->orderBy('id')
                ->get()
                ->map(fn (BotSuggestion $s) => $this->suggestionPayload($s))
                ->all(),
        ]);
    }

    /** `current` is read live, so a card never compares against a stale copy of the catalog. */
    private function suggestionPayload(BotSuggestion $suggestion): array
    {
        return array_merge($suggestion->toArray(), [
            'current' => LearningCurrentState::snapshot($suggestion->type, $suggestion->target) ?? $suggestion->current,
            'target_label' => $this->targetLabel($suggestion->type, $suggestion->target),
        ]);
    }

    /** The target in words (script title, intent name, flow title › step) instead of its raw key; null when unknown. */
    private function targetLabel(string $type, ?string $target): ?string
    {
        if ($target === null || $target === '') {
            return null;
        }

        return match ($type) {
            'script_text' => BotKnowledgeEntry::query()->where('key', SuggestionValidator::scriptKey($target))->value('title'),
            'intent_keywords' => BotIntent::query()->where('key', $target)->value('label_ar'),
            'flow_step' => ($parts = SuggestionValidator::splitFlowTarget($target)) !== null
                ? (($title = BotFlow::query()->where('key', $parts[0])->value('title_ar')) !== null ? $title.' › '.$parts[1] : null)
                : null,
            default => null,
        };
    }
}
