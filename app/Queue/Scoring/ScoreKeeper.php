<?php

namespace App\Queue\Scoring;

use App\Models\QueueSetting;
use App\Models\ScoreEvent;
use App\Models\User;
use App\Queue\QueueService;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * The moderators' points ledger (queue Part 2, refresh 2026-10-17). Append-only and transparent:
 * every point is one `score_events` line with its reason; nothing edits or deletes a line, a
 * mistake is taken back by a reversal line. There is no manual adjustment.
 *
 *  - idempotent: one line per `unique_key` (the caller's key per award, e.g. `base:{entry}`);
 *  - the daily cap (`points.daily_cap`, ≤ 0 = none) limits the positive lines that stand on a
 *    moderator's business day; a capped award keeps what fits and stores the asked `requested`;
 *    negative awards and reversals are never capped;
 *  - a moderator's awards are serialised on her `users` row, so two listeners at once never both
 *    take the last room under the cap. Never called inside a queue transaction (listeners run
 *    after the commit), so the lock order conversation → entry → member is untouched.
 */
class ScoreKeeper
{
    /**
     * @param  array{queue_entry_id?: ?int, conversation_id?: ?int, support_case_id?: ?int, qa_review_id?: ?int}  $refs
     */
    public function award(int $userId, string $kind, int $points, string $reasonKey, array $vars, string $key, array $refs = [], ?string $date = null): ScoreEvent
    {
        $key = mb_substr($key, 0, 100);
        if ($existing = ScoreEvent::query()->where('unique_key', $key)->first()) {
            return $existing;
        }

        $date ??= app(QueueService::class)->businessDate();

        try {
            return DB::transaction(function () use ($userId, $kind, $points, $reasonKey, $vars, $key, $refs, $date) {
                User::query()->whereKey($userId)->lockForUpdate()->first(['id']);

                if ($existing = ScoreEvent::query()->where('unique_key', $key)->first()) {
                    return $existing;
                }

                $paid = $points;
                $cap = $this->cap();

                if ($points > 0 && $cap > 0) {
                    $used = (int) ScoreEvent::query()->where('user_id', $userId)->where('business_date', $date)
                        ->where('points', '>', 0)->whereNull('reversed_by_id')->sum('points');
                    $paid = max(0, min($points, $cap - $used));
                }

                return ScoreEvent::query()->create(array_intersect_key($refs, array_flip(['queue_entry_id', 'conversation_id', 'support_case_id', 'qa_review_id'])) + [
                    'user_id' => $userId, 'business_date' => $date, 'kind' => $kind, 'points' => $paid, 'requested' => $points,
                    'reason_key' => $reasonKey, 'reason_vars' => $vars, 'unique_key' => $key,
                ]);
            }, attempts: 3);
        } catch (UniqueConstraintViolationException) {
            return ScoreEvent::query()->where('unique_key', $key)->firstOrFail();
        }
    }

    /**
     * Takes a standing line back with a line of the opposite points (same kind, day and refs).
     * Null for a 0-point line, a reversal, or a line already reversed.
     */
    public function reverse(ScoreEvent $line, string $reasonKey, array $vars = []): ?ScoreEvent
    {
        // Not serialised on the users row like award(): a reversal is never capped, and an award
        // running beside it can at worst read the sum before the reversal (it then pays less, never more).
        return DB::transaction(function () use ($line, $reasonKey, $vars) {
            $locked = ScoreEvent::query()->whereKey($line->id)->lockForUpdate()->first();

            if ($locked === null || $locked->points === 0 || $locked->reverses_id !== null || $locked->reversed_by_id !== null) {
                return null;
            }

            $rev = ScoreEvent::query()->create($locked->only(['user_id', 'business_date', 'kind', 'queue_entry_id', 'conversation_id', 'support_case_id', 'qa_review_id']) + [
                'points' => -$locked->points, 'requested' => -$locked->points, 'reason_key' => $reasonKey,
                'reason_vars' => $vars + ($locked->reason_vars ?? []), 'unique_key' => 'rev:'.$locked->id, 'reverses_id' => $locked->id,
            ]);
            $locked->forceFill(['reversed_by_id' => $rev->id])->save();

            return $rev;
        }, attempts: 3);
    }

    /**
     * Reverses every standing line of `$column = $id` of those kinds. Returns how many.
     *
     * @param  list<string>  $kinds
     */
    public function reverseWhere(string $column, int $id, array $kinds, string $reasonKey, array $vars = []): int
    {
        $lines = ScoreEvent::query()->where($column, $id)->whereIn('kind', $kinds)
            ->whereNull('reverses_id')->whereNull('reversed_by_id')->where('points', '!=', 0)->orderBy('id')->get();

        return $lines->filter(fn (ScoreEvent $l) => $this->reverse($l, $reasonKey, $vars) !== null)->count();
    }

    public function dayTotal(int $userId, string $date): int
    {
        return (int) ScoreEvent::query()->where('user_id', $userId)->where('business_date', $date)->sum('points');
    }

    /**
     * Totals of a day per moderator, for the desks: one grouped query.
     *
     * @param  list<int>  $userIds
     * @return array<int, int>
     */
    public function dayTotals(array $userIds, string $date): array
    {
        if ($userIds === []) {
            return [];
        }

        return ScoreEvent::query()->toBase()->whereIn('user_id', $userIds)->where('business_date', $date)
            ->selectRaw('user_id, SUM(points) as total')->groupBy('user_id')->pluck('total', 'user_id')
            ->map(fn ($v) => (int) $v)->all();
    }

    /** @return array{total:int, cap:int, capped:int, by_kind:array<string,int>} */
    public function summary(int $userId, string $from, ?string $to = null): array
    {
        $rows = ScoreEvent::query()->toBase()->where('user_id', $userId)->whereBetween('business_date', [$from, $to ?? $from])
            ->selectRaw('kind, SUM(points) as total, SUM(CASE WHEN requested > points AND requested > 0 AND reverses_id IS NULL THEN requested - points ELSE 0 END) as capped')
            ->groupBy('kind')->get();
        $byKind = array_fill_keys(ScoreEvent::KINDS, 0);

        foreach ($rows as $r) {
            $byKind[(string) $r->kind] = (int) $r->total;
        }

        return ['total' => array_sum($byKind), 'cap' => $this->cap(), 'capped' => (int) $rows->sum('capped'), 'by_kind' => $byKind];
    }

    /**
     * A moderator's lines of a day (or range), newest first, with the reason in the viewer's language.
     *
     * @return list<array<string, mixed>>
     */
    public function ledger(int $userId, string $from, ?string $to = null, int $limit = 300): array
    {
        return ScoreEvent::query()->where('user_id', $userId)->whereBetween('business_date', [$from, $to ?? $from])
            ->orderByDesc('id')->limit($limit)->get()
            ->map(fn (ScoreEvent $l) => self::line($l))->values()->all();
    }

    /** @return array<string, mixed> */
    public static function line(ScoreEvent $l): array
    {
        return [
            'id' => $l->id,
            'business_date' => $l->business_date,
            'kind' => $l->kind,
            'points' => $l->points,
            'requested' => $l->requested,
            'reason' => self::reasonText($l),
            'queue_entry_id' => $l->queue_entry_id,
            'conversation_id' => $l->conversation_id,
            'support_case_id' => $l->support_case_id,
            'reversed' => $l->reversed_by_id !== null,
            'is_reversal' => $l->reverses_id !== null,
            'at' => $l->created_at?->toIso8601String(),
        ];
    }

    /** The line's reason in `$locale` (default: the viewer's), with the cap note when the cap cut it. */
    public static function reasonText(ScoreEvent $l, ?string $locale = null): string
    {
        $vars = array_map(fn ($v) => is_scalar($v) ? (string) $v : '', $l->reason_vars ?? []);
        $text = (string) __('queue.points.'.$l->reason_key, $vars, $locale);

        if ($l->reverses_id === null && $l->requested > 0 && $l->requested > $l->points) {
            $text .= ' '.__('queue.points.capped', ['cap' => (string) app(self::class)->cap()], $locale);
        }

        return $text;
    }

    public function cap(): int
    {
        return QueueSetting::current()->point('daily_cap');
    }
}
