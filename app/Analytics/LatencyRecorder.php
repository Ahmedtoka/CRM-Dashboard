<?php

namespace App\Analytics;

use App\Models\Message;
use App\Models\WebhookEvent;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use DateTimeImmutable;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Records how long each acceptance-critical path took (spec §11.3) so
 * crm:latency-report can compute percentiles against the exact targets.
 *
 * Every public method here is wrapped so a recording failure (a bad
 * connection, a locked table, an unexpected value) never breaks the request,
 * job, or webhook it is instrumenting: the exception is reported and
 * swallowed.
 *
 * inbound()/outbound() work in integer epoch-milliseconds throughout, never
 * through a DATETIME(0) column: `messages.created_at` and the old
 * `webhook_events.received_at` are only second-precision (no custom
 * `$dateFormat`), which silently inflated every measured duration by up to
 * 999ms. The real start instants live in `webhook_events.received_at_ms` and
 * `messages.queued_at_ms` (plain bigint columns, set at capture time).
 */
final class LatencyRecorder
{
    /** Cap per crm:latency-report query (ruling: cap to the latest 100,000 samples). */
    private const MAX_SAMPLES = 100_000;

    /**
     * started_at = event.received_at_ms, ended_at = now (after the MessageCreated
     * broadcast), via the injectable crm.latency.clock so tests can assert an exact
     * duration without a real sleep.
     */
    public function inbound(WebhookEvent $event, Message $message): void
    {
        $this->safely(function () use ($event, $message) {
            $startedAtMs = $event->received_at_ms ?? $event->received_at?->getTimestampMs() ?? $event->created_at?->getTimestampMs();

            if ($startedAtMs === null) {
                return;
            }

            $endedAtMs = $this->nowMs();

            $this->insertMs('inbound', (string) $message->id, $startedAtMs, $endedAtMs, [
                'message_id' => $message->id,
                'webhook_event_id' => $event->id,
            ]);
        });
    }

    /**
     * started_at = message queued_at_ms (moderator pressed send / bot queued the reply),
     * ended_at = the moment we are about to call the channel adapter's provider API.
     */
    public function outbound(Message $message, CarbonInterface $providerCallAt): void
    {
        $this->safely(function () use ($message, $providerCallAt) {
            $startedAtMs = $message->queued_at_ms ?? $message->created_at?->getTimestampMs();

            if ($startedAtMs === null) {
                return;
            }

            $endedAtMs = $providerCallAt->getTimestampMs();

            $this->insertMs('outbound', (string) $message->id, $startedAtMs, $endedAtMs, [
                'message_id' => $message->id,
            ]);
        });
    }

    /**
     * @param  array<string, mixed>  $meta
     */
    public function list(string $ref, float $startedAt, float $endedAt, array $meta = []): void
    {
        $this->safely(function () use ($ref, $startedAt, $endedAt, $meta) {
            $durationMs = ($endedAt - $startedAt) * 1000;

            if (! is_finite($durationMs)) {
                return;
            }

            $this->insert(
                'list',
                $ref,
                $this->instantFromUnixSeconds($startedAt),
                $this->instantFromUnixSeconds($endedAt),
                $meta,
                (int) max(0, round($durationMs)),
            );
        });
    }

    /**
     * Driver-agnostic queue-wait sample: time between a job being pushed and a worker
     * picking it up (Queue::before), so crm:latency-report doesn't need a database queue.
     *
     * @param  array<string, mixed>  $meta
     */
    public function queueWait(string $ref, int $pushedAtMs, int $pickedUpAtMs, array $meta = []): void
    {
        $this->safely(function () use ($ref, $pushedAtMs, $pickedUpAtMs, $meta) {
            $this->insertMs('queue', $ref, $pushedAtMs, $pickedUpAtMs, $meta);
        });
    }

    /**
     * Nearest-rank percentiles (p = ceil(q/100 x n)) computed in PHP over a query
     * limited to the window and capped to the latest MAX_SAMPLES rows, selecting
     * only duration_ms.
     *
     * @return array{count:int,p50:int,p95:int,p99:int,max:int}
     */
    public function percentiles(string $kind, CarbonInterface $from, CarbonInterface $to): array
    {
        $durations = DB::table('latency_samples')
            ->where('kind', $kind)
            ->whereBetween('created_at', [$from, $to])
            ->orderByDesc('id')
            ->limit(self::MAX_SAMPLES)
            ->pluck('duration_ms')
            ->map(fn ($v) => (int) $v)
            ->sort()
            ->values();

        $count = $durations->count();

        if ($count === 0) {
            return ['count' => 0, 'p50' => 0, 'p95' => 0, 'p99' => 0, 'max' => 0];
        }

        return [
            'count' => $count,
            'p50' => $this->nearestRank($durations, 50),
            'p95' => $this->nearestRank($durations, 95),
            'p99' => $this->nearestRank($durations, 99),
            'max' => (int) $durations->last(),
        ];
    }

    /**
     * @param  \Illuminate\Support\Collection<int, int>  $sorted  ascending
     */
    private function nearestRank($sorted, int $percentile): int
    {
        $n = $sorted->count();
        $rank = (int) ceil(($percentile / 100) * $n);
        $rank = max(1, min($n, $rank));

        return (int) $sorted->get($rank - 1);
    }

    /**
     * @param  array<string, mixed>  $meta
     */
    private function insertMs(string $kind, string $ref, int $startedAtMs, int $endedAtMs, array $meta): void
    {
        $this->insert(
            $kind,
            $ref,
            CarbonImmutable::createFromTimestampMs($startedAtMs),
            CarbonImmutable::createFromTimestampMs($endedAtMs),
            $meta,
            max(0, $endedAtMs - $startedAtMs),
        );
    }

    /**
     * @param  array<string, mixed>  $meta
     */
    private function insert(string $kind, string $ref, CarbonImmutable $startedAt, CarbonImmutable $endedAt, array $meta, ?int $durationMs = null): void
    {
        if (! config('crm.latency.enabled')) {
            return;
        }

        if (! $this->shouldSample((float) config('crm.latency.sample_rate', 1.0))) {
            return;
        }

        $durationMs ??= max(0, $startedAt->diffInMilliseconds($endedAt, false));

        DB::table('latency_samples')->insert([
            'kind' => $kind,
            'ref' => $ref,
            'started_at' => $startedAt,
            'ended_at' => $endedAt,
            'duration_ms' => $durationMs,
            'meta' => json_encode($meta),
            'created_at' => CarbonImmutable::now(),
            'updated_at' => CarbonImmutable::now(),
        ]);
    }

    private function shouldSample(float $rate): bool
    {
        if ($rate >= 1.0) {
            return true;
        }

        if ($rate <= 0.0) {
            return false;
        }

        return mt_rand(1, 1_000_000) <= (int) round($rate * 1_000_000);
    }

    private function instantFromUnixSeconds(float $unixSeconds): CarbonImmutable
    {
        $seconds = (int) floor($unixSeconds);
        $micros = (int) round(($unixSeconds - $seconds) * 1_000_000);

        $dt = DateTimeImmutable::createFromFormat('U.u', sprintf('%d.%06d', $seconds, $micros));

        return CarbonImmutable::instance($dt ?: new DateTimeImmutable('@'.$seconds));
    }

    /**
     * Epoch-milliseconds "now", via the container-bound crm.latency.clock (a closure
     * returning float unix seconds; defaults to microtime(true)) so tests can control
     * the end checkpoint of inbound() without a real sleep.
     */
    private function nowMs(): int
    {
        /** @var callable():float $clock */
        $clock = app('crm.latency.clock');

        return (int) floor($clock() * 1000);
    }

    /**
     * Recording must never break the request/job it instruments.
     */
    private function safely(callable $fn): void
    {
        try {
            $fn();
        } catch (Throwable $e) {
            report($e);
        }
    }
}
