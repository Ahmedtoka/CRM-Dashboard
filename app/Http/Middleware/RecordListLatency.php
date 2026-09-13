<?php

namespace App\Http\Middleware;

use App\Analytics\LatencyRecorder;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Times GET /inbox/conversations (web + API) for crm:latency-report's "inbox
 * list/filter/search" target (spec §11.3: p95 < 300ms). Recording only runs
 * when config('crm.latency.enabled') so ordinary traffic pays no timing cost.
 */
class RecordListLatency
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! config('crm.latency.enabled')) {
            return $next($request);
        }

        $startedAt = microtime(true);

        $response = $next($request);

        $endedAt = microtime(true);

        app(LatencyRecorder::class)->list($request->path(), $startedAt, $endedAt, [
            'status' => $response->getStatusCode(),
            'query' => $request->query(),
        ]);

        return $response;
    }
}
