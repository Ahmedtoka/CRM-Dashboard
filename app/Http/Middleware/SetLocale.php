<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class SetLocale
{
    public const SUPPORTED = ['ar', 'en'];

    public function handle(Request $request, Closure $next): Response
    {
        // A stateless request (the cookie-less 404 page) has no session: follow the browser's language.
        $locale = $request->user()?->locale
            ?? ($request->hasSession() ? $request->session()->get('locale') : $this->preferred($request));

        if (in_array($locale, self::SUPPORTED, true)) {
            app()->setLocale($locale);
        }

        return $next($request);
    }

    private function preferred(Request $request): ?string
    {
        return $request->headers->has('Accept-Language') ? $request->getPreferredLanguage(self::SUPPORTED) : null;
    }
}
