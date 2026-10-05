<?php

namespace App\Ads\Attribution\Commands;

use App\Analytics\ActivityLogger;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Rebuilds conversation_ad_referrals (A4) from the `conversation.referral` activity-log rows that
 * App\Inbox\AdAttribution writes for every referral. referred_at = the log row's created_at, the same stamp the live
 * path uses, so the unique key makes a re-run (or a run after live rows exist) a no-op. Writes only that table.
 */
class BackfillReferralsCommand extends Command
{
    protected $signature = 'ads:backfill-referrals {--since=2026-09-25 : First day (UTC) of activity-log rows to read}';

    protected $description = 'Rebuild the ad referral history of conversations from the activity log (idempotent)';

    public function handle(): int
    {
        $since = CarbonImmutable::parse((string) $this->option('since'), 'UTC')->startOfDay();
        $read = $inserted = 0;

        DB::table('activity_logs as l')
            ->join('conversations as c', 'c.id', '=', 'l.conversation_id')
            ->where('l.action', ActivityLogger::CONVERSATION_REFERRAL)
            ->where('l.created_at', '>=', $since->format('Y-m-d H:i:s'))
            ->select(['l.id', 'l.conversation_id', 'l.meta', 'l.created_at', 'c.customer_id'])
            ->chunkById(1000, function ($logs) use (&$read, &$inserted) {
                $rows = [];
                foreach ($logs as $log) {
                    $read++;
                    $meta = json_decode((string) $log->meta, true);
                    $ad = is_array($meta) ? ($meta['ad_id'] ?? null) : null;
                    if ($ad === null || $ad === '' || $log->created_at === null) {
                        continue;
                    }
                    $rows[] = [
                        'conversation_id' => (int) $log->conversation_id,
                        'customer_id' => $log->customer_id !== null ? (int) $log->customer_id : null,
                        'ad_external_id' => mb_substr((string) $ad, 0, 64),
                        'referred_at' => CarbonImmutable::parse((string) $log->created_at)->format('Y-m-d H:i:s'),
                    ];
                }
                if ($rows !== []) {
                    $inserted += DB::table('conversation_ad_referrals')->insertOrIgnore($rows);
                }
            }, 'l.id', 'id');

        $this->info("Read {$read} referral log row(s); added {$inserted} referral row(s).");

        return self::SUCCESS;
    }
}
