<?php

namespace App\Simulator\Commands;

use App\Simulator\LoadTest\LoadTest;
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
        {--spread=10 : Minutes one wave is spread over}';

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
            default => $this->notYet($action),
        };
    }

    private function seedYesterday(LoadTest $loadTest): int
    {
        $count = max(1, min(1000, (int) $this->option('count')));
        $seeded = $loadTest->seedYesterday($count);

        $this->info("Seeded {$seeded} chats from yesterday evening, handed over and waiting for a person.");

        return self::SUCCESS;
    }

    private function notYet(string $action): int
    {
        $this->error("«{$action}» is not available yet.");

        return self::FAILURE;
    }
}
