<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Today\TeamLine;
use App\Today\TodayCache;
use App\Today\TodayCards;
use App\Today\TodayWindow;
use App\Today\UrgentStrip;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * «النهارده» (spec §6, wireframe 04 §4): the admin/supervisor home. The urgent strip comes with the page; the
 * cards and the team line load right after it (deferred, skeletons meanwhile). «امبارح» is the same page on the
 * complete previous day, the end-of-day report (G12). Agents keep the inbox; ads roles never get here (RestrictAdsRoles).
 */
final class TodayController extends Controller
{
    public function __invoke(Request $request, UrgentStrip $urgent, TodayCards $cards, TeamLine $team, TodayCache $cache): Response|RedirectResponse
    {
        $user = $request->user();

        if (! $user->isSupervisorOrAbove()) {
            abort_if($request->expectsJson(), 403);

            return redirect()->route('inbox');
        }

        $w = TodayWindow::fromRequest($request);
        $strip = $w->isToday() ? $cache->remember($user, $w, 'urgent', fn () => $urgent->for($user)) : null;

        return Inertia::render('Today', [
            'mode' => $w->mode,
            'date' => $w->date,
            'generated_at' => $strip['generated_at'] ?? now()->toIso8601String(),
            'urgent' => fn () => $strip['data'] ?? null,
            'cards' => Inertia::defer(fn () => $cache->remember($user, $w, 'cards', fn () => $cards->all($w, $user))['data'], 'cards'),
            'team' => Inertia::defer(fn () => $cache->remember($user, $w, 'team', fn () => $team->for($w))['data'], 'team'),
            // S5 (decisions feed) fills this with the 09:00 digest; S4 keeps the slot.
            'digest' => null,
        ]);
    }
}
