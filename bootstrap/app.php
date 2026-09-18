<?php

use App\Comments\CommentActionFailedException;
use App\Comments\PrivateReplyNotAllowedException;
use App\Http\Middleware\EnsureDevToolsEnabled;
use App\Http\Middleware\EnsureRole;
use App\Http\Middleware\HandleInertiaRequests;
use App\Http\Middleware\RecordListLatency;
use App\Http\Middleware\SetLocale;
use App\Inbox\WindowClosedException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Middleware\AddLinkHeadersForPreloadedAssets;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        channels: __DIR__.'/../routes/channels.php',
        health: '/up',
        then: function () {
            // Webhooks are stateless: no session, cookies or CSRF (the `web` group would
            // start and write a session for every platform delivery).
            Route::group([], __DIR__.'/../routes/webhooks.php');
            Route::middleware(['web', 'auth'])->group(__DIR__.'/../routes/crm.php');
        },
    )
    ->withMiddleware(function (Middleware $middleware) {
        $middleware->web(append: [
            // Guest pages (login, password reset) follow the session locale too.
            SetLocale::class,
            HandleInertiaRequests::class,
            AddLinkHeadersForPreloadedAssets::class,
        ]);

        $middleware->alias([
            'role' => EnsureRole::class,
            'dev-tools' => EnsureDevToolsEnabled::class,
            'record-list-latency' => RecordListLatency::class,
        ]);

        $middleware->validateCsrfTokens(except: [
            'webhooks/*',
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions) {
        $wantsJson = fn (Request $request) => $request->is('api/*') || $request->expectsJson();

        $exceptions->shouldRenderJsonWhen($wantsJson);

        $exceptions->render(fn (WindowClosedException $e, Request $request) => $wantsJson($request)
            ? response()->json(['message' => $e->getMessage(), 'mode' => $e->mode], 422)
            : null);

        $exceptions->render(fn (PrivateReplyNotAllowedException $e, Request $request) => $wantsJson($request)
            ? response()->json(['message' => $e->getMessage()], 422)
            : null);

        $exceptions->render(fn (CommentActionFailedException $e, Request $request) => $wantsJson($request)
            ? response()->json(['message' => $e->getMessage()], 502)
            : null);

        $exceptions->render(fn (\App\Media\MediaRejected $e, Request $request) => $wantsJson($request)
            ? response()->json(['message' => $e->getMessage(), 'errors' => ['file' => [$e->getMessage()]]], 422)
            : back()->withErrors(['file' => $e->getMessage()]));

        // The handler converts AuthorizationException to AccessDeniedHttpException before
        // render callbacks run, so the 403 mapping is registered on the converted type.
        $exceptions->render(fn (AccessDeniedHttpException $e, Request $request) => $wantsJson($request)
            ? response()->json(['message' => $e->getMessage() ?: 'This action is unauthorized.'], 403)
            : null);
    })->create();
