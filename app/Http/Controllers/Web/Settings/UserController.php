<?php

namespace App\Http\Controllers\Web\Settings;

use App\Analytics\PresenceTracker;
use App\Enums\Platform;
use App\Enums\UserRole;
use App\Http\Controllers\Concerns\RespondsWithData;
use App\Http\Controllers\Controller;
use App\Http\Resources\UserResource;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

class UserController extends Controller
{
    use RespondsWithData;

    public function index(Request $request): Response
    {
        return Inertia::render('settings/Users', [
            'users' => UserResource::collection(User::with('userPlatforms')->orderBy('name')->get())->resolve($request),
            'roles' => array_map(fn (UserRole $r) => $r->value, UserRole::cases()),
        ]);
    }

    public function store(Request $request): HttpResponse
    {
        $data = $this->validated($request);

        $user = DB::transaction(function () use ($data) {
            $user = User::create([
                'name' => $data['name'],
                'email' => $data['email'],
                'password' => $data['password'],
                'role' => $data['role'],
                'locale' => $data['locale'] ?? 'ar',
                'color' => $data['color'] ?? null,
                'is_active' => $data['is_active'] ?? true,
            ]);
            $this->syncPlatforms($user, $data['platforms'] ?? []);

            return $user;
        });

        return $this->done($request, (new UserResource($user->load('userPlatforms')))->resolve($request), 201);
    }

    public function update(Request $request, User $user): HttpResponse
    {
        $data = $this->validated($request, $user);

        if ($user->is($request->user()) && ($data['role'] !== UserRole::Admin->value || ! $request->boolean('is_active', true))) {
            throw ValidationException::withMessages(['role' => __('errors.users.cannot_remove_own_admin')]);
        }

        DB::transaction(function () use ($request, $user, $data) {
            $user->fill([
                'name' => $data['name'],
                'email' => $data['email'],
                'role' => $data['role'],
                'locale' => $data['locale'] ?? $user->locale,
                'color' => $data['color'] ?? $user->color,
                'is_active' => $data['is_active'] ?? $user->is_active,
            ]);

            if (! empty($data['password'])) {
                $user->password = $data['password'];
            }

            $user->save();

            // Omitting `platforms` keeps the current permissions; an empty array clears them.
            if ($request->has('platforms')) {
                $this->syncPlatforms($user, $data['platforms'] ?? []);
            }
        });

        return $this->done($request, (new UserResource($user->fresh('userPlatforms')))->resolve($request));
    }

    /**
     * Users are deactivated, never deleted, so their attribution history stays intact.
     */
    public function destroy(Request $request, User $user, PresenceTracker $presence): HttpResponse
    {
        if ($user->is($request->user())) {
            throw ValidationException::withMessages(['user' => __('errors.users.cannot_deactivate_self')]);
        }

        $user->forceFill(['is_active' => false])->save();
        $user->tokens()->delete();
        $presence->end($user);

        return $this->done($request, (new UserResource($user->load('userPlatforms')))->resolve($request));
    }

    private function validated(Request $request, ?User $user = null): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', Rule::unique('users', 'email')->ignore($user?->id)],
            'password' => [$user ? 'nullable' : 'required', 'string', 'min:8'],
            'role' => ['required', Rule::enum(UserRole::class)],
            'locale' => ['nullable', Rule::in(['ar', 'en'])],
            'color' => ['nullable', 'string', 'max:20'],
            'is_active' => ['nullable', 'boolean'],
            'platforms' => ['nullable', 'array'],
            'platforms.*' => [Rule::enum(Platform::class)],
        ]);
    }

    /**
     * @param  array<int, string>  $platforms
     */
    private function syncPlatforms(User $user, array $platforms): void
    {
        $user->userPlatforms()->delete();
        $user->userPlatforms()->createMany(array_map(fn (string $p) => ['platform' => $p], array_values(array_unique($platforms))));
        $user->unsetRelation('userPlatforms');
    }
}
