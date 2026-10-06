<?php

namespace App\Http\Controllers\Web;

use App\Analytics\ActivityLogger;
use App\Analytics\LatencyRecorder;
use App\Analytics\MetricsService;
use App\Analytics\PresenceTracker;
use App\Analytics\QuickReplyReport;
use App\Enums\Platform;
use App\Http\Controllers\Concerns\ReportEndpoints;
use App\Http\Controllers\Controller;
use App\Http\Resources\ActivityLogResource;
use App\Http\Support\DateRange;
use App\Models\ActivityLog;
use App\Models\BotTestLink;
use App\Models\BotTestSession;
use App\Models\User;
use App\TestLinks\TestLinkReport;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use ReflectionClass;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ReportController extends Controller
{
    use ReportEndpoints;

    public function team(Request $request, MetricsService $metrics, PresenceTracker $presence): Response
    {
        return Inertia::render('Reports/Team', $this->teamReport($request, $metrics, $presence));
    }

    public function user(Request $request, MetricsService $metrics, User $user): Response
    {
        return Inertia::render('Reports/User', $this->userReport($request, $metrics, $user));
    }

    public function me(Request $request, MetricsService $metrics): Response
    {
        return Inertia::render('Reports/Me', $this->userReport($request, $metrics, $request->user()));
    }

    public function bot(Request $request, MetricsService $metrics): Response
    {
        return Inertia::render('Reports/Bot', $this->botReport($request, $metrics));
    }

    /**
     * Spec §11.3 / §11.5 step 7 — percentiles per kind against the exact acceptance
     * targets, over a selectable recent window. Admin-only: shows real production
     * numbers moderators/supervisors don't need day to day.
     */
    public function latency(Request $request, LatencyRecorder $recorder): Response
    {
        $data = $request->validate([
            'window' => ['nullable', Rule::in(['15m', '1h', '24h', 'custom'])],
            'from' => ['nullable', 'date_format:Y-m-d\TH:i'],
            'to' => ['nullable', 'date_format:Y-m-d\TH:i'],
        ]);

        [$window, $from, $to] = $this->resolveLatencyWindow($data);

        $targets = (array) config('crm.latency.targets', ['inbound' => 2000, 'outbound' => 1500, 'list' => 300]);

        $kinds = [];
        foreach (['inbound', 'outbound', 'list'] as $kind) {
            $percentiles = $recorder->percentiles($kind, $from, $to);
            $target = (int) ($targets[$kind] ?? 0);

            $kinds[$kind] = [
                ...$percentiles,
                'target' => $target,
                // Ruling: PASS when count > 0 and p95 <= target; no data => neither pass nor fail.
                'pass' => $percentiles['count'] > 0 ? $percentiles['p95'] <= $target : null,
            ];
        }

        return Inertia::render('Reports/Latency', [
            'window' => $window,
            'range' => ['from' => $from->toIso8601String(), 'to' => $to->toIso8601String()],
            'targets' => $targets,
            'kinds' => $kinds,
        ]);
    }

    /**
     * @param  array{window?: string, from?: string, to?: string}  $data
     * @return array{0: string, 1: CarbonImmutable, 2: CarbonImmutable}
     */
    private function resolveLatencyWindow(array $data): array
    {
        $window = $data['window'] ?? '1h';

        if ($window === 'custom' && ! empty($data['from']) && ! empty($data['to'])) {
            $tz = (string) config('crm.timezone_display', 'Africa/Cairo');
            $from = CarbonImmutable::createFromFormat('Y-m-d\TH:i', $data['from'], $tz)->utc();
            $to = CarbonImmutable::createFromFormat('Y-m-d\TH:i', $data['to'], $tz)->utc();

            if ($to->lt($from)) {
                [$from, $to] = [$to, $from];
            }

            return ['custom', $from, $to];
        }

        // No usable custom bounds (or a non-custom preset): fall back to a relative window.
        $window = $window === 'custom' ? '1h' : $window;
        $to = CarbonImmutable::now();
        $from = match ($window) {
            '15m' => $to->subMinutes(15),
            '24h' => $to->subDay(),
            default => $to->subHour(),
        };

        return [$window, $from, $to];
    }

    /**
     * Saved replies usage report (spec §2.3, §6): stats only — reply bodies never
     * appear here — ranked by usage, broken down per agent, plus replies unused
     * in the last 30 days. Supervisor+ only, same platform filter as other reports.
     */
    public function quickReplies(Request $request, QuickReplyReport $report): Response
    {
        $range = DateRange::fromRequest($request);
        $platform = $this->reportPlatform($request);

        return Inertia::render('Reports/QuickReplies', [
            'range' => $range->toArray(),
            'platform' => $platform?->value,
            'top' => $report->top($range, $platform),
            'perAgent' => $report->perAgent($range, $platform),
            'unused' => $report->unused(30),
        ]);
    }

    public function quickRepliesExport(Request $request, QuickReplyReport $report): StreamedResponse
    {
        $range = DateRange::fromRequest($request);
        $platform = $this->reportPlatform($request);

        return response()->streamDownload(function () use ($report, $range, $platform) {
            $out = fopen('php://output', 'w');
            fwrite($out, "\u{FEFF}"); // Excel opens UTF-8 Arabic correctly with a BOM
            foreach ($report->csvRows($range, $platform) as $row) {
                fputcsv($out, $row);
            }
            fclose($out);
        }, "quick-replies-{$range->fromDate}-{$range->toDate}.csv", ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    /**
     * Reports → «تجربة الفريق» (design 2026-09-21 §4): every run of the team's test
     * links — who, how long, how far into which flow, what it produced — plus a funnel
     * per flow and a readable transcript.
     */
    public function teamTest(Request $request, TestLinkReport $report): Response
    {
        $link = $this->testLink($request);
        $data = $report->forLink($link);

        return Inertia::render('Reports/TeamTest', [
            'links' => $report->links(),
            'linkId' => $link?->id,
            'sessions' => $data['sessions'],
            'totals' => $data['totals'],
            'funnels' => $data['funnels'],
        ]);
    }

    /** The readable transcript of one run, with its link back into the inbox. */
    public function teamTestSession(Request $request, BotTestSession $session, TestLinkReport $report): JsonResponse
    {
        return response()->json(['data' => [
            'id' => $session->id,
            'label' => $session->label(),
            'conversation_id' => $session->conversation_id,
            'transcript' => $report->transcript($session),
        ]]);
    }

    public function teamTestExport(Request $request, TestLinkReport $report): StreamedResponse
    {
        $link = $this->testLink($request);
        $sessions = $report->sessions($link);
        $name = 'team-test-'.($link?->id ?? 'all').'-'.now()->format('Y-m-d').'.csv';

        return response()->streamDownload(function () use ($report, $sessions) {
            $out = fopen('php://output', 'w');
            fwrite($out, "\u{FEFF}"); // Excel opens UTF-8 Arabic correctly with a BOM
            foreach ($report->csvRows($sessions) as $row) {
                fputcsv($out, $row);
            }
            fclose($out);
        }, $name, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    /** The link the page is filtered to, or null for "every link". */
    private function testLink(Request $request): ?BotTestLink
    {
        $id = (int) $request->query('link');

        return $id > 0 ? BotTestLink::query()->find($id) : null;
    }

    public function activity(Request $request): Response
    {
        $actions = array_values((new ReflectionClass(ActivityLogger::class))->getConstants());

        $filters = $request->validate([
            'user_id' => ['nullable', 'integer'],
            'action' => ['nullable', Rule::in($actions)],
            'platform' => ['nullable', Rule::enum(Platform::class)],
        ]);
        $range = DateRange::fromRequest($request);

        $logs = ActivityLog::query()
            ->with('user')
            ->whereBetween('created_at', [$range->from, $range->to])
            ->when($filters['user_id'] ?? null, fn ($q, $v) => $q->where('user_id', $v))
            ->when($filters['action'] ?? null, fn ($q, $v) => $q->where('action', $v))
            ->when($filters['platform'] ?? null, fn ($q, $v) => $q->where('platform', $v))
            ->orderByDesc('id')
            ->paginate(50)
            ->withQueryString();

        return Inertia::render('Reports/Activity', [
            'logs' => ActivityLogResource::collection($logs),
            'range' => $range->toArray(),
            'filters' => array_merge(['user_id' => null, 'action' => null, 'platform' => null], $filters),
            'users' => User::orderBy('name')->get(['id', 'name', 'color']),
            'actions' => $actions,
        ]);
    }
}
