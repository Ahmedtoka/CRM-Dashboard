<?php

namespace App\Http\Middleware;

use App\Enums\UserRole;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * `role:supervisor` allows supervisors and admins; `role:admin` allows admins only.
 */
class EnsureRole
{
    private const RANK = [
        'moderator' => 1,
        'supervisor' => 2,
        'admin' => 3,
    ];

    public function handle(Request $request, Closure $next, string $minimum): Response
    {
        $role = $request->user()?->role;
        $rank = $role instanceof UserRole ? (self::RANK[$role->value] ?? 0) : 0;

        abort_if($rank < (self::RANK[$minimum] ?? PHP_INT_MAX), 403, 'This action requires the '.$minimum.' role.');

        return $next($request);
    }
}
