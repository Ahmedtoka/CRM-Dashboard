<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\DeviceToken;
use App\Push\PushManager;
use App\Push\PushMessageFactory;
use Illuminate\Database\UniqueConstraintViolationException;
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
    public const MAX_PER_USER = 10;

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            // FCM registration tokens: url-safe characters and colons, ~150+ chars (review 01, M1).
            'token' => ['required', 'string', 'regex:/^[A-Za-z0-9_:\-]{32,4096}$/'],
            'platform' => ['required', Rule::in(['android', 'ios'])],
            'app_version' => ['nullable', 'string', 'max:32'],
            'locale' => ['nullable', Rule::in(['ar', 'en'])],
        ]);

        $user = $request->user();
        $current = $user->currentAccessToken();
        $attributes = [
            'user_id' => $user->id,
            'personal_access_token_id' => $current instanceof PersonalAccessToken ? $current->getKey() : null,
            'platform' => $data['platform'],
            'token' => $data['token'],
            'app_version' => $data['app_version'] ?? null,
            'locale' => $data['locale'] ?? null,
            'last_registered_at' => now(),
            'failure_count' => 0,
            'disabled_at' => null,
        ];

        try {
            [$device, $created] = $this->save(DeviceToken::hashToken($data['token']), $attributes);
        } catch (UniqueConstraintViolationException) {
            // Two registrations of the same token raced (launch + onTokenRefresh): the other
            // insert won, so this one becomes an update of that row (review 01, M2).
            [$device] = $this->save(DeviceToken::hashToken($data['token']), $attributes);
            $created = false;
        }

        $this->pruneBeyondCap($user->id);

        return response()->json(['data' => [
            'id' => $device->id,
            'platform' => $device->platform,
            'app_version' => $device->app_version,
            'last_registered_at' => $device->last_registered_at?->toIso8601String(),
        ]], $created ? 201 : 200);
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @return array{0: DeviceToken, 1: bool}
     */
    private function save(string $hash, array $attributes): array
    {
        $device = DeviceToken::query()->firstOrNew(['token_hash' => $hash]);
        $created = ! $device->exists;
        $device->forceFill($attributes)->save();

        return [$device, $created];
    }

    /** At most MAX_PER_USER rows per user: the least recently registered beyond that are deleted (review 01, M1). */
    private function pruneBeyondCap(int $userId): void
    {
        $keep = DeviceToken::query()->where('user_id', $userId)
            ->orderByDesc('last_registered_at')->orderByDesc('id')->limit(self::MAX_PER_USER)->pluck('id');

        DeviceToken::query()->where('user_id', $userId)->whereKeyNot($keep)->delete();
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

        return response()->json(['data' => [
            'driver' => $push->driverName(), 'devices' => $result['devices'], 'sent' => $result['sent'], 'failed' => $result['failed'],
        ]]);
    }
}
