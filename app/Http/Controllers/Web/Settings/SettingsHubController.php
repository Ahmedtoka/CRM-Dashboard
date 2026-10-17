<?php

namespace App\Http\Controllers\Web\Settings;

use App\Http\Controllers\Controller;
use App\Settings\SettingsHub;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/** «الإعدادات» (A5): one page of grouped cards instead of 18 links in the menu. */
final class SettingsHubController extends Controller
{
    public function __invoke(Request $request, SettingsHub $hub): Response
    {
        return Inertia::render('settings/Index', ['groups' => $hub->for($request->user())]);
    }
}
