<?php

namespace App\Http\Controllers\Api\V1;

use App\Analytics\ActivityLogger;
use App\Analytics\PresenceTracker;
use App\Enums\ActorType;
use App\Http\Controllers\Controller;
use App\Http\Resources\UserResource;
use App\Http\Support\RealtimeConfig;
use App\Models\DeviceToken;
use App\Models\User;
use App\Queue\ShiftService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\NewAccessToken;
use Laravel\Sanctum\PersonalAccessToken;

class AuthController extends Controller
{
    /** Abilities carried by every token issued to the mobile app (API contract, "Auth"). */
    public const ABILITIES = ['mobile'];

    public function login(Request $request, ActivityLogger $logger): JsonResponse
    {
        $data = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
            'device_name' => ['required', 'string', 'max:100'],
        ]);

        $user = User::where('email', $data['email'])->first();

        if ($user === null || ! $user->is_active || ! Hash::check($data['password'], $user->password)) {
            throw ValidationException::withMessages(['email' => __('auth.failed')]);
        }

        if ($user->isAdsRole()) {
            throw ValidationException::withMessages(['email' => __('errors.auth.ads_role_no_mobile')]);
        }

        $token = $this->issue($user, $data['device_name']);

        $logger->log(ActorType::User, $user, ActivityLogger::USER_LOGIN, null, null, [
            'device' => 'mobile',
            'device_name' => $data['device_name'],
        ]);

        return response()->json($this->tokenPayload($request, $user, $token));
    }

    /**
     * Rotates the current token: a new expiring token with the same name, this token's
     * push devices moved onto it, then the old token deleted. A legacy token (no
     * expires_at) may refresh too and becomes an expiring one.
     */
    public function refresh(Request $request): JsonResponse
    {
        $user = $request->user();
        $current = $user->currentAccessToken();

        abort_unless($current instanceof PersonalAccessToken, 401);

        $token = DB::transaction(function () use ($user, $current) {
            $token = $this->issue($user, (string) $current->name);

            DeviceToken::query()->where('personal_access_token_id', $current->getKey())
                ->update(['personal_access_token_id' => $token->accessToken->getKey()]);

            $current->delete();

            return $token;
        });

        return response()->json($this->tokenPayload($request, $user, $token));
    }

    /** Logging out of the app = «خروج» on the queue, the same as a web logout (spec D6). */
    public function logout(Request $request, ActivityLogger $logger, PresenceTracker $presence): JsonResponse
    {
        $user = $request->user();
        $current = $user->currentAccessToken();

        if ($current instanceof PersonalAccessToken) {
            DeviceToken::query()->where('personal_access_token_id', $current->getKey())->delete();
            $current->delete();
        }

        $presence->end($user);
        rescue(fn () => app(ShiftService::class)->checkOutEverywhere($user), null, report: true);

        $logger->log(ActorType::User, $user, ActivityLogger::USER_LOGOUT, null, null, ['device' => 'mobile']);

        return response()->json(['data' => ['ok' => true]]);
    }

    public function me(Request $request): JsonResponse
    {
        $user = $request->user();
        $current = $user->currentAccessToken();

        return response()->json([
            'data' => (new UserResource($user))->resolve($request) + [
                'preferences' => ['notify_scope' => $user->notificationPreferences()['notify_scope']],
                'token_expires_at' => $current instanceof PersonalAccessToken ? $current->expires_at?->toIso8601String() : null,
            ],
            'realtime' => RealtimeConfig::forClient(),
        ]);
    }

    private function issue(User $user, string $name): NewAccessToken
    {
        return $user->createToken($name, self::ABILITIES, now()->addDays((int) config('crm.mobile.token_ttl_days', 30)));
    }

    /** @return array<string, mixed> */
    private function tokenPayload(Request $request, User $user, NewAccessToken $token): array
    {
        return [
            'token' => $token->plainTextToken,
            'token_type' => 'Bearer',
            'expires_at' => $token->accessToken->expires_at?->toIso8601String(),
            'user' => (new UserResource($user))->resolve($request),
            'realtime' => RealtimeConfig::forClient(),
        ];
    }
}
