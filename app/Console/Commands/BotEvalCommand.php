<?php

namespace App\Console\Commands;

use App\Bot\Eval\BotEval;
use Illuminate\Console\Command;

/**
 * `php artisan bot:eval` (bot-hybrid.md §6): runs the 44 bot scenarios through the hybrid router and
 * prints pass rates. Offline by default — keyword planner, canned model replies, no network. With
 * --live the real Anthropic API answers (it costs money: confirm, or pass --force). Nothing is ever
 * sent to a customer or saved: each scenario runs in a rolled-back transaction.
 */
class BotEvalCommand extends Command
{
    protected $signature = 'bot:eval
        {file? : scenarios YAML (default tests/Fixtures/bot-eval/scenarios.yaml)}
        {--repeat=1 : runs per scenario (safety scenarios must pass all of them)}
        {--only= : comma-separated scenario ids}
        {--live : use the real Anthropic API (costs money)}
        {--force : with --live, do not ask}
        {--json= : also write the full report to this path}';

    protected $description = 'Run the bot eval scenarios (offline by default) and report pass rates';

    public function handle(BotEval $eval): int
    {
        $file = (string) ($this->argument('file') ?: base_path('tests/Fixtures/bot-eval/scenarios.yaml'));
        $live = (bool) $this->option('live');

        if (! is_file($file)) {
            $this->error("No scenarios file at {$file}");

            return self::FAILURE;
        }

        if ($live && blank(config('crm.anthropic.key'))) {
            $this->error('--live needs ANTHROPIC_API_KEY.');

            return self::FAILURE;
        }

        if ($live && ! $this->option('force') && ! $this->confirm('This calls the real Anthropic API (Haiku/Sonnet) for every scenario and costs money. Go on?')) {
            return self::FAILURE;
        }

        $only = filled($this->option('only')) ? array_map('intval', explode(',', (string) $this->option('only'))) : null;
        $report = $eval->run($file, $live, max(1, (int) $this->option('repeat')), $only);

        $this->table(['#', 'category', 'result', 'tier', 'flow', 'why'], array_map(fn ($r) => [
            $r['id'],
            $r['category'].($r['safety'] ? ' (safety)' : ''),
            ($r['ok'] ? 'PASS' : 'FAIL')." {$r['passed']}/{$r['runs']}",
            $r['last']['observed']['tier'] ?? '-',
            $r['last']['observed']['flow'] ?? '-',
            mb_substr(implode('; ', $r['last']['failures']), 0, 90),
        ], $report['scenarios']));

        $s = $report['summary'];
        $this->newLine();
        $this->line(sprintf('Mode: %s · scenarios: %d · pass: %s%% · safety: %s%% · multi-return: %s%% · p95 latency: %s ms · cost: $%s',
            $live ? 'LIVE' : 'offline', $s['scenarios'], $s['pass_rate'] ?? '-', $s['safety_pass_rate'] ?? '-', $s['multi_return_pass_rate'] ?? '-', $s['p95_latency_ms'] ?? '-', $s['cost_usd']));

        foreach ($s['categories'] as $category => $rate) {
            $this->line("  {$category}: {$rate}%");
        }

        if (filled($this->option('json'))) {
            file_put_contents((string) $this->option('json'), json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
            $this->info('Report written to '.$this->option('json'));
        }

        return self::SUCCESS;
    }
}
