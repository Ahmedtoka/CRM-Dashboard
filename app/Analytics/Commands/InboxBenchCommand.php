<?php

namespace App\Analytics\Commands;

use App\Analytics\Bench\InboxBenchScenarios;
use App\Models\Conversation;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * Times the inbox JSON endpoints in-process (no network) with a query counter, so the UI overhaul
 * has before/after numbers on the same dataset (spec §1.3). Local/staging on a database named *load* only (it writes presence/latency rows).
 */
class InboxBenchCommand extends Command
{
    protected $signature = 'crm:inbox-bench {--user= : Email of the user the requests run as}
        {--label=baseline} {--runs=30} {--warmup=3} {--output-dir= : Defaults to base_path("docs/perf")}';

    protected $description = 'Measure inbox list/detail/messages endpoints: p50/p95 ms and SQL queries per request';

    private int $queries = 0;

    public function handle(): int
    {
        $environment = app()->environment();
        $database = (string) config('database.connections.'.config('database.default').'.database');

        if (! in_array($environment, ['local', 'staging'], true) || ! str_contains(strtolower($database), 'load')) {
            $this->error(sprintf(
                'Refusing to run crm:inbox-bench: requires APP_ENV in [local, staging] (got "%s") and a database name containing "load", e.g. crm_perf_load (got "%s").',
                $environment,
                $database,
            ));

            return self::FAILURE;
        }
        $user = User::query()->where('email', (string) $this->option('user'))->first();
        if ($user === null) {
            $this->error('Unknown --user.');

            return self::FAILURE;
        }

        $hot = Conversation::query()->withCount('messages')->orderByDesc('messages_count')->first();
        if ($hot === null) {
            $this->error('No conversations to measure.');

            return self::FAILURE;
        }
        $typical = Conversation::query()->orderByDesc('last_message_at')->skip(5)->first() ?? $hot;
        $hotIds = $hot->messages()->orderBy('id')->pluck('id');
        $ctx = [
            'hot_id' => (int) $hot->id,
            'typical_id' => (int) $typical->id,
            'tag_id' => DB::table('tags')->value('id'),
            'moderator_id' => Conversation::query()->whereNotNull('last_responder_id')->value('last_responder_id'),
            'mid_message_id' => $hotIds->count() > 1 ? $hotIds[intdiv($hotIds->count(), 2)] : null,
            'recent_message_id' => $hotIds->count() > 5 ? $hotIds[$hotIds->count() - 5] : null,
        ];

        $runs = max(1, (int) $this->option('runs'));
        $warmup = max(0, (int) $this->option('warmup'));
        $results = [];

        // Laravel cannot unregister a listener: register one and reset the counter per request.
        DB::listen(function () {
            $this->queries++;
        });

        foreach (InboxBenchScenarios::for($ctx) as $s) {
            $times = [];
            $queries = [];
            $status = 0;
            for ($i = 0; $i < $warmup + $runs; $i++) {
                [$ms, $q, $status] = $this->once($user, $s['uri']);
                if ($i >= $warmup) {
                    $times[] = $ms;
                    $queries[] = $q;
                }
            }
            sort($times);
            $row = [
                'name' => $s['name'], 'uri' => $s['uri'], 'status' => $status, 'runs' => $runs,
                'p50_ms' => round($this->pct($times, 50), 1), 'p95_ms' => round($this->pct($times, 95), 1),
                'max_ms' => round(max($times), 1),
                'queries_avg' => round(array_sum($queries) / count($queries), 1), 'queries_max' => max($queries),
            ];
            $results[] = $row;
            $this->line(sprintf('%-28s %3d  p50 %7.1f  p95 %7.1f  q %5.1f', $s['name'], $status, $row['p50_ms'], $row['p95_ms'], $row['queries_avg']));
        }

        $this->write($results);

        return self::SUCCESS;
    }

    /** @return array{0: float, 1: int, 2: int} */
    private function once(User $user, string $uri): array
    {
        $this->queries = 0;

        $request = Request::create($uri, 'GET', server: ['HTTP_ACCEPT' => 'application/json', 'HTTP_X_REQUESTED_WITH' => 'XMLHttpRequest']);
        $request->setLaravelSession(app('session')->driver());
        Auth::guard('web')->setUser($user);
        $kernel = app(Kernel::class);
        $t = hrtime(true);
        $response = $kernel->handle($request);
        $ms = (hrtime(true) - $t) / 1e6;
        $queries = $this->queries;
        $kernel->terminate($request, $response);

        return [$ms, $queries, $response->getStatusCode()];
    }

    /** @param list<float> $sorted */
    private function pct(array $sorted, int $p): float
    {
        $idx = (int) ceil($p / 100 * count($sorted)) - 1;

        return $sorted[max(0, min(count($sorted) - 1, $idx))];
    }

    /** @param list<array<string, mixed>> $results */
    private function write(array $results): void
    {
        $dir = (string) ($this->option('output-dir') ?: base_path('docs/perf'));
        @mkdir($dir, 0775, true);
        $label = (string) $this->option('label');
        $payload = [
            'label' => $label, 'at' => now()->toIso8601String(),
            'database' => DB::connection()->getDatabaseName(), 'driver' => DB::connection()->getDriverName(),
            'counts' => ['conversations' => DB::table('conversations')->count(), 'messages' => DB::table('messages')->count()],
            'scenarios' => $results,
        ];
        file_put_contents("{$dir}/inbox-{$label}.json", json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

        $md = "# Inbox bench — {$label}\n\n{$payload['driver']} `{$payload['database']}` · {$payload['counts']['conversations']} conversations · {$payload['counts']['messages']} messages · {$payload['at']}\n\n";
        $md .= "| scenario | status | p50 ms | p95 ms | max ms | queries avg | queries max |\n|---|---|---|---|---|---|---|\n";
        foreach ($results as $r) {
            $md .= "| {$r['name']} | {$r['status']} | {$r['p50_ms']} | {$r['p95_ms']} | {$r['max_ms']} | {$r['queries_avg']} | {$r['queries_max']} |\n";
        }
        file_put_contents("{$dir}/inbox-{$label}.md", $md);
    }
}
