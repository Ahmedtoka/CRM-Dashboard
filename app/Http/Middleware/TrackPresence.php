<?php

namespace App\Http\Middleware;

use App\Analytics\PresenceTracker;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\HttpFoundation\Response;

/**
 * Counts web page activity as presence, at most one heartbeat per user per minute.
 * The client also posts /presence/heartbeat while a tab stays open.
 */
class TrackPresence
{
    public function __construct(private readonly PresenceTracker $presence) {}

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user !== null && Cache::add("presence:web:{$user->id}", true, 60)) {
            $this->presence->heartbeat($user, 'web');
        }

        return $next($request);
    }
}
