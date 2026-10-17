<?php

namespace App\Http\Middleware;

use App\Onboarding\HomeRoute;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Media buyers and content users live in /ads (and «النهارده» › الإعلانات, /today/ads) only (Ads Hub design §2,
 * redesign 2026-10-17), plus their own account in Settings: everywhere else a page
 * visit sends them home, and any other request (json, writes) is a 403.
 */
class RestrictAdsRoles
{
    private const ALLOWED = [
        'ads', 'ads/*', 'today/ads', 'settings/ads/*', 'settings', 'settings/profile', 'settings/password', 'settings/appearance', 'settings/notifications',
        'logout', 'notifications', 'notifications/*', 'broadcasting', 'broadcasting/*', 'up',
    ];

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user?->isAdsRole() || $request->is(...self::ALLOWED)) {
            return $next($request);
        }

        if ($request->isMethod('GET') && ! $request->expectsJson()) {
            return redirect(HomeRoute::for($user));
        }

        abort(403);
    }
}
