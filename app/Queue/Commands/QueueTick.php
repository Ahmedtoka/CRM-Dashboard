<?php

namespace App\Queue\Commands;

use App\Models\QueueDecision;
use App\Models\QueueSetting;
use App\Queue\QueueRouter;
use App\Queue\ShiftService;
use App\Queue\WaitEstimator;
use App\Queue\WindowLifecycle;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;

/**
 * The heartbeat of the handover queue, scheduled every 30 seconds (QueueServiceProvider).
 * Steps, in this order, each on its own: a step that throws is reported and the next ones still run.
 *   1. shifts open / close on time;
 *   2. members: break overrun alert, offline moderators, hand-off of their windows, check-out after
 *      10 minutes offline (`auto_out`), pending breaks and check-outs settled;
 *   3. customer silence: warning, then auto-close;
 *   4. moderator reply: apology, hand-off (`no_reply`) or leader alert (flow revision §4);
 *   5. confirm sweep: closes whose confirm window passed (safety net for a lost ConfirmClose job);
 *   6. the router (before the countdown, so a customer served now gets no «باقي» message);
 *   7. the «باقي 5 / 3 / 1» countdown and apology messages to the lounge (desks read once);
 *   8. once an hour, decision lines older than 7 days are deleted.
 *
 * The settings row is read once and handed to the steps that loop.
 *
 * Two ticks never run side by side (`queue:tick` cache lock, on top of the schedule's
 * withoutOverlapping): the second one leaves at once. Every customer message is decided under the
 * row lock of its entry, so even a tick run by hand next to the scheduled one sends nothing twice.
 *
 * Does nothing while the queue is switched off in the settings.
 */
class QueueTick extends Command
{
    public const LOCK_SECONDS = 120;

    public const DECISIONS_KEPT_DAYS = 7;

    protected $signature = 'queue:tick';

    protected $description = 'Handover queue heartbeat: shifts, breaks, offline members, silence timers, confirm sweep, waiting messages, router';

    protected $help = <<<'TXT'
Scheduled every 30 seconds. Laravel runs a sub-minute task from inside `schedule:run`, which
stays alive until the end of the minute and starts the second run itself, so the server's cron
keeps its one-minute line:

  * * * * * cd <app>/public_html/backend && php artisan schedule:run >> /dev/null 2>&1

On Cloudways (Application Settings -> Cron Job Management) keep exactly that line. Do not wrap it
in `timeout`, and do not add a second cron line for queue:tick. `deploy.sh` runs
`php artisan schedule:interrupt` so a running schedule:run stops repeating with the old code.

The cache store must support locks (redis, database, file): the schedule's withoutOverlapping /
onOneServer and the tick's own lock live there.

Locally: `php artisan schedule:work` in its own window, or run `php artisan queue:tick` by hand.
Details: docs/queue/scheduling.md
TXT;

    public function handle(ShiftService $shifts, WindowLifecycle $windows, WaitEstimator $estimator, QueueRouter $router): int
    {
        $settings = QueueSetting::current();

        if (! $settings->enabled) {
            return self::SUCCESS;
        }

        $lock = Cache::lock('queue:tick', self::LOCK_SECONDS);

        if (! $lock->get()) {
            $this->info('Another queue:tick is still running; skipped.');

            return self::SUCCESS;
        }

        try {
            $steps = [
                'shifts' => fn () => $shifts->transition($settings),
                'members' => fn () => $shifts->tickMembers($settings),
                'silence' => fn () => $windows->tickSilence($settings),
                'replies' => fn () => $windows->tickReplies($settings),
                'confirm' => fn () => $windows->confirmDue($settings),
                'router' => fn () => $router->run('التيك الدوري'),
                'waiting' => fn () => $estimator->tickLounge($settings),
                'prune' => fn () => $this->pruneDecisions(),
            ];

            foreach ($steps as $step) {
                rescue($step, null, report: true);
            }
        } finally {
            $lock->release();
        }

        return self::SUCCESS;
    }

    private function pruneDecisions(): void
    {
        if (Cache::add('queue:decisions-pruned', true, now()->addHour())) {
            QueueDecision::query()->where('created_at', '<', now()->subDays(self::DECISIONS_KEPT_DAYS))->delete();
        }
    }
}
