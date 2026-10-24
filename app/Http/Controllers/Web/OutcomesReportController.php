<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Http\Support\DateRange;
use App\Models\User;
use App\Queue\Reports\OutcomesReport;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Reports › الفريق › «نتائج المحادثات» (2026-10-24): what happened in the chats that ended in a
 * range of Cairo days (≤ 92), per day / week / month and per moderator, and its CSV. Supervisors
 * and admins (the route group).
 */
class OutcomesReportController extends Controller
{
    public function index(Request $request, OutcomesReport $report): Response
    {
        [$range, $group, $userId] = $this->filters($request);

        return Inertia::render('Reports/Outcomes', [
            'range' => ['from' => $range->fromDate, 'to' => $range->toDate],
            'group' => $group,
            'userId' => $userId,
            'moderators' => User::query()->whereIn('role', ['moderator', 'supervisor'])->orderBy('name')->get(['id', 'name', 'color'])
                ->map(fn (User $u) => ['id' => $u->id, 'name' => $u->name, 'color' => $u->color])->values(),
            'report' => $report->build($range->fromDate, $range->toDate, $group, $userId),
        ]);
    }

    public function export(Request $request, OutcomesReport $report): StreamedResponse
    {
        [$range, $group, $userId] = $this->filters($request);
        $rows = $report->csvRows($report->build($range->fromDate, $range->toDate, $group, $userId));

        return response()->streamDownload(function () use ($rows) {
            $out = fopen('php://output', 'w');
            fwrite($out, "\u{FEFF}"); // Excel opens UTF-8 Arabic correctly with a BOM
            foreach ($rows as $row) {
                fputcsv($out, $row);
            }
            fclose($out);
        }, "outcomes-{$range->fromDate}-{$range->toDate}.csv", ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    /** @return array{0: DateRange, 1: string, 2: ?int} */
    private function filters(Request $request): array
    {
        $range = DateRange::fromRequest($request);
        $days = CarbonImmutable::parse($range->fromDate)->diffInDays(CarbonImmutable::parse($range->toDate)) + 1;

        if ($days > OutcomesReport::MAX_DAYS) {
            throw ValidationException::withMessages(['to' => __('errors.queue.report_range', ['n' => OutcomesReport::MAX_DAYS])]);
        }

        $data = $request->validate([
            'group' => ['nullable', 'string', Rule::in(OutcomesReport::GROUPS)],
            'user' => ['nullable', 'integer'],
        ]);

        return [$range, $data['group'] ?? 'day', isset($data['user']) ? (int) $data['user'] : null];
    }
}
