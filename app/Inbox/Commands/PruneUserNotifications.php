<?php

namespace App\Inbox\Commands;

use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Retention for `user_notifications` (final fix wave M4): read notifications
 * are kept 30 days, unread ones 90 days. Deleted in id-ordered chunks so a
 * large backlog never holds one long lock. Scheduled daily (see
 * InboxServiceProvider).
 */
class PruneUserNotifications extends Command
{
    protected $signature = 'crm:prune-user-notifications';

    protected $description = 'Delete read notifications older than 30 days and unread ones older than 90 days';

    public const READ_DAYS = 30;

    public const UNREAD_DAYS = 90;

    private const CHUNK = 1000;

    public function handle(): int
    {
        $now = CarbonImmutable::now();

        $deleted = $this->prune(fn () => DB::table('user_notifications')->whereNotNull('read_at')->where('read_at', '<', $now->subDays(self::READ_DAYS)))
            + $this->prune(fn () => DB::table('user_notifications')->whereNull('read_at')->where('created_at', '<', $now->subDays(self::UNREAD_DAYS)));

        $this->info("deleted={$deleted}");

        return self::SUCCESS;
    }

    /**
     * @param  callable(): Builder  $query
     */
    private function prune(callable $query): int
    {
        $deleted = 0;

        do {
            $ids = $query()->orderBy('id')->limit(self::CHUNK)->pluck('id');

            if ($ids->isEmpty()) {
                break;
            }

            DB::table('user_notifications')->whereIn('id', $ids)->delete();
            $deleted += $ids->count();
        } while ($ids->count() === self::CHUNK);

        return $deleted;
    }
}
