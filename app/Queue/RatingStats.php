<?php

namespace App\Queue;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * The customers' answers to the rating question (RatingService stores them on the rated entry, G11), read for
 * «النهارده», /reports/team and the board. A rating belongs to the moment she answered (`reviewed_at`) and to the
 * agent of the rated window (`assigned_user_id`). Test chats never count. 1 and 2 are "low".
 */
final class RatingStats
{
    public const LOW_MAX = 2;

    public const TZ = 'Africa/Cairo';

    public const EMPTY = ['count' => 0, 'avg' => null, 'low' => 0];

    /** @return array{count:int, avg:?float, low:int} */
    public function summary(CarbonInterface $from, CarbonInterface $to, ?int $userId = null): array
    {
        $r = $this->base($from, $to)
            ->when($userId !== null, fn (Builder $q) => $q->where('assigned_user_id', $userId))
            ->selectRaw('COUNT(*) as n, AVG(review_stars) as stars, SUM(CASE WHEN review_stars <= ? THEN 1 ELSE 0 END) as low', [self::LOW_MAX])
            ->first();

        return self::shape((int) ($r->n ?? 0), $r->stars ?? null, (int) ($r->low ?? 0));
    }

    /** @return array<int, array{count:int, avg:?float, low:int}> */
    public function byAgent(CarbonInterface $from, CarbonInterface $to): array
    {
        return $this->base($from, $to)->whereNotNull('assigned_user_id')
            ->selectRaw('assigned_user_id, COUNT(*) as n, AVG(review_stars) as stars, SUM(CASE WHEN review_stars <= ? THEN 1 ELSE 0 END) as low', [self::LOW_MAX])
            ->groupBy('assigned_user_id')->orderBy('assigned_user_id')->get()
            ->mapWithKeys(fn (object $r) => [(int) $r->assigned_user_id => self::shape((int) $r->n, $r->stars, (int) $r->low)])
            ->all();
    }

    /**
     * Per agent per Cairo day. The day is taken in PHP (a timezone shift in SQL is not portable between sqlite and
     * MariaDB); the rows are three small columns read in chunks.
     *
     * @return list<array{user_id:int, date:string, count:int, avg:?float, low:int}>
     */
    public function byAgentAndDay(CarbonInterface $from, CarbonInterface $to): array
    {
        $acc = [];
        $rows = $this->base($from, $to)->whereNotNull('assigned_user_id')
            ->select(['id', 'assigned_user_id', 'review_stars', 'reviewed_at'])->lazyById(2000, 'id');

        foreach ($rows as $r) {
            $day = CarbonImmutable::parse((string) $r->reviewed_at, (string) config('app.timezone'))->setTimezone(self::TZ)->toDateString();
            $key = $day.'|'.(int) $r->assigned_user_id;
            $acc[$key] ??= ['user_id' => (int) $r->assigned_user_id, 'date' => $day, 'n' => 0, 'sum' => 0, 'low' => 0];
            $acc[$key]['n']++;
            $acc[$key]['sum'] += (int) $r->review_stars;
            $acc[$key]['low'] += (int) $r->review_stars <= self::LOW_MAX ? 1 : 0;
        }

        $out = array_map(fn (array $a) => ['user_id' => $a['user_id'], 'date' => $a['date']] + self::shape($a['n'], $a['sum'] / $a['n'], $a['low']), array_values($acc));
        usort($out, fn (array $x, array $y) => [$y['date'], $x['user_id']] <=> [$x['date'], $y['user_id']]);

        return $out;
    }

    /** @return list<array{entry_id:int, conversation_id:?int, user_id:?int, stars:int, reviewed_at:string}> */
    public function recent(CarbonInterface $from, CarbonInterface $to, bool $lowOnly, int $limit = 50): array
    {
        return $this->base($from, $to)
            ->when($lowOnly, fn (Builder $q) => $q->where('review_stars', '<=', self::LOW_MAX))
            ->orderByDesc('reviewed_at')->orderByDesc('id')->limit($limit)
            ->get(['id', 'conversation_id', 'assigned_user_id', 'review_stars', 'reviewed_at'])
            ->map(fn (object $r) => [
                'entry_id' => (int) $r->id,
                'conversation_id' => $r->conversation_id !== null ? (int) $r->conversation_id : null,
                'user_id' => $r->assigned_user_id !== null ? (int) $r->assigned_user_id : null,
                'stars' => (int) $r->review_stars,
                'reviewed_at' => CarbonImmutable::parse((string) $r->reviewed_at, (string) config('app.timezone'))->toIso8601String(),
            ])->values()->all();
    }

    private function base(CarbonInterface $from, CarbonInterface $to): Builder
    {
        return DB::table('queue_entries')->whereNotNull('review_stars')->where('is_test', false)
            ->whereBetween('reviewed_at', [$from, $to]);
    }

    /** @return array{count:int, avg:?float, low:int} */
    private static function shape(int $n, mixed $stars, int $low): array
    {
        return ['count' => $n, 'avg' => $n > 0 ? round((float) $stars, 1) : null, 'low' => $low];
    }
}
