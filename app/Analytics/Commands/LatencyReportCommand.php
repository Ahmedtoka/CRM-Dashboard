<?php

namespace App\Analytics\Commands;

use App\Analytics\LatencyRecorder;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;

/**
 * Writes docs/perf/staging-report.md: percentiles per kind against the exact
 * acceptance targets (spec §11.3), with a PASS/FAIL line per target so the
 * report answers "are we ready for real use" at a glance.
 */
class LatencyReportCommand extends Command
{
    protected $signature = 'crm:latency-report {--from=} {--to=} {--output=docs/perf/staging-report.md}';

    protected $description = 'Write a Markdown latency report comparing measured percentiles against the acceptance targets';

    /**
     * kind => label. Targets themselves come from config('crm.latency.targets') — the
     * single source of truth shared with the /reports/latency page — not hardcoded here.
     *
     * @var array<string, string>
     */
    private const LABELS = [
        'inbound' => 'Inbound webhook -> inbox broadcast',
        'outbound' => 'Moderator send -> provider call',
        'list' => 'Inbox list/filter/search',
    ];

    /** Default targets, only used if config('crm.latency.targets') is missing a kind. */
    private const DEFAULT_TARGETS = [
        'inbound' => 2000,
        'outbound' => 1500,
        'list' => 300,
    ];

    public function handle(LatencyRecorder $recorder): int
    {
        $to = $this->option('to') ? CarbonImmutable::parse((string) $this->option('to')) : CarbonImmutable::now();
        $from = $this->option('from') ? CarbonImmutable::parse((string) $this->option('from')) : $to->subDay();

        $targets = (array) config('crm.latency.targets', self::DEFAULT_TARGETS);

        $rows = [];
        $allPass = true;

        foreach (self::LABELS as $kind => $label) {
            $target = (int) ($targets[$kind] ?? self::DEFAULT_TARGETS[$kind]);
            $p = $recorder->percentiles($kind, $from, $to);
            $pass = $p['p95'] <= $target;
            $allPass = $allPass && $pass;

            $rows[] = [
                'label' => $label,
                'count' => $p['count'],
                'p50' => $p['p50'],
                'p95' => $p['p95'],
                'p99' => $p['p99'],
                'max' => $p['max'],
                'target' => $target,
                'status' => $pass ? 'PASS' : 'FAIL',
            ];
        }

        // Queue wait is driver-agnostic (recorded from Queue::before for every connection,
        // not just the database driver) and informational only — no acceptance target.
        $queue = $recorder->percentiles('queue', $from, $to);

        $failedJobs = Schema::hasTable('failed_jobs') ? DB::table('failed_jobs')->count() : 0;

        $report = $this->buildMarkdown($from, $to, $rows, $queue, $failedJobs, $allPass);

        $path = $this->resolveOutputPath((string) $this->option('output'));
        File::ensureDirectoryExists(dirname($path));
        File::put($path, $report);

        $this->info("Wrote latency report to {$path}");

        foreach ($rows as $row) {
            $this->line("{$row['label']}: p95={$row['p95']}ms target={$row['target']}ms [{$row['status']}]");
        }

        $this->line("Queue wait: p95={$queue['p95']}ms max={$queue['max']}ms [count={$queue['count']}]");
        $this->line($allPass ? 'Overall: PASS' : 'Overall: FAIL');

        return $allPass ? self::SUCCESS : self::FAILURE;
    }

    /**
     * @param  array<int, array{label:string,count:int,p50:int,p95:int,p99:int,max:int,target:int,status:string}>  $rows
     * @param  array{count:int,p50:int,p95:int,p99:int,max:int}  $queue
     */
    private function buildMarkdown(CarbonImmutable $from, CarbonImmutable $to, array $rows, array $queue, int $failedJobs, bool $allPass): string
    {
        $lines = [];
        $lines[] = '# Staging Latency Report';
        $lines[] = '';
        $lines[] = 'Generated: '.CarbonImmutable::now()->toIso8601String();
        $lines[] = 'Window: '.$from->toIso8601String().' to '.$to->toIso8601String();
        $lines[] = '';
        $lines[] = '## Environment';
        $lines[] = '- PHP: '.PHP_VERSION;
        $lines[] = '- Queue driver: '.config('queue.default');
        $lines[] = '- Workers: '.config('crm.latency.workers_note');
        $lines[] = '';
        $lines[] = '## Percentiles vs targets';
        $lines[] = '';
        $lines[] = '| Metric | Count | p50 (ms) | p95 (ms) | p99 (ms) | Max (ms) | Target p95 (ms) | Result |';
        $lines[] = '|---|---|---|---|---|---|---|---|';

        foreach ($rows as $row) {
            $lines[] = sprintf(
                '| %s | %d | %d | %d | %d | %d | %d | %s |',
                $row['label'],
                $row['count'],
                $row['p50'],
                $row['p95'],
                $row['p99'],
                $row['max'],
                $row['target'],
                $row['status'],
            );
        }

        $lines[] = '';
        $lines[] = '## Queue wait (informational — driver-agnostic, no acceptance target)';
        $lines[] = '';
        $lines[] = '| Metric | Count | p50 (ms) | p95 (ms) | p99 (ms) | Max (ms) |';
        $lines[] = '|---|---|---|---|---|---|';
        $lines[] = sprintf(
            '| Queue wait (push -> worker pickup) | %d | %d | %d | %d | %d |',
            $queue['count'],
            $queue['p50'],
            $queue['p95'],
            $queue['p99'],
            $queue['max'],
        );

        $lines[] = '';
        $lines[] = '## Reliability';
        $lines[] = '';
        $lines[] = "- Failed jobs: {$failedJobs}";
        $lines[] = '';
        $lines[] = '## Overall: '.($allPass ? 'PASS' : 'FAIL');
        $lines[] = '';

        return implode("\n", $lines);
    }

    private function resolveOutputPath(string $output): string
    {
        if ($this->isAbsolute($output)) {
            return $output;
        }

        // Ruling: the default path is relative to the repo root, one level above backend/.
        return dirname(base_path()).DIRECTORY_SEPARATOR.$output;
    }

    private function isAbsolute(string $path): bool
    {
        return (bool) preg_match('#^([A-Za-z]:[\\\\/]|/)#', $path);
    }
}
