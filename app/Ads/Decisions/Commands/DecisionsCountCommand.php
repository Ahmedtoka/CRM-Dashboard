<?php

namespace App\Ads\Decisions\Commands;

use App\Ads\Decisions\DecisionCounter;
use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Console\Command;
use Throwable;

/** Refreshes the cached «محتاج قرار» badge of every media buyer and manager (hourly, after the sync and the attribution). */
class DecisionsCountCommand extends Command
{
    protected $signature = 'ads:decisions-count {--user= : One user id}';

    protected $description = 'Refresh the cached Ads decisions badge count per user';

    public function handle(DecisionCounter $counter): int
    {
        $users = User::query()
            ->where('is_active', true)
            ->whereIn('role', [UserRole::Admin->value, UserRole::Supervisor->value, UserRole::MediaBuyer->value])
            ->when($this->option('user') !== null, fn ($q) => $q->whereKey((int) $this->option('user')))
            ->orderBy('id')->get();

        $done = 0;
        foreach ($users as $u) {
            if (! DecisionCounter::eligible($u)) {
                continue;
            }
            try {
                $counter->refresh($u);
                $done++;
            } catch (Throwable $e) {
                report($e); // one user's failure must not leave everyone else's badge stale
            }
        }
        $this->info("Refreshed {$done} badge(s).");

        return self::SUCCESS;
    }
}
