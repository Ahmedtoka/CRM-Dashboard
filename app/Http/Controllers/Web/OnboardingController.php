<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\BotSetting;
use App\Onboarding\HomeRoute;
use App\Onboarding\OnboardingProgress;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/** «ابدأ من هنا» (owner, 2026-09-26): the connect-everything page for a store's admin. */
class OnboardingController extends Controller
{
    public function index(OnboardingProgress $progress): Response
    {
        return Inertia::render('Onboarding', ['progress' => $progress->build()]);
    }

    /** «تخطي دلوقتي»: no more automatic landing here; the page stays in the menu. */
    public function dismiss(Request $request): RedirectResponse
    {
        BotSetting::current()->forceFill(['onboarding_dismissed_at' => now()])->save();

        return redirect(HomeRoute::for($request->user()));
    }

    /** Back to the automatic landing (from the page itself). */
    public function resume(): RedirectResponse
    {
        BotSetting::current()->forceFill(['onboarding_dismissed_at' => null])->save();

        return redirect()->route('onboarding.index');
    }
}
