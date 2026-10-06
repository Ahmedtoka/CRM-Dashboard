<?php

namespace App\Http\Middleware;

use App\Ads\Control\Write\WriteDenied;
use App\Enums\UserRole;
use App\Onboarding\HomeRoute;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * `ads:report` supervisor+ and media buyers, `ads:materials` supervisor+, media buyers and content,
 * `ads:manage` supervisor+. A content user opening a report page lands on the materials library.
 */
class EnsureAdsAccess
{
    public function handle(Request $request, Closure $next, string $area): Response
    {
        $user = $request->user();
        $role = $user?->role;

        $allowed = match ($area) {
            'report' => $user?->isSupervisorOrAbove() || $role === UserRole::MediaBuyer,
            'materials' => $user?->isSupervisorOrAbove() || in_array($role, [UserRole::MediaBuyer, UserRole::Content], true),
            'manage' => (bool) $user?->isSupervisorOrAbove(),
            // Launch approvals: refused before the password re-auth, so a buyer gets 403 and never a 423 prompt.
            'authority' => $user !== null && $user->hasAdsAuthority() ? true : throw WriteDenied::make('ads_authority_required'),
            default => false,
        };

        if ($allowed) {
            return $next($request);
        }

        if ($role === UserRole::Content && $request->isMethod('GET') && ! $request->expectsJson()) {
            return redirect(HomeRoute::for($user));
        }

        abort(403);
    }
}
