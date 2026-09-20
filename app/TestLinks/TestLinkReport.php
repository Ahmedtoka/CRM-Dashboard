<?php

namespace App\TestLinks;

use App\Analytics\ActivityLogger;
use App\Enums\MessageDirection;
use App\Enums\SenderType;
use App\Models\ActivityLog;
use App\Models\BotFlow;
use App\Models\BotTestLink;
use App\Models\BotTestSession;
use App\Models\BotTestSessionStep;
use App\Models\Message;
use App\Models\SupportCase;
use Illuminate\Support\Collection;

/**
 * Reports → «تجربة الفريق» (design 2026-09-21 §4): what each tester did, how far
 * they got through each flow, and where the flows lose people.
 *
 * Everything here reads the rows the test session already left behind — the flow
 * trail (`bot_test_session_steps`), the conversation's messages, its cases and its
 * handovers — so the report never re-runs anything.
 */
class TestLinkReport
{
    public const MAX_SESSIONS = 500;

    public const TRANSCRIPT_LIMIT = 400;

    /** @return list<array<string, mixed>> the links the picker offers */
    public function links(): array
    {
        return BotTestLink::query()
            ->withCount(['sessions as runs_count'])
            ->orderByDesc('id')
            ->get()
            ->map(fn (BotTestLink $l) => [
                'id' => $l->id,
                'label' => $l->label,
                'url' => $l->url(),
                'is_open' => $l->isOpen(),
                'views_count' => (int) $l->views_count,
                'runs_count' => (int) ($l->getAttribute('runs_count') ?? 0),
                'last_opened_at' => $l->last_opened_at?->toIso8601String(),
            ])
            ->all();
    }

    /**
     * The whole page for one link (or, with none, every link's sessions together).
     *
     * @return array{sessions: list<array<string, mixed>>, totals: array<string, mixed>, funnels: list<array<string, mixed>>}
     */
    public function forLink(?BotTestLink $link): array
    {
        $sessions = $this->sessions($link);

        return [
            'sessions' => $sessions,
            'totals' => $this->totals($sessions),
            'funnels' => $this->funnels($sessions),
        ];
    }

    /**
     * One row per run (design §4): who, when, how long, how much, on what, how far.
     *
     * @return list<array<string, mixed>>
     */
    public function sessions(?BotTestLink $link): array
    {
        /** @var Collection<int, BotTestSession> $rows */
        $rows = BotTestSession::query()
            ->with(['link:id,label'])
            ->when($link !== null, fn ($q) => $q->where('bot_test_link_id', $link->id))
            ->orderByDesc('id')
            ->limit(self::MAX_SESSIONS)
            ->get();

        if ($rows->isEmpty()) {
            return [];
        }

        $conversationIds = $rows->pluck('conversation_id')->filter()->values()->all();

        $steps = BotTestSessionStep::query()
            ->whereIn('bot_test_session_id', $rows->modelKeys())
            ->orderBy('id')
            ->get()
            ->groupBy('bot_test_session_id');

        $counts = Message::query()
            ->whereIn('conversation_id', $conversationIds ?: [0])
            ->where('sender_type', '!=', SenderType::System->value)
            ->selectRaw('conversation_id, direction, sender_type, count(*) as n')
            ->groupBy('conversation_id', 'direction', 'sender_type')
            ->get();

        $cases = SupportCase::query()
            ->whereIn('conversation_id', $conversationIds ?: [0])
            ->get(['conversation_id', 'type'])
            ->groupBy('conversation_id');

        $handovers = ActivityLog::query()
            ->whereIn('conversation_id', $conversationIds ?: [0])
            ->where('action', ActivityLogger::CONVERSATION_HANDOVER)
            ->selectRaw('conversation_id, count(*) as n')
            ->groupBy('conversation_id')
            ->pluck('n', 'conversation_id');

        $titles = $this->flowTitles();

        return $rows->map(function (BotTestSession $s) use ($steps, $counts, $cases, $handovers, $titles) {
            $trail = $this->trail($steps->get($s->id));
            $mine = $counts->where('conversation_id', $s->conversation_id);

            return [
                'id' => $s->id,
                'link_id' => $s->bot_test_link_id,
                'link_label' => $s->link?->label,
                'name' => $s->tester_name,
                'run_no' => (int) $s->run_no,
                'label' => $s->label(),
                'conversation_id' => $s->conversation_id,
                'device_family' => $s->device_family ?: 'unknown',
                'started_at' => ($s->started_at ?? $s->created_at)?->toIso8601String(),
                'ended_at' => $s->ended_at?->toIso8601String(),
                'ended_reason' => $s->ended_reason,
                'duration_seconds' => $s->durationSeconds(),
                'messages_in' => (int) $mine->where('direction', MessageDirection::In->value)->sum('n'),
                'messages_out' => (int) $mine->where('direction', MessageDirection::Out->value)->sum('n'),
                'messages_total' => (int) $mine->sum('n'),
                'flows' => array_map(fn (string $key) => ['key' => $key, 'title' => $titles[$key] ?? $key], $trail['flows']),
                'last_flow' => $trail['last_flow'],
                'last_step' => $trail['last_step'],
                'finished' => $trail['finished'],
                'dropped_at' => $trail['dropped_at'],
                'cases' => ($cases->get($s->conversation_id) ?? collect())->count(),
                'case_types' => ($cases->get($s->conversation_id) ?? collect())->pluck('type')->unique()->values()->all(),
                'handovers' => (int) ($handovers[$s->conversation_id] ?? 0),
            ];
        })->values()->all();
    }

    /**
     * How far each flow carried the testers: sessions that reached every step, in the
     * order the flow itself defines, plus the steps that lost the most people.
     *
     * @param  list<array<string, mixed>>  $sessions
     * @return list<array<string, mixed>>
     */
    public function funnels(array $sessions): array
    {
        $sessionIds = array_column($sessions, 'id');

        if ($sessionIds === []) {
            return [];
        }

        $steps = BotTestSessionStep::query()
            ->whereIn('bot_test_session_id', $sessionIds)
            ->orderBy('id')
            ->get();

        if ($steps->isEmpty()) {
            return [];
        }

        $flows = BotFlow::query()->orderBy('id')->get(['key', 'title_ar', 'definition'])->keyBy('key');
        $out = [];

        foreach ($steps->groupBy('flow_key') as $flowKey => $flowSteps) {
            /** @var BotFlow|null $flow */
            $flow = $flows->get($flowKey);
            $order = $this->stepOrder($flow);

            $reached = [];
            $left = [];
            $lastStepPerSession = [];

            foreach ($flowSteps as $row) {
                if ($row->step_id === null) {
                    $left[$row->bot_test_session_id] = true;

                    continue;
                }

                $reached[$row->step_id][$row->bot_test_session_id] = true;
                $lastStepPerSession[$row->bot_test_session_id] = $row->step_id;
            }

            $entered = count(array_unique(array_merge(
                array_keys($left),
                array_keys($lastStepPerSession),
            )));

            // Drop-offs are the steps a session stopped on without ever leaving the flow.
            $dropOffs = [];

            foreach ($lastStepPerSession as $sessionId => $stepId) {
                if (! isset($left[$sessionId])) {
                    $dropOffs[$stepId] = ($dropOffs[$stepId] ?? 0) + 1;
                }
            }

            arsort($dropOffs);

            $keys = array_keys($reached);
            usort($keys, function (string $a, string $b) use ($order, $reached) {
                $ia = $order[$a] ?? PHP_INT_MAX;
                $ib = $order[$b] ?? PHP_INT_MAX;

                return $ia === $ib ? count($reached[$b]) <=> count($reached[$a]) : $ia <=> $ib;
            });

            $out[] = [
                'key' => $flowKey,
                'title' => $flow?->title_ar ?? $flowKey,
                'entered' => $entered,
                'finished' => count($left),
                'steps' => array_map(fn (string $step) => [
                    'id' => $step,
                    'reached' => count($reached[$step]),
                    'dropped' => (int) ($dropOffs[$step] ?? 0),
                ], $keys),
                'top_drop_offs' => array_map(
                    fn (string $step) => ['id' => $step, 'dropped' => $dropOffs[$step]],
                    array_slice(array_keys($dropOffs), 0, 3),
                ),
            ];
        }

        usort($out, fn (array $a, array $b) => $b['entered'] <=> $a['entered']);

        return $out;
    }

    /**
     * @param  list<array<string, mixed>>  $sessions
     * @return array<string, mixed>
     */
    public function totals(array $sessions): array
    {
        $count = count($sessions);
        $durations = array_column($sessions, 'duration_seconds');

        return [
            'sessions' => $count,
            'testers' => count(array_unique(array_column($sessions, 'name'))),
            'messages' => array_sum(array_column($sessions, 'messages_total')),
            'cases' => array_sum(array_column($sessions, 'cases')),
            'handovers' => array_sum(array_column($sessions, 'handovers')),
            'finished' => count(array_filter($sessions, fn (array $s) => $s['finished'])),
            'avg_duration_seconds' => $count > 0 ? (int) round(array_sum($durations) / $count) : 0,
        ];
    }

    /**
     * The readable transcript of one run, with who said what.
     *
     * @return list<array<string, mixed>>
     */
    public function transcript(BotTestSession $session): array
    {
        if ($session->conversation_id === null) {
            return [];
        }

        return Message::query()
            ->with('user:id,name')
            ->where('conversation_id', $session->conversation_id)
            ->orderBy('id')
            ->limit(self::TRANSCRIPT_LIMIT)
            ->get()
            ->map(fn (Message $m) => [
                'id' => $m->id,
                'direction' => $m->direction?->value,
                'sender' => $m->sender_type?->value,
                'author' => $m->sender_type === SenderType::User ? $m->user?->name : null,
                'body' => $m->body,
                'buttons' => array_values($m->buttons ?? []),
                'has_cards' => ! empty($m->cards),
                'has_image' => $m->mediaAttachments()->exists(),
                'created_at' => $m->created_at?->toIso8601String(),
            ])
            ->all();
    }

    /**
     * The sessions table as CSV (design §4). One header row, then one row per run.
     *
     * @param  list<array<string, mixed>>  $sessions
     * @return iterable<list<string>>
     */
    public function csvRows(array $sessions): iterable
    {
        yield ['link', 'tester', 'run', 'started_at', 'duration_seconds', 'messages_in', 'messages_out', 'device', 'flows', 'last_step', 'finished', 'cases', 'handovers', 'conversation_id'];

        foreach ($sessions as $s) {
            yield [
                (string) ($s['link_label'] ?? ''),
                (string) $s['name'],
                (string) $s['run_no'],
                (string) ($s['started_at'] ?? ''),
                (string) $s['duration_seconds'],
                (string) $s['messages_in'],
                (string) $s['messages_out'],
                (string) $s['device_family'],
                implode(' | ', array_column($s['flows'], 'title')),
                (string) ($s['last_step'] ?? ''),
                $s['finished'] ? 'yes' : 'no',
                (string) $s['cases'],
                (string) $s['handovers'],
                (string) ($s['conversation_id'] ?? ''),
            ];
        }
    }

    /**
     * What one run's flow trail says: which flows were entered, where it stopped and
     * whether the last flow was seen through to its end.
     *
     * @param  Collection<int, BotTestSessionStep>|null  $rows
     * @return array{flows: list<string>, last_flow: ?string, last_step: ?string, finished: bool, dropped_at: ?string}
     */
    private function trail(?Collection $rows): array
    {
        if ($rows === null || $rows->isEmpty()) {
            return ['flows' => [], 'last_flow' => null, 'last_step' => null, 'finished' => false, 'dropped_at' => null];
        }

        $last = $rows->last();
        $lastWithStep = $rows->last(fn (BotTestSessionStep $r) => $r->step_id !== null);
        $finished = $last->step_id === null;

        return [
            'flows' => $rows->pluck('flow_key')->unique()->values()->all(),
            'last_flow' => $last->flow_key,
            'last_step' => $lastWithStep?->step_id,
            'finished' => $finished,
            'dropped_at' => $finished ? null : $lastWithStep?->step_id,
        ];
    }

    /** Step id → its position in the published definition, so the funnel reads in flow order. */
    private function stepOrder(?BotFlow $flow): array
    {
        $order = [];
        $i = 0;

        foreach (array_keys((array) ($flow?->definition['steps'] ?? [])) as $stepId) {
            $order[(string) $stepId] = $i++;
        }

        return $order;
    }

    /** @return array<string, string> */
    private function flowTitles(): array
    {
        return BotFlow::query()->pluck('title_ar', 'key')->all();
    }
}
