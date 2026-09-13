<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class LocaleController extends Controller
{
    public function update(Request $request, string $locale): Response
    {
        $request->user()->forceFill(['locale' => $locale])->save();
        $request->session()->put('locale', $locale);
        app()->setLocale($locale);

        return $request->expectsJson()
            ? response()->json(['data' => ['locale' => $locale]])
            : back(303);
    }

    /**
     * Guests (login / password pages) only get the session locale; it becomes the
     * user's saved locale on the next signed-in switch.
     */
    public function guest(Request $request, string $locale): Response
    {
        $request->session()->put('locale', $locale);
        app()->setLocale($locale);

        return back(303);
    }
}
