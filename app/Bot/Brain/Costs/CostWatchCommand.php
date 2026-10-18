<?php

namespace App\Bot\Brain\Costs;

use App\Inbox\UserNotifier;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;

/**
 * Hourly (b2, 2026-10-19): a bell warning to the admins, at most once per Cairo day each, when the
 * Anthropic balance is projected to last fewer than 3 days, or when today's Sonnet cap was hit
 * (from then until midnight every turn runs on Haiku).
 */
class CostWatchCommand extends Command
{
    protected $signature = 'bot:cost-watch';

    protected $description = 'Warn the admins when the Anthropic balance runs low or the daily Sonnet cap is hit';

    public function handle(BotCostReport $report, UserNotifier $notifier): int
    {
        $data = $report->build();
        $day = now(BotCostReport::TZ)->toDateString();

        if (($data['balance']['warn'] ?? false) && Cache::add("bot:cost-watch:balance:{$day}", true, now()->addDays(2))) {
            $notifier->notifyAdmins('bot.balance_low', [
                'days' => $data['balance']['days_left'],
                'left_usd' => $data['balance']['left_usd'],
            ]);
            $this->info('balance warning sent');
        }

        if (($data['sonnet']['hit'] ?? false) && Cache::add("bot:cost-watch:sonnet:{$day}", true, now()->addDays(2))) {
            $notifier->notifyAdmins('bot.sonnet_cap', ['cap' => $data['sonnet']['cap'], 'spent' => $data['sonnet']['spent_today']]);
            $this->info('sonnet cap warning sent');
        }

        return self::SUCCESS;
    }
}
