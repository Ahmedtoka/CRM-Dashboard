<?php

namespace App\Ads\Sync;

use App\Ads\Control\Jobs\PublishAd;
use App\Models\AdAccount;
use App\Models\AdPublication;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Redis;
use Throwable;

/** Lists the jobs waiting on the commercelong queue (Redis only), decoding sync jobs into account, kind and who asked. */
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

    /** @return list<array{job: string, account_id: ?int, account: ?string, kind: ?string, days: ?int, attempts: int, available_at: ?string, trigger: ?string}> */
    public function waiting(string $queue = 'commercelong'): array
    {
        if (! $this->supported()) {
            return [];
        }

        try {
            [$ready, $delayed] = ($this->source ?? $this->redisSource(...))($queue);
        } catch (Throwable) {
            return [];
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
