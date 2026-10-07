<?php

namespace App\Simulator\Commands;

use App\Simulator\LoadTest\LoadTest;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

/**
 * The production load test (2026-10-07). Every customer line goes through the real webhook
 * pipeline on the «تيست» channels; nothing is ever sent to Meta. Refuses to run unless
 * CRM_LOAD_TEST=true (`crm.load_test`); the scheduler's `tick` is then a silent no-op.
 *
 *   seed-yesterday --count=100   backlog: chats from yesterday evening, waiting for a person
 *   start --every=30 --count=100 --hours=3 [--spread=10]   waves of new chats
 *   status                       the plan and its counters
 *   stop                         ends the plan; pending follow-ups do nothing
 *   tick                         (scheduler, every minute) emits the waves that are due
 */
class RunLoadTest extends Command
{
    protected $signature = 'crm:load-test
        {action : seed-yesterday | start | status | stop | tick}
        {--count=100 : Chats to seed (seed-yesterday) or new chats per wave (start)}
        {--every=30 : Minutes between two waves}
        {--hours=3 : How long the waves run}
        {--spread=10 : Minutes one wave is spread over}
        {--close : With stop: close every open load-test ticket and resolve the test chats (no closing message, no rating)}';

    protected $description = 'Production load test on the «تيست» channels: backlog, waves of customers, simulated follow-ups';

    private const ACTIONS = ['seed-yesterday', 'start', 'status', 'stop', 'tick'];

    public function handle(LoadTest $loadTest): int
    {
        $action = (string) $this->argument('action');

        if (! in_array($action, self::ACTIONS, true)) {
            $this->error('Unknown action «'.$action.'». Use one of: '.implode(', ', self::ACTIONS).'.');

            return self::FAILURE;
        }

        if (! LoadTest::enabled()) {
            if ($action === 'tick') {
                return self::SUCCESS; // the scheduler's every-minute call: nothing to do, nothing to say
            }

            $this->error('Refusing to run: the load test is off. Set CRM_LOAD_TEST=true in .env (then php artisan config:clear), and back to false after the test.');

            return self::FAILURE;
        }

        return match ($action) {
            'seed-yesterday' => $this->seedYesterday($loadTest),
            'start' => $this->start($loadTest),
            'status' => $this->status($loadTest),
            'stop' => $this->stop($loadTest),
            'tick' => $this->tick($loadTest),
        };
    }

    private function seedYesterday(LoadTest $loadTest): int
    {
        if (LoadTest::shiftIsOpen()) {
            $this->error('Refusing: a shift is open. Seed the backlog before the team checks in (close the open shift first).');

            return self::FAILURE;
        }

        $count = max(1, min(1000, (int) $this->option('count')));
        $seeded = $loadTest->seedYesterday($count);

        $this->info("Seeded {$seeded} chats from yesterday evening, handed over and waiting for a person.");
        $this->line("They land in yesterday's numbers only as test chats, and test chats are left out of every report, Today and team figure.");

        return self::SUCCESS;
    }

    private function start(LoadTest $loadTest): int
    {
        $plan = [
            'every' => (int) $this->option('every'),
            'count' => (int) $this->option('count'),
            'hours' => (int) $this->option('hours'),
            'spread' => (int) $this->option('spread'),
        ];

        $problem = match (true) {
            $plan['every'] < 1 || $plan['every'] > 1440 => '--every must be 1-1440 minutes.',
            $plan['count'] < 1 || $plan['count'] > 1000 => '--count must be 1-1000 chats per wave.',
            $plan['hours'] < 1 || $plan['hours'] > 24 => '--hours must be 1-24.',
            $plan['spread'] < 0 || $plan['spread'] > $plan['every'] => '--spread must be 0 to --every minutes.',
            default => null,
        };

        if ($problem !== null) {
            $this->error($problem);

            return self::FAILURE;
        }

        $planned = LoadTest::planned($plan);
        $run = $loadTest->start($plan);

        $this->info("Run #{$run->id}: wave 1 queued ({$plan['count']} new chats over {$plan['spread']} min).");
        $this->line("Wave 1 of {$planned['waves']}; next wave at ".$this->cairo($run->next_wave_at).' (Cairo), last one before '.$this->cairo($run->waves_until).'.');
        $this->line("Planned in all: {$planned['openers']} new chats (a run never sends more than ".LoadTest::MAX_OPENERS.').');

        return self::SUCCESS;
    }

    private function tick(LoadTest $loadTest): int
    {
        $sent = $loadTest->tick();

        if ($sent > 0) {
            $this->info("Wave queued: {$sent} new chats.");
        }

        return self::SUCCESS;
    }

    private function status(LoadTest $loadTest): int
    {
        $s = $loadTest->status();

        if ($s === null) {
            $this->info('No active load test.');

            return self::SUCCESS;
        }

        $run = $s['run'];
        $plan = $run->plan;

        $this->info("Run #{$run->id} active since ".$this->cairo($run->started_at).' (Cairo).');
        $this->line(is_array($plan)
            ? "Plan: every {$plan['every']} min, {$plan['count']} per wave, spread {$plan['spread']} min, for {$plan['hours']} h"
                .($run->next_wave_at ? '; next wave at '.$this->cairo($run->next_wave_at) : '; no more waves')
            : 'Plan: none (backlog only).');
        $this->line("Waves: {$run->waves_done} of {$s['waves_total']}");
        $this->line("Openers sent: {$run->openers_sent}");
        $this->line("Backlog seeded: {$run->seeded}");
        $this->line("Follow-ups sent: {$run->followups_sent}");
        $this->line("Test chats: {$s['chats']} (open {$s['open']}, closed {$s['closed']})");

        return self::SUCCESS;
    }

    private function stop(LoadTest $loadTest): int
    {
        $run = $loadTest->stop();

        $this->info($run === null
            ? 'No active load test.'
            : "Stopped run #{$run->id}: no more waves; queued openers and follow-ups will do nothing.");

        if ($this->option('close')) {
            $closed = $loadTest->closeAll();
            $this->info("Closed {$closed} load-test chats: their tickets left the agents' windows and the lounge (no closing message, no rating).");
        }

        return self::SUCCESS;
    }

    private function cairo(?\DateTimeInterface $at): string
    {
        return $at === null ? '-' : CarbonImmutable::instance($at)->setTimezone(LoadTest::TZ)->format('Y-m-d H:i');
    }
}
