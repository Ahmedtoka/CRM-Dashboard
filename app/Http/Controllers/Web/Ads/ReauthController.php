<?php

namespace App\Http\Controllers\Web\Ads;

use App\Ads\Audit\AdsAudit;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;

/** In-page password re-auth (G3, A6): stamps the same session key Laravel's RequirePassword middleware reads. */
class ReauthController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $data = $request->validate(['password' => ['required', 'string']]);
        if (! Auth::guard('web')->validate(['email' => $request->user()->email, 'password' => $data['password']])) {
            throw ValidationException::withMessages(['password' => __('auth.password')]);
        }
        $request->session()->put('auth.password_confirmed_at', time());
        AdsAudit::record('launch.reauth', null, null, null, [], $request->user());

        return response()->json(['ok' => true, 'valid_until' => now()->addSeconds(ApprovalController::REAUTH_SECONDS)->toIso8601String()]);
    }
}
