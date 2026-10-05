<?php

namespace App\Ads\Health\Commands;

use App\Ads\AdsSettings;
use App\Ads\Audit\AdsAudit;
use Illuminate\Console\Command;

/**
 * The Phase A gate switch. Until --pass is run every Ads page says the numbers are under review; --reopen puts the
 * notice back. Both are audited. The owner runs --pass after comparing the reconcile report with Ads Manager.
 */
class GateCommand extends Command
{
    public const KEY = 'numbers_verified_at';

    protected $signature = 'ads:gate {--pass : Mark the numbers as verified (removes the under-review notice)} {--reopen : Put the under-review notice back} {--note= : Why (kept in the audit log)}';

    protected $description = 'Pass or reopen the Phase A numbers gate';

    public function handle(AdsSettings $settings): int
    {
        $pass = (bool) $this->option('pass');
        $reopen = (bool) $this->option('reopen');
        if ($pass === $reopen) {
            $this->error('Pass exactly one of --pass or --reopen.');

            return self::FAILURE;
        }

        $before = $settings->get(self::KEY);
        $note = trim((string) $this->option('note'));

        if ($pass) {
            $at = now()->toIso8601String();
            $settings->set(self::KEY, $at);
            AdsAudit::record('gate.passed', null, ['numbers_verified_at' => $before], ['numbers_verified_at' => $at], $note === '' ? [] : ['note' => $note]);
            $this->info('Gate passed: the under-review notice is off.');

            return self::SUCCESS;
        }

        $settings->set(self::KEY, null);
        AdsAudit::record('gate.reopened', null, ['numbers_verified_at' => $before], ['numbers_verified_at' => null], $note === '' ? [] : ['note' => $note]);
        $this->info('Gate reopened: the under-review notice is back.');

        return self::SUCCESS;
    }
}
