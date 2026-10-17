<?php

namespace App\Webhooks;

use App\Analytics\MetricsService;
use App\Inbox\UserNotifier;
use App\Models\WebhookRejection;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Webhooks refused at the door (2026-10-17): a Meta POST whose X-Hub-Signature-256 does not match
 * the App Secret (403), a Shopify POST whose HMAC does not match the API secret (401). Production
 * once refused every Shopify webhook for days and nobody could see it; now each refusal bumps a
 * counter per provider per hour, writes a throttled warning (provider, path and reason only —
 * never the payload, a header or a secret), and Settings shows the count for the last day.
 *
 * When they pile up (THRESHOLD in one hour) the admins get one bell notification per provider per
 * Cairo day. Recording can never break the response: every failure here is swallowed and reported.
 */
class WebhookRejections
{
    /** Rejections in one hour that ring the admins' bell. */
    public const THRESHOLD = 5;

    /** The window Settings reports. */
    public const WINDOW_HOURS = 24;

    /** At most one log line per provider in this many seconds. */
    private const LOG_EVERY_SECONDS = 600;

    public const NOTIFICATION = 'webhook.rejected';

    public function __construct(private readonly UserNotifier $notifier) {}

    public function record(string $provider, string $path, string $reason): void
    {
        rescue(fn () => $this->store($provider, Str::limit($path, 120, ''), Str::limit($reason, 40, '')), report: true);
    }

    /**
     * @return array{count: int, last_at: ?string, last_reason: ?string, hours: int}
     */
    public function summary(string $provider): array
    {
        $rows = WebhookRejection::query()
            ->where('provider', $provider)
            ->where('hour', '>=', CarbonImmutable::now()->subHours(self::WINDOW_HOURS - 1)->startOfHour())
            ->orderByDesc('last_at')
            ->get(['rejected', 'last_at', 'last_reason']);

        return [
            'count' => (int) $rows->sum('rejected'),
            'last_at' => $rows->first()?->last_at?->toIso8601String(),
            'last_reason' => $rows->first()?->last_reason,
            'hours' => self::WINDOW_HOURS,
        ];
    }

    /**
     * @param  list<string>  $providers
     * @return array<string, array{count: int, last_at: ?string, last_reason: ?string, hours: int}>
     */
    public function summaries(array $providers): array
    {
        return collect($providers)->mapWithKeys(fn (string $p) => [$p => $this->summary($p)])->all();
    }

    private function store(string $provider, string $path, string $reason): void
    {
        $now = CarbonImmutable::now();
        $hour = $now->startOfHour();

        DB::table('webhook_rejections')->upsert(
            [[
                'provider' => $provider, 'hour' => $hour, 'rejected' => 1, 'last_reason' => $reason,
                'last_path' => $path, 'last_at' => $now, 'notified' => false, 'created_at' => $now, 'updated_at' => $now,
            ]],
            ['provider', 'hour'],
            ['rejected' => DB::raw('rejected + 1'), 'last_reason' => $reason, 'last_path' => $path, 'last_at' => $now, 'updated_at' => $now],
        );

        if (Cache::add("webhook-rejections:log:{$provider}", true, self::LOG_EVERY_SECONDS)) {
            Log::warning('webhook.rejected', ['provider' => $provider, 'path' => $path, 'reason' => $reason]);
        }

        $row = WebhookRejection::query()->where('provider', $provider)->where('hour', $hour)->first();

        if ($row === null || $row->rejected < self::THRESHOLD || $row->notified || $this->notifiedToday($provider)) {
            return;
        }

        // Claimed on the row, so two requests crossing the threshold together notify once.
        if (WebhookRejection::query()->whereKey($row->id)->where('notified', false)->update(['notified' => true]) !== 1) {
            return;
        }

        $this->notifier->notifyAdmins(self::NOTIFICATION, [
            'provider' => $provider,
            'count' => $row->rejected,
            'reason' => $reason,
            'at' => $now->toIso8601String(),
        ]);
    }

    private function notifiedToday(string $provider): bool
    {
        $dayStart = CarbonImmutable::now(MetricsService::TZ)->startOfDay()->utc();

        return WebhookRejection::query()->where('provider', $provider)->where('hour', '>=', $dayStart)->where('notified', true)->exists();
    }
}
