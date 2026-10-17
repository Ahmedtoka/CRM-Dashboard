<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * A page that moved in the redesign (IA §5): the old GET URL answers 301 with the visitor's query kept, so bookmarks,
 * notifications and emails keep working. The route keeps its old name; `->defaults('to', '/bot/flows/{flow}')` names
 * the new URL, with `{param}` filled from the old route's parameters and any query in `to` kept before the visitor's.
 */
final class MovedPageController extends Controller
{
    public function __invoke(Request $request): RedirectResponse
    {
        $route = $request->route();
        [$path, $fixed] = array_pad(explode('?', (string) $route->defaults['to'], 2), 2, '');

        foreach ($route->originalParameters() as $name => $value) {
            $path = str_replace('{'.$name.'}', rawurlencode((string) $value), $path);
        }

        // The visitor's query goes on untouched (never re-encoded: `accounts[]=5` must not become `accounts[0]=5`).
        $query = implode('&', array_filter([$fixed, (string) $request->server('QUERY_STRING')], fn (string $q) => $q !== ''));

        return redirect($path.($query === '' ? '' : '?'.$query), 301);
    }
}
