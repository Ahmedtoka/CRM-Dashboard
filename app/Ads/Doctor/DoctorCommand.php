<?php

namespace App\Ads\Doctor;

use App\Ads\Doctor\Checks\AccountsCheck;
use App\Ads\Doctor\Checks\AppCheck;
use App\Ads\Doctor\Checks\FailedJobsCheck;
use App\Ads\Doctor\Checks\PeopleCheck;
use App\Ads\Doctor\Checks\QueueCheck;
use App\Ads\Doctor\Checks\QuotaCheck;
use App\Ads\Doctor\Checks\SchedulerCheck;
use App\Ads\Doctor\Checks\SyncRunsCheck;
use App\Ads\Doctor\Checks\TokenCheck;
use Illuminate\Console\Command;

/**
 * Read-only server diagnostics for the Ads Hub (Phase 0). Writes nothing (no row, not even an audit row, no cache key) and
 * only sends GET requests to Meta; it never prints a token. Exit code 1 when any row is `fail`.
 */
class DoctorCommand extends Command
{
    protected $signature = 'ads:doctor {--markdown : Print Markdown tables for the facts sheet} {--no-network : Do not call Meta} {--account=* : Limit to these ad account ids or external ids}';

    protected $description = 'Read-only diagnostics of the ads pipeline on this server (queue, scheduler, sync, tokens, quota, accounts)';

    /** @var list<class-string<Checks\DoctorCheck>> */
    private const CHECKS = [AppCheck::class, QueueCheck::class, SchedulerCheck::class, FailedJobsCheck::class, SyncRunsCheck::class, TokenCheck::class, QuotaCheck::class, AccountsCheck::class, PeopleCheck::class];

    public function handle(): int
    {
        $ctx = new DoctorContext(! $this->option('no-network'), array_values(array_filter(array_map('strval', (array) $this->option('account')))));

        $rows = [];
        foreach (self::CHECKS as $class) {
            array_push($rows, ...(new $class($ctx))->safely());
        }

        $this->option('markdown') ? $this->markdown($rows) : $this->console($rows);

        $fails = count(array_filter($rows, fn (DoctorRow $r) => $r->status === 'fail'));
        $this->line('');
        $this->line(count($rows).' checks, '.$fails.' fail, '.count(array_filter($rows, fn (DoctorRow $r) => $r->status === 'warn')).' warn.');

        return $fails > 0 ? self::FAILURE : self::SUCCESS;
    }

    /** @param  list<DoctorRow>  $rows */
    private function console(array $rows): void
    {
        $bySection = [];
        foreach ($rows as $r) {
            $bySection[$r->section][] = $r;
        }
        foreach ($bySection as $section => $list) {
            $this->line('');
            $this->info($section);
            $this->table(['Check', 'Status', 'Value', 'Hint'], array_map(fn (DoctorRow $r) => [$r->check, strtoupper($r->status), $r->value, $r->hint], $list));
        }
    }

    /** @param  list<DoctorRow>  $rows */
    private function markdown(array $rows): void
    {
        $cell = fn (string $v) => str_replace(['|', "\r", "\n"], ['\\|', ' ', ' '], $v);
        $this->line('| Section | Check | Status | Value | Hint |');
        $this->line('|---|---|---|---|---|');
        foreach ($rows as $r) {
            $this->line('| '.implode(' | ', array_map($cell, [$r->section, $r->check, $r->status, $r->value, $r->hint])).' |');
        }
    }
}
