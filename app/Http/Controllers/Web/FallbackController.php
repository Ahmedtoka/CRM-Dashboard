<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Illuminate\Routing\Router;
use Symfony\Component\HttpKernel\Exception\MethodNotAllowedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * The catch-all route (routes/web.php). It runs inside the `web` group, so the app-level error
 * page (bootstrap/app.php → respond) knows the signed-in user and the interface language.
 *
 * Because it answers every verb, it would also swallow a known address called with the wrong
 * verb (DELETE /settings/profile); that keeps Laravel's 405 with its Allow header.
 */
class FallbackController extends Controller
{
    public function __invoke(Request $request, Router $router): never
    {
        $allowed = array_values(array_filter(
            array_diff(Router::$verbs, [$request->getMethod()]),
            fn (string $verb): bool => collect($router->getRoutes()->get($verb))
                ->contains(fn (Route $route): bool => ! $route->isFallback && $route->matches($request, false)),
        ));

        if ($allowed !== []) {
            throw new MethodNotAllowedHttpException($allowed, sprintf('The %s method is not supported for route %s. Supported methods: %s.', $request->getMethod(), $request->path(), implode(', ', $allowed)));
        }

        throw new NotFoundHttpException;
    }
}
