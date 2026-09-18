<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Developer-only pages (the simulator, the latency report) exist only while
 * `crm.dev_tools` (env CRM_DEV_TOOLS) is on; otherwise they 404 as if absent.
 */
class EnsureDevToolsEnabled
{
    public function handle(Request $request, Closure $next): Response
    {
        abort_unless((bool) config('crm.dev_tools'), 404);

        return $next($request);
    }
}
