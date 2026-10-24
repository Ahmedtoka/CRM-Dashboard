<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Http\Support\DateRange;
use App\Queue\Reports\TeamReport;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The CSV of the leader's report («الموظفات في الطابور» on /reports/team): the same rows the page
 * shows, for a range of at most TeamReport::MAX_DAYS business days. Supervisors and admins (the
 * route group); the page itself is rendered by ReportController::team.
 */
class TeamReportController extends Controller
{
    public function export(Request $request, TeamReport $report): StreamedResponse
    {
        $range = DateRange::fromRequest($request);
        $days = CarbonImmutable::parse($range->fromDate)->diffInDays(CarbonImmutable::parse($range->toDate)) + 1;

        if ($days > TeamReport::MAX_DAYS) {
            throw ValidationException::withMessages(['to' => __('errors.queue.report_range', ['n' => TeamReport::MAX_DAYS])]);
        }

        $rows = $report->csvRows($report->build($range->fromDate, $range->toDate));

        return response()->streamDownload(function () use ($rows) {
            $out = fopen('php://output', 'w');
            fwrite($out, "\u{FEFF}"); // Excel opens UTF-8 Arabic correctly with a BOM
            foreach ($rows as $row) {
                fputcsv($out, $row);
            }
            fclose($out);
        }, "team-queue-{$range->fromDate}-{$range->toDate}.csv", ['Content-Type' => 'text/csv; charset=UTF-8']);
    }
}
