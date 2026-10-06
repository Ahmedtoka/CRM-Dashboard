<?php

namespace App\Http\Controllers\Web\Ads;

use App\Ads\Control\Write\RecentPassword;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

/** JSON password confirmation for the Run dialog (WriteActionDialog): same session key as Laravel's password.confirm. */
class ReauthController extends Controller
{
    public function __invoke(Request $request): Response
    {
        $data = $request->validate(['password' => ['required', 'string', 'max:255']]);
        if (! Hash::check($data['password'], (string) $request->user()->password)) {
            throw ValidationException::withMessages(['password' => __('auth.password')]);
        }
        $request->session()->put(RecentPassword::SESSION_KEY, time());

        return response()->noContent();
    }
}
