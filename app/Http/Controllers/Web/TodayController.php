<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Today\TodayWindow;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/** «النهارده» (spec §6): the admin/supervisor home. Agents keep the inbox; ads roles never get here (RestrictAdsRoles). */
final class TodayController extends Controller
{
    public function __invoke(Request $request): Response|RedirectResponse
    {
        $user = $request->user();

        if (! $user->isSupervisorOrAbove()) {
            abort_if($request->expectsJson(), 403);

            return redirect()->route('inbox');
        }

        $w = TodayWindow::fromRequest($request);

        return Inertia::render('Today', ['mode' => $w->mode, 'date' => $w->date]);
    }
}
