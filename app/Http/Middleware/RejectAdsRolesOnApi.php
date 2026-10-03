<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** The mobile API is for inbox staff: tokens held by Ads Hub roles are refused. */
class RejectAdsRolesOnApi
{
    public function handle(Request $request, Closure $next): Response
    {
        abort_if($request->user()?->isAdsRole(), 403, __('errors.auth.ads_role_no_mobile'));

        return $next($request);
    }
}
