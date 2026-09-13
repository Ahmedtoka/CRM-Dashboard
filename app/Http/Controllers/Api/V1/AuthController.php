<?php

namespace App\Http\Controllers\Api\V1;

use App\Analytics\ActivityLogger;
use App\Analytics\PresenceTracker;
use App\Enums\ActorType;
use App\Http\Controllers\Controller;
use App\Http\Resources\UserResource;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
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

        $token = $user->createToken($data['device_name'])->plainTextToken;

        $logger->log(ActorType::User, $user, ActivityLogger::USER_LOGIN, null, null, [
            'device' => 'mobile',
            'device_name' => $data['device_name'],
        ]);

        return response()->json([
            'token' => $token,
            'user' => (new UserResource($user))->resolve($request),
        ]);
    }

    public function logout(Request $request, ActivityLogger $logger, PresenceTracker $presence): JsonResponse
    {
        $user = $request->user();

        $user->currentAccessToken()?->delete();
        $presence->end($user);

        $logger->log(ActorType::User, $user, ActivityLogger::USER_LOGOUT, null, null, ['device' => 'mobile']);

        return response()->json(['data' => ['ok' => true]]);
    }

    public function me(Request $request): UserResource
    {
        return new UserResource($request->user());
    }
}
