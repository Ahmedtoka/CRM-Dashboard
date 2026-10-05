<?php

namespace App\Ads\Health;

use App\Ads\AdsSettings;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/**
 * One is dispatched per expected queue every five minutes. Running it proves a worker is consuming that queue; the
 * time is kept in ads_settings (the cache can be wiped), and ads:health reads it.
 */
class QueueHeartbeat implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 1;

    public function __construct(public string $queueName)
    {
        $this->onQueue($queueName);
    }

    public function handle(AdsSettings $settings): void
    {
        $settings->set(self::key($this->queueName), now()->toIso8601String());
    }

    public static function key(string $queue): string
    {
        return 'queue_heartbeat:'.$queue;
    }
}
