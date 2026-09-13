<?php

namespace App\Analytics\Commands;

use App\Analytics\MetricsService;
use App\Enums\Platform;
use App\Models\AnalyticsDaily;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Fills analytics_daily for one Africa/Cairo calendar day: per user a totals row (platform null)
 * plus one row per platform with activity, and the same for the bot (user_id null).
 * Re-running a date updates rows in place and removes rows that no longer have activity.
 */
class RollupDaily extends Command
{
    protected $signature = 'crm:rollup {date? : Africa/Cairo date (Y-m-d); defaults to today}';

    protected $description = 'Roll up moderator and bot metrics into analytics_daily for one Cairo day';

    public function handle(MetricsService $metrics): int
    {
        $arg = $this->argument('date');
        $day = $arg
            ? CarbonImmutable::createFromFormat('!Y-m-d', (string) $arg, MetricsService::TZ)
            : CarbonImmutable::now(MetricsService::TZ)->startOfDay();

        if ($day === false || ($arg && $day->format('Y-m-d') !== $arg)) {
            $this->error("Invalid date [{$arg}], expected Y-m-d.");

            return self::FAILURE;
        }

        $date = $day->toDateString();
        $from = $day->startOfDay()->utc();
        $to = $day->endOfDay()->utc();

        $rows = [];

        foreach (User::query()->orderBy('id')->get() as $user) {
            $rows[] = [$user->id, null, $this->userColumns($metrics->userMetrics($user, $from, $to))];
            foreach (Platform::cases() as $platform) {
                $rows[] = [$user->id, $platform, $this->userColumns($metrics->userMetrics($user, $from, $to, $platform))];
            }
        }

        $rows[] = [null, null, $this->botColumns($metrics->botMetrics($from, $to))];
        foreach (Platform::cases() as $platform) {
            $rows[] = [null, $platform, $this->botColumns($metrics->botMetrics($from, $to, $platform))];
        }

        $written = 0;

        DB::transaction(function () use ($rows, $date, &$written) {
            $keep = [];

            foreach ($rows as [$userId, $platform, $columns]) {
                if (array_sum($columns) == 0) {
                    continue;
                }

                $row = AnalyticsDaily::query()
                    ->whereDate('date', $date)
                    ->where('user_id', $userId)
                    ->where('platform', $platform?->value)
                    ->first()
                    ?? new AnalyticsDaily(['date' => $date, 'user_id' => $userId, 'platform' => $platform]);

                $row->fill($columns)->save();
                $keep[] = $row->id;
            }

            AnalyticsDaily::query()->whereDate('date', $date)->whereNotIn('id', $keep)->delete();

            $written = count($keep);
        });

        $this->info("analytics_daily {$date}: {$written} rows.");

        return self::SUCCESS;
    }

    private function userColumns(array $m): array
    {
        return [
            'messages_sent' => $m['messages_sent'],
            'conversations_handled' => $m['conversations_handled'],
            'first_responses' => $m['first_responses'],
            'follow_ups' => $m['follow_ups'],
            'resolved' => $m['resolved'],
            'avg_first_response_sec' => $m['avg_first_response_sec'],
            'avg_response_sec' => $m['avg_response_sec'],
            'comments_handled' => $m['comments_handled'],
            'orders_count' => $m['orders_count'],
            'orders_total' => $m['orders_total'],
            'online_minutes' => $m['online_minutes'],
        ];
    }

    private function botColumns(array $m): array
    {
        return [
            'messages_sent' => $m['messages_sent'],
            'conversations_handled' => $m['conversations_touched'],
            'resolved' => $m['auto_resolved'],
            'comments_handled' => $m['comments_replied'] + $m['comments_hidden'] + $m['private_replies'],
        ];
    }
}
