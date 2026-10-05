<?php

namespace App\Ads\Sync;

use App\Ads\Control\Jobs\PublishAd;
use App\Models\AdAccount;
use App\Models\AdPublication;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Redis;
use Throwable;

/** Lists the jobs waiting on the commercelong queue and the ads sync lane (Redis only), decoding sync jobs into account, kind and who asked. */
class QueueInspector
{
    private const LIMIT = 200;

    /** @var (callable(string): array{0: list<string>, 1: list<array{0: string, 1: float}>})|null */
    private $source;

    /** @param  (callable(string): array{0: list<string>, 1: list<array{0: string, 1: float}>})|null  $source  raw payloads: [ready list, [payload, score] delayed pairs]; tests inject it */
    public function __construct(?callable $source = null)
    {
        $this->source = $source;
    }

    public function supported(): bool
    {
        return $this->source !== null || config('queue.default') === 'redis';
    }

    /**
     * Ready, delayed and reserved job counts of a queue (Redis only). Read-only; null when not Redis or unreachable.
     *
     * @return array{ready: int, delayed: int, reserved: int}|null
     */
    public function lengths(string $queue): ?array
    {
        if (config('queue.default') !== 'redis') {
            return null;
        }

        try {
            $redis = Redis::connection(config('queue.connections.redislong.connection'));

            return [
                'ready' => (int) $redis->llen("queues:{$queue}"),
                'delayed' => (int) $redis->zcard("queues:{$queue}:delayed"),
                'reserved' => (int) $redis->zcard("queues:{$queue}:reserved"),
            ];
        } catch (Throwable) {
            return null;
        }
    }

    /** @return list<array{job: string, account_id: ?int, account: ?string, kind: ?string, days: ?int, attempts: int, available_at: ?string, trigger: ?string}> */
    public function waiting(?string $queue = null): array
    {
        if (! $this->supported()) {
            return [];
        }

        // Default: commercelong (publish jobs, and the sync until it moves) plus the ads sync lane when it differs.
        $queues = $queue !== null ? [$queue] : array_values(array_unique(['commercelong', SyncAdAccount::queueName()]));
        $ready = [];
        $delayed = [];
        foreach ($queues as $q) {
            try {
                [$r, $d] = ($this->source ?? $this->redisSource(...))($q);
            } catch (Throwable) {
                continue;
            }
            array_push($ready, ...$r);
            array_push($delayed, ...$d);
        }

        $rows = [];
        foreach ($ready as $payload) {
            $rows[] = $this->decode((string) $payload, null);
        }
        foreach ($delayed as [$payload, $score]) {
            $rows[] = $this->decode((string) $payload, (float) $score);
        }

        $names = AdAccount::query()->whereIn('id', array_filter(array_column($rows, 'account_id')))->pluck('name', 'id');

        return array_map(fn (array $r) => [...$r, 'account' => $r['account_id'] === null ? null : ($names[$r['account_id']] ?? null)], $rows);
    }

    /** @return array{0: list<string>, 1: list<array{0: string, 1: float}>} */
    private function redisSource(string $queue): array
    {
        $redis = Redis::connection(config('queue.connections.redislong.connection'));
        $ready = $redis->lrange("queues:{$queue}", 0, self::LIMIT - 1);
        $delayed = [];
        foreach ($redis->zrange("queues:{$queue}:delayed", 0, self::LIMIT - 1, ['withscores' => true]) as $payload => $score) {
            $delayed[] = [(string) $payload, (float) $score];
        }

        return [$ready, $delayed];
    }

    /** @return array{job: string, account_id: ?int, account: ?string, kind: ?string, days: ?int, attempts: int, available_at: ?string, trigger: ?string} */
    private function decode(string $payload, ?float $availableAt): array
    {
        $data = json_decode($payload, true);
        $data = is_array($data) ? $data : [];
        $name = (string) ($data['displayName'] ?? '');
        $row = [
            'job' => $name === '' ? '?' : class_basename($name), 'account_id' => null, 'account' => null, 'kind' => null, 'days' => null,
            'attempts' => (int) ($data['attempts'] ?? 0),
            'available_at' => $availableAt === null ? null : CarbonImmutable::createFromTimestamp((int) $availableAt)->toIso8601String(),
            'trigger' => null,
        ];

        if ($name === SyncAdAccount::class && is_string($data['data']['command'] ?? null)) {
            $job = @unserialize($data['data']['command'], ['allowed_classes' => [SyncAdAccount::class]]);
            if ($job instanceof SyncAdAccount) {
                $row['account_id'] = $job->accountId;
                $row['kind'] = $job->kind;
                $row['days'] = $job->days;
                $row['trigger'] = $job->trigger;
            }
        }

        if ($name === PublishAd::class && is_string($data['data']['command'] ?? null)) {
            $job = @unserialize($data['data']['command'], ['allowed_classes' => [PublishAd::class]]);
            if ($job instanceof PublishAd) {
                $row['account_id'] = AdPublication::query()->whereKey($job->publicationId)->value('ad_account_id');
                $row['kind'] = 'publish';
            }
        }

        return $row;
    }
}
