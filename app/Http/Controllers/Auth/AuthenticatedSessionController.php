<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\LoginRequest;
use App\Models\User;
use App\Onboarding\HomeRoute;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

class AuthenticatedSessionController extends Controller
{
    /**
     * Show the login page.
     */
    public function create(Request $request): Response
    {
        return Inertia::render('auth/Login', [
            'canResetPassword' => Route::has('password.request'),
            'status' => $request->session()->get('status'),
            'quickUsers' => self::quickLoginEnabled()
                ? User::query()->where('is_active', true)->orderBy('id')
                    ->get(['id', 'name', 'email', 'role'])
                    ->map(fn (User $u) => ['id' => $u->id, 'name' => $u->name, 'email' => $u->email, 'role' => $u->role->value])
                    ->all()
                : [],
        ]);
    }

    /**
     * One-click sign-in for the owner's local machine (no password typing).
     * Enabled only when `crm.dev_quick_login` is on AND the app runs locally;
     * anywhere else this route does not exist.
     */
    public function quick(Request $request): RedirectResponse
    {
        abort_unless(self::quickLoginEnabled(), 404);

        $data = $request->validate([
            'user_id' => ['required', 'integer', Rule::exists('users', 'id')->where('is_active', true)],
        ]);

        Auth::login(User::findOrFail($data['user_id']), remember: true);
        $request->session()->regenerate();

        return redirect()->intended(HomeRoute::for($request->user()));
    }

    public static function quickLoginEnabled(): bool
    {
        return (bool) config('crm.dev_quick_login') && app()->environment(['local', 'testing']);
    }

    /**
     * Handle an incoming authentication request.
     */
    public function store(LoginRequest $request): RedirectResponse
    {
        $request->authenticate();

        $request->session()->regenerate();

        return redirect()->intended(HomeRoute::for($request->user()));
    }

    /**
     * Destroy an authenticated session.
     *
     * Always a full page load (final fix wave I2): an Inertia request gets a 409 +
     * X-Inertia-Location, which the client follows with `window.location`, so no
     * client module state (notification channels, prefs, unread counts) survives
     * into the next login in the same tab. Plain requests still get a redirect.
     */
    public function destroy(Request $request): SymfonyResponse
    {
        Auth::guard('web')->logout();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return Inertia::location(url('/'));
    }
}
