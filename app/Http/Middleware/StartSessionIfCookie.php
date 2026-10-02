<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Session\Middleware\StartSession;
use Symfony\Component\HttpFoundation\Response;

/**
 * StartSession only for a request that already carries the session cookie (used by the catch-all
 * 404 route). A signed-in user still gets her session, so the error page renders inside the app;
 * a cookie-less scanner / bot / webhook miss stays stateless: no `sessions` row, no Set-Cookie.
 */
class StartSessionIfCookie extends StartSession
{
    public function handle($request, Closure $next): Response
    {
        if (! $request instanceof Request || ! $request->cookies->has((string) config('session.cookie'))) {
            return $next($request);
        }

        return parent::handle($request, $next);
    }
}
