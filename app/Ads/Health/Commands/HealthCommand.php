<?php

namespace App\Ads\Health\Commands;

use App\Ads\Health\DataHealth;
use App\Ads\Health\HealthCheck;
use Illuminate\Console\Command;

/** Every five minutes: evaluate the ads data health, store the states and announce held transitions. */
class HealthCommand extends Command
{
    protected $signature = 'ads:health';

    protected $description = 'Check ads sync, tokens, queues and scheduler; notify the admins when a status holds';

    public function handle(DataHealth $health): int
    {
        $bad = array_filter($health->run(), fn (HealthCheck $c) => $c->isBad());
        if ($bad === []) {
            $this->line('Ads data health: all checks ok.');

            return self::SUCCESS;
        }

        foreach ($bad as $c) {
            $this->line(sprintf('%-9s %s%s', strtoupper($c->status), $c->key, isset($c->detail['account']) ? ' ('.$c->detail['account'].')' : ''));
        }

        return self::SUCCESS;
    }
}
