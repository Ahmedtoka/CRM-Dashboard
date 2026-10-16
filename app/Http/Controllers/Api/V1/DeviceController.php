<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\DeviceToken;
use App\Push\PushManager;
use App\Push\PushMessageFactory;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\Rule;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * Push device registration for the mobile app (API contract, "Devices"). A device row is
 * found by sha256(token) and always (re)bound to the caller and her current access
 * token; a user can only ever delete her own rows.
 */
class DeviceController extends Controller
{
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'token' => ['required', 'string', 'max:4096'],
            'platform' => ['required', Rule::in(['android', 'ios'])],
            'app_version' => ['nullable', 'string', 'max:32'],
            'locale' => ['nullable', Rule::in(['ar', 'en'])],
        ]);

        $user = $request->user();
        $current = $user->currentAccessToken();
        $hash = DeviceToken::hashToken($data['token']);

        $device = DeviceToken::query()->firstOrNew(['token_hash' => $hash]);
        $created = ! $device->exists;

        $device->forceFill([
            'user_id' => $user->id,
            'personal_access_token_id' => $current instanceof PersonalAccessToken ? $current->getKey() : null,
            'platform' => $data['platform'],
            'token' => $data['token'],
            'app_version' => $data['app_version'] ?? null,
            'locale' => $data['locale'] ?? null,
            'last_registered_at' => now(),
            'failure_count' => 0,
            'disabled_at' => null,
        ])->save();

        return response()->json(['data' => [
            'id' => $device->id,
            'platform' => $device->platform,
            'app_version' => $device->app_version,
            'last_registered_at' => $device->last_registered_at?->toIso8601String(),
        ]], $created ? 201 : 200);
    }

    /** 204 whether or not a row was deleted: no probing for other users' tokens. */
    public function destroy(Request $request): Response
    {
        $data = $request->validate(['token' => ['required', 'string', 'max:4096']]);

        DeviceToken::query()->where('token_hash', DeviceToken::hashToken($data['token']))
            ->where('user_id', $request->user()->id)->delete();

        return response()->noContent();
    }

    public function test(Request $request, PushManager $push, PushMessageFactory $factory): JsonResponse
    {
        $user = $request->user();
        $result = $push->sendToUser($user, $factory->test($user));

        return response()->json(['data' => ['driver' => $push->driverName()] + $result]);
    }
}
