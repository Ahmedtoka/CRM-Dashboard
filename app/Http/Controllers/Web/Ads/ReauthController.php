<?php

namespace App\Http\Controllers\Web\Ads;

use App\Ads\Audit\AdsAudit;
use App\Ads\Control\Write\RecentPassword;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

/**
 * In-page password re-auth (S1 approvals, S2 Run dialog): stamps the session key Laravel's RequirePassword middleware
 * and RecentPassword both read.
 */
class ReauthController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $data = $request->validate(['password' => ['required', 'string', 'max:255']]);
        if (! Hash::check($data['password'], (string) $request->user()->password)) {
            throw ValidationException::withMessages(['password' => __('auth.password')]);
        }
        $request->session()->put(RecentPassword::SESSION_KEY, time());
        AdsAudit::record('launch.reauth', null, null, null, [], $request->user());

        return response()->json(['ok' => true, 'valid_until' => now()->addSeconds(ApprovalController::REAUTH_SECONDS)->toIso8601String()]);
    }
}
