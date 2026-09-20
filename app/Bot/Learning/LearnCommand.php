<?php

namespace App\Bot\Learning;

use App\Enums\SenderType;
use App\Models\BotFlow;
use App\Models\BotIntent;
use App\Models\BotKnowledgeEntry;
use App\Models\BotLearningNote;
use App\Models\BotLearningReport;
use App\Models\BotSuggestion;
use App\Models\ChannelAccount;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\SupportCase;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * The nightly review (design §6, learning v2 §2): one Claude call over a Cairo
 * day of per-conversation notes (real conversations only) proposes at most 15
 * improvements. Nothing is applied — the
 * report and its suggestions wait for the owner on /settings/bot-learning.
 *
 * Re-running a day replaces that day's still-pending suggestions, so the
 * owner never sees two versions of the same idea; already decided ones stay.
 */
class LearnCommand extends Command
{
    protected $signature = 'bot:learn
        {--date= : the Cairo day to review (Y-m-d), yesterday by default}
        {--backfill= : how many unreviewed conversations to review inline first (default crm.learning.backfill, 30)}';

    protected $description = 'Turn a day of conversation notes into bot improvements for the owner to approve';

    public const MAX_SUGGESTIONS = 15;

    public const MAX_TRANSCRIPTS = 60;

    /** The catalog shares the prompt with the notes, so a long script is cut too. */
    public const MAX_SCRIPT_CHARS = 400;

    /** The notes list sent to the analyst stays under this many characters. */
    public const MAX_NOTE_CHARS = 60000;

    /** Learning-worthy kinds first, so a character budget cut drops the least useful notes. */
    private const KIND_ORDER = ['agent_knowledge', 'unanswered', 'wrong_answer', 'new_phrasing', 'flow_friction'];

    public function handle(TranscriptBuilder $transcripts, LearningAnalyst $analyst, SuggestionValidator $validator, ConversationReview $review, ConversationReviewer $reviewer): int
    {
        $date = $this->date();
        [$from, $to] = $transcripts->window($date);

        // Learning v2 §2 backfill: real conversations of the day that no review
        // covered yet (first deploy, a failed job) are reviewed inline first.
        $backfilled = $this->backfill($date, $transcripts, $review, $reviewer);

        $existing = BotLearningReport::query()->whereDate('report_date', $date->toDateString())->value('id');

        $rows = BotLearningNote::query()
            ->whereIn('channel_account_id', LearningScope::channelAccountIds())
            ->where(function ($q) use ($from, $to, $backfilled, $existing) {
                $q->where(fn ($q) => $q->whereNull('used_in_report_id')->where('created_at', '>=', $from)->where('created_at', '<', $to))
                    ->orWhereIn('id', $backfilled === [] ? [0] : $backfilled);

                // A re-run of the same day sees the notes its report already used.
                if ($existing !== null) {
                    $q->orWhere('used_in_report_id', $existing);
                }
            })
            ->orderBy('id')
            ->get();

        $notes = $this->groupNotes($rows);

        if ($notes === []) {
            $this->info("No notes for {$date->toDateString()} — nothing to review.");

            return $this->outcome(new LearningOutcome(LearningOutcome::SKIPPED, $date->toDateString()), self::SUCCESS);
        }

        try {
            $result = $analyst->analyze($notes, $this->catalog());
        } catch (Throwable $e) {
            // Design §"Error handling": a failed learning call writes no report.
            report($e);
            $this->error("Learning failed for {$date->toDateString()}: {$e->getMessage()}");

            return $this->outcome(
                new LearningOutcome(LearningOutcome::FAILED, $date->toDateString(), message: $e->getMessage()),
                self::FAILURE,
            );
        }

        // Bug fix (2026-09-17): the analyst throws on a detected truncation, but a
        // response can also come back well-formed and simply empty (no summary,
        // no suggestions) — never worth a report either way.
        if (trim((string) ($result['summary'] ?? '')) === '' && ((array) ($result['suggestions'] ?? [])) === []) {
            report(new \RuntimeException("Empty learning result for {$date->toDateString()}: no summary and no suggestions"));
            $this->error("Learning failed for {$date->toDateString()}: ".ClaudeLearningAnalyst::TRUNCATED_MESSAGE);

            return $this->outcome(
                new LearningOutcome(LearningOutcome::FAILED, $date->toDateString(), message: ClaudeLearningAnalyst::TRUNCATED_MESSAGE),
                self::FAILURE,
            );
        }

        $stats = $this->stats((array) ($result['stats'] ?? []), $from, $to, $rows, $result);

        $report = DB::transaction(function () use ($date, $result, $stats, $rows) {
            $report = $this->saveReport($date, $result, $stats);

            BotLearningNote::query()->whereKey($rows->modelKeys())->update(['used_in_report_id' => $report->id]);

            return $report;
        });

        $stored = $this->saveSuggestions($report, (array) ($result['suggestions'] ?? []), $validator, $this->sourceByConversation($rows));

        $this->info("Report for {$date->toDateString()}: {$stats['conversations_reviewed']} conversations, {$stats['notes']} notes, {$stored} suggestions.");

        return $this->outcome(
            new LearningOutcome(LearningOutcome::CREATED, $date->toDateString(), $report->id, $stats['conversations_reviewed'], $stored),
            self::SUCCESS,
        );
    }

    /**
     * Reviews up to `--backfill` of the day's real conversations that no
     * review covers yet. A failed review is reported and skipped.
     *
     * @return list<int> ids of the note rows written here
     */
    private function backfill(CarbonImmutable $date, TranscriptBuilder $transcripts, ConversationReview $review, ConversationReviewer $reviewer): array
    {
        $option = $this->option('backfill');
        $limit = max(0, (int) (filled($option) ? $option : config('crm.learning.backfill', 30)));

        if ($limit === 0) {
            return [];
        }

        $written = [];

        foreach ($transcripts->conversationIdsForDate($date, self::MAX_TRANSCRIPTS * 5) as $id) {
            if (count($written) >= $limit) {
                break;
            }

            $conversation = Conversation::query()->find($id);

            if ($conversation === null || $review->skipReason($conversation) !== null) {
                continue;
            }

            try {
                $note = $review->review($conversation, $reviewer);
            } catch (Throwable $e) {
                report($e);
                $this->line("  review of conversation {$id} failed — {$e->getMessage()}");

                continue;
            }

            // Null here means the daily cap: no point asking for the rest.
            if ($note === null) {
                break;
            }

            $written[] = $note->id;
        }

        if ($written !== []) {
            $this->line('  backfilled '.count($written).' conversation reviews');
        }

        return $written;
    }

    /**
     * The analyst's input: every note with its conversation id, grouped by
     * kind, the most useful kinds first, under a character budget.
     *
     * @param  Collection<int, BotLearningNote>  $rows
     * @return array<string, list<array{conversation_id:int, summary:string, quote:string, agent_answer?:string}>>
     */
    private function groupNotes(Collection $rows): array
    {
        $byKind = [];

        foreach ($rows as $row) {
            foreach ((array) $row->notes as $note) {
                if (! is_array($note) || ! is_string($note['kind'] ?? null)) {
                    continue;
                }

                $item = [
                    'conversation_id' => (int) $row->conversation_id,
                    'summary' => (string) ($note['summary'] ?? ''),
                    'quote' => (string) ($note['quote'] ?? ''),
                ];

                if ($note['kind'] === 'agent_knowledge' && filled($note['agent_answer'] ?? null)) {
                    $item['agent_answer'] = (string) $note['agent_answer'];
                }

                $byKind[$note['kind']][] = $item;
            }
        }

        $grouped = [];
        $used = 0;

        foreach (self::KIND_ORDER as $kind) {
            foreach ($byKind[$kind] ?? [] as $item) {
                $size = mb_strlen(implode(' ', $item));

                if ($used > 0 && $used + $size > self::MAX_NOTE_CHARS) {
                    break 2;
                }

                $grouped[$kind][] = $item;
                $used += $size;
            }
        }

        return $grouped;
    }

    /** Publishes what this run did for a caller in the same process (the page's run button). */
    private function outcome(LearningOutcome $outcome, int $exitCode): int
    {
        $this->laravel->instance(LearningOutcome::class, $outcome);

        return $exitCode;
    }

    /** The --date option, or yesterday in Cairo (the 02:00 schedule reviews the day that just ended). */
    private function date(): CarbonImmutable
    {
        $option = $this->option('date');

        return filled($option)
            ? CarbonImmutable::parse((string) $option, TranscriptBuilder::TZ)->startOfDay()
            : CarbonImmutable::now(TranscriptBuilder::TZ)->subDay()->startOfDay();
    }

    /**
     * What the bot answers with today: script bodies, intent keywords and the
     * text/option titles of every flow step (draft-free: the published ones).
     *
     * @return array{scripts: array<string, string>, intents: array<string, list<string>>, flows: array<string, array<string, array{text: ?string, options: list<string>}>>}
     */
    private function catalog(): array
    {
        $scripts = [];

        foreach (BotKnowledgeEntry::query()->where('key', 'like', 'script.%')->orderBy('sort')->get(['key', 'body']) as $entry) {
            $body = (string) $entry->body;

            // A few very long scripts must not crowd out the transcripts.
            if (mb_strlen($body) > self::MAX_SCRIPT_CHARS) {
                $body = mb_substr($body, 0, self::MAX_SCRIPT_CHARS).'…';
            }

            $scripts[substr($entry->key, strlen('script.'))] = $body;
        }

        $intents = [];

        foreach (BotIntent::query()->where('is_active', true)->orderBy('sort')->get(['key', 'keywords']) as $intent) {
            $intents[$intent->key] = array_values($intent->keywords ?? []);
        }

        $flows = [];

        foreach (BotFlow::query()->orderBy('id')->get(['key', 'definition']) as $flow) {
            $steps = [];

            foreach ((array) ($flow->definition['steps'] ?? []) as $stepId => $step) {
                if (! is_array($step)) {
                    continue;
                }

                $steps[(string) $stepId] = [
                    'text' => is_string($step['text'] ?? null) ? $step['text'] : null,
                    'options' => array_values(array_map(
                        fn ($option) => is_array($option) && is_string($option['title'] ?? null) ? $option['title'] : '',
                        (array) ($step['options'] ?? []),
                    )),
                ];
            }

            $flows[$flow->key] = $steps;
        }

        return ['scripts' => $scripts, 'intents' => $intents, 'flows' => $flows];
    }

    private function saveReport(CarbonImmutable $date, array $result, array $stats): BotLearningReport
    {
        return BotLearningReport::updateOrCreate(
            ['report_date' => $date->toDateString()],
            [
                'summary' => (string) ($result['summary'] ?? ''),
                'stats' => $stats,
                'model' => (string) ($result['model'] ?? ''),
                'input_tokens' => (int) ($result['input_tokens'] ?? 0),
                'output_tokens' => (int) ($result['output_tokens'] ?? 0),
            ],
        );
    }

    /**
     * Every count is made here, not taken from the model: conversations
     * reviewed, notes, how many real conversations a human agent answered,
     * the support cases the day produced, which pages the reviewed
     * conversations came from and what the day cost (the reviews plus this
     * call). `top_intents` stays the model's read of the day.
     *
     * @param  array<string, mixed>  $reported
     * @param  Collection<int, BotLearningNote>  $rows
     * @return array<string, mixed>
     */
    private function stats(array $reported, CarbonImmutable $from, CarbonImmutable $to, Collection $rows, array $result): array
    {
        $handovers = Message::query()
            ->where('created_at', '>=', $from)->where('created_at', '<', $to)
            ->where('sender_type', SenderType::User->value)
            ->whereIn('conversation_id', LearningScope::conversations()->select('id'))
            ->distinct()
            ->count('conversation_id');

        $cases = SupportCase::query()
            ->where('created_at', '>=', $from)->where('created_at', '<', $to)
            ->whereIn('conversation_id', LearningScope::conversations()->select('id'))
            ->count();

        $reviewed = $rows->pluck('conversation_id')->filter()->unique();

        $names = ChannelAccount::query()->whereKey($rows->pluck('channel_account_id')->filter()->unique()->values()->all())->pluck('name', 'id');

        $sources = $rows->filter(fn (BotLearningNote $row) => $row->channel_account_id !== null)
            ->groupBy('channel_account_id')
            ->map(fn ($group, $accountId) => [
                'channel_account_id' => (int) $accountId,
                'name' => (string) ($names[$accountId] ?? ''),
                'count' => $group->pluck('conversation_id')->filter()->unique()->count(),
            ])
            ->sortByDesc('count')
            ->values()
            ->all();

        // How much of the day came from real customers and how much from the team's own
        // test links (design 2026-09-21 §5) — the nightly report says so in words.
        $bySource = [
            BotLearningNote::SOURCE_LIVE => $rows->where('source', BotLearningNote::SOURCE_LIVE)->pluck('conversation_id')->filter()->unique()->count(),
            BotLearningNote::SOURCE_TEST => $rows->where('source', BotLearningNote::SOURCE_TEST)->pluck('conversation_id')->filter()->unique()->count(),
        ];

        $analystCost = ConversationReview::cost(
            (string) ($result['model'] ?? ''),
            (int) ($result['input_tokens'] ?? 0),
            (int) ($result['output_tokens'] ?? 0),
        );

        return array_merge(array_intersect_key($reported, ['top_intents' => true]), [
            'conversations' => $reviewed->count(),
            'conversations_reviewed' => $reviewed->count(),
            'notes' => (int) $rows->sum(fn (BotLearningNote $row) => count((array) $row->notes)),
            'handovers' => $handovers,
            'cases' => $cases,
            'sources' => $sources,
            'by_source' => $bySource,
            'cost_usd' => round((float) $rows->sum('cost_usd') + $analystCost, 4),
        ]);
    }

    /** @return int how many suggestions were stored */
    private function saveSuggestions(BotLearningReport $report, array $suggestions, SuggestionValidator $validator, array $sourceByConversation): int
    {
        return DB::transaction(function () use ($report, $suggestions, $validator, $sourceByConversation) {
            // A re-run of the same day replaces its pending ideas; decided ones stay.
            $report->suggestions()->where('status', 'pending')->delete();

            $stored = 0;
            $seen = [];

            foreach ($suggestions as $suggestion) {
                if ($stored >= self::MAX_SUGGESTIONS) {
                    break;
                }

                if (! is_array($suggestion)) {
                    continue;
                }

                $error = $validator->validate($suggestion);

                if ($error !== null) {
                    $this->line('  skipped '.json_encode($suggestion['type'] ?? '?')." — {$error}");

                    continue;
                }

                $type = (string) $suggestion['type'];
                $target = $this->targetOf($type, $suggestion);
                $fingerprint = $type.'|'.$target;

                if (isset($seen[$fingerprint]) || $this->hasPending($type, $target)) {
                    continue;
                }

                $seen[$fingerprint] = true;

                BotSuggestion::create([
                    'report_id' => $report->id,
                    'type' => $type,
                    'source' => self::suggestionSource($suggestion['evidence'] ?? null, $sourceByConversation),
                    'target' => $target,
                    'current' => $this->currentOf($type, $target),
                    'proposed' => (array) $suggestion['proposed'],
                    'reason' => is_string($suggestion['reason'] ?? null) ? $suggestion['reason'] : null,
                    'evidence' => is_array($suggestion['evidence'] ?? null) ? $suggestion['evidence'] : null,
                    'status' => 'pending',
                ]);

                $stored++;
            }

            return $stored;
        });
    }

    /** A new_faq has no existing target, so its proposed key stands in — that is what dedupes re-runs. */
    private function targetOf(string $type, array $suggestion): string
    {
        if ($type === 'new_faq') {
            return (string) ($suggestion['proposed']['key'] ?? '');
        }

        return trim((string) ($suggestion['target'] ?? ''));
    }

    private function hasPending(string $type, string $target): bool
    {
        return BotSuggestion::query()
            ->where('status', 'pending')
            ->where('type', $type)
            ->where('target', $target)
            ->exists();
    }

    /** The "before" side, snapshotted so the card still shows something if the row later disappears. */
    private function currentOf(string $type, string $target): ?array
    {
        return LearningCurrentState::snapshot($type, $target);
    }

    /**
     * Which conversation each reviewed episode came from, live or test.
     *
     * @param  Collection<int, BotLearningNote>  $rows
     * @return array<int, string>
     */
    private function sourceByConversation(Collection $rows): array
    {
        $map = [];

        foreach ($rows as $row) {
            if ($row->conversation_id !== null) {
                $map[(int) $row->conversation_id] = (string) ($row->source ?: BotLearningNote::SOURCE_LIVE);
            }
        }

        return $map;
    }

    /**
     * A suggestion is a `test` one only when every conversation it cites is a team
     * test run (design 2026-09-21 §5); anything grounded in a real customer, or in
     * nothing identifiable, counts as `live` so the owner never mistakes it for
     * something only the team saw.
     *
     * @param  array<int, string>  $sourceByConversation
     */
    private static function suggestionSource(mixed $evidence, array $sourceByConversation): string
    {
        $ids = is_array($evidence) ? array_filter(array_map('intval', (array) ($evidence['conversation_ids'] ?? []))) : [];

        if ($ids === []) {
            return BotLearningNote::SOURCE_LIVE;
        }

        foreach ($ids as $id) {
            if (($sourceByConversation[$id] ?? BotLearningNote::SOURCE_LIVE) !== BotLearningNote::SOURCE_TEST) {
                return BotLearningNote::SOURCE_LIVE;
            }
        }

        return BotLearningNote::SOURCE_TEST;
    }
}
