<?php

namespace App\Http\Controllers\Concerns;

use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Settings-style mutations answer JSON (`{data}`) to XHR/API callers and redirect
 * back for Inertia form submissions.
 */
trait RespondsWithData
{
    protected function done(Request $request, mixed $data = null, int $status = 200): Response
    {
        if ($request->expectsJson()) {
            return response()->json(['data' => $data], $status);
        }

        return back(303);
    }
}
