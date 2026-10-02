<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\View\Middleware\ShareErrorsFromSession;
use Symfony\Component\HttpFoundation\Response;

/**
 * Session only for a request that already carries the session cookie (the catch-all 404 route).
 * A signed-in user still gets her session, so the error page renders inside the app; a cookie-less
 * scanner / bot / webhook miss stays stateless: no `sessions` row, no Set-Cookie.
 *
 * Deliberately NOT a subclass of StartSession: the route excludes StartSession, and the router
 * drops every subclass of an excluded middleware, so a subclass would never run.
 */
class StartSessionIfCookie
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! $request->cookies->has((string) config('session.cookie'))) {
            return $next($request);
        }

        $share = app(ShareErrorsFromSession::class);

        return app(StartSession::class)->handle($request, fn (Request $request) => $share->handle($request, $next));
    }
}
