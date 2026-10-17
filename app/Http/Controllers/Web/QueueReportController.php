<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Http\Support\DateRange;
use App\Queue\Reports\QueueReport;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Reports › «الطابور» (queue Part 2, §5.4): the queue's report for any range of business days
 * (≤ 92), and its CSV. Supervisors and admins (the route group); a moderator sees her own points
 * on /reports/me.
 */
class QueueReportController extends Controller
{
    public function index(Request $request, QueueReport $report): Response
    {
        $range = $this->range($request);

        return Inertia::render('Reports/Queue', [
            'range' => ['from' => $range->fromDate, 'to' => $range->toDate],
            'report' => $report->build($range->fromDate, $range->toDate),
        ]);
    }

    public function export(Request $request, QueueReport $report): StreamedResponse
    {
        $range = $this->range($request);
        $rows = $report->csvRows($report->build($range->fromDate, $range->toDate));

        return response()->streamDownload(function () use ($rows) {
            $out = fopen('php://output', 'w');
            fwrite($out, "\u{FEFF}"); // Excel opens UTF-8 Arabic correctly with a BOM
            foreach ($rows as $row) {
                fputcsv($out, $row);
            }
            fclose($out);
        }, "queue-{$range->fromDate}-{$range->toDate}.csv", ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    private function range(Request $request): DateRange
    {
        $range = DateRange::fromRequest($request);
        $days = CarbonImmutable::parse($range->fromDate)->diffInDays(CarbonImmutable::parse($range->toDate)) + 1;

        if ($days > QueueReport::MAX_DAYS) {
            throw ValidationException::withMessages(['to' => __('errors.queue.report_range', ['n' => QueueReport::MAX_DAYS])]);
        }

        return $range;
    }
}
