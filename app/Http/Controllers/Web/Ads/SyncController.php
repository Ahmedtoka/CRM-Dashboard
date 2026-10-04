<?php

namespace App\Http\Controllers\Web\Ads;

use App\Ads\Sync\QueueInspector;
use App\Http\Controllers\Controller;
use App\Models\AdAccount;
use App\Models\AdsSyncRun;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/** What the ad syncs are doing now, what is waiting, the recent log and when the next scheduled runs are due. Supervisor and up. */
class SyncController extends Controller
{
    public function index(Request $request, QueueInspector $queue): Response
    {
        $filters = $request->validate([
            'account' => ['nullable', 'integer'],
            'status' => ['nullable', 'in:running,ok,error'],
            'trigger' => ['nullable', 'in:schedule,manual,backfill,setup'],
        ]);

        $query = fn () => AdsSyncRun::query()->with(['account:id,name', 'triggeredBy:id,name']);

        $runs = $query()
            ->when($filters['account'] ?? null, fn ($q, $id) => $q->where('ad_account_id', $id))
            ->when($filters['status'] ?? null, fn ($q, $s) => $q->where('status', $s))
            ->when($filters['trigger'] ?? null, fn ($q, $t) => $q->where('trigger', $t))
            ->orderByDesc('started_at')->orderByDesc('id')->limit(100)->get();

        return Inertia::render('Ads/Sync', [
            'now' => [
                'running' => $query()->where('status', 'running')->orderBy('started_at')->get()->map(fn (AdsSyncRun $r) => $this->run($r))->values(),
                'waiting' => $queue->waiting(),
                'supported' => $queue->supported(),
            ],
            'runs' => $runs->map(fn (AdsSyncRun $r) => $this->run($r))->values(),
            'filters' => ['account' => $filters['account'] ?? null, 'status' => $filters['status'] ?? null, 'trigger' => $filters['trigger'] ?? null],
            'schedule' => $this->schedule(),
            'accounts' => AdAccount::query()->orderBy('name')->get(['id', 'name', 'platform'])->map(fn (AdAccount $a) => ['id' => $a->id, 'name' => $a->name, 'platform' => $a->platform])->values(),
        ]);
    }

    /** @return array<string, mixed> */
    private function run(AdsSyncRun $r): array
    {
        $end = $r->finished_at ?? now();

        return [
            'id' => $r->id,
            'account' => $r->account?->name,
            'platform' => $r->platform,
            'kind' => $r->kind,
            'status' => $r->status,
            'from' => $r->from_date?->toDateString(),
            'to' => $r->to_date?->toDateString(),
            'ads_count' => $r->ads_count,
            'rows_count' => $r->rows_count,
            'error' => $r->error,
            'trigger' => $r->trigger,
            'user' => $r->triggeredBy?->name,
            'started_at' => $r->started_at?->toIso8601String(),
            'finished_at' => $r->finished_at?->toIso8601String(),
            'seconds' => $r->started_at ? max(0, (int) $r->started_at->diffInSeconds($end, true)) : null,
        ];
    }

    /** @return list<array{command: string, next_due: string}> */
    private function schedule(): array
    {
        return collect(app(Schedule::class)->events())
            ->filter(fn ($e) => str_contains((string) $e->command, 'ads:'))
            ->map(fn ($e) => [
                'command' => trim((string) preg_replace('/^.*artisan[\'"]?\s+/', '', (string) $e->command)),
                'next_due' => $e->nextRunDate()->setTimezone('Africa/Cairo')->toIso8601String(),
            ])->values()->all();
    }
}
