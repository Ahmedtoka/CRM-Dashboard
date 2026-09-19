<?php

use App\Bot\Flows\OwnerFlowsUpgrade;
use App\Bot\Flows\ReturnFlowUpgrade;
use App\Models\BotFlow;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
|
| The closure you provide to your test functions is always bound to a specific PHPUnit test
| case class. By default, that class is "PHPUnit\Framework\TestCase". Of course, you may
| need to change it using the "pest()" function to bind a different classes or traits.
|
*/

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->in('Feature');

/*
|--------------------------------------------------------------------------
| Expectations
|--------------------------------------------------------------------------
|
| When you're writing tests, you often need to check that values meet certain conditions. The
| "expect()" function gives you access to a set of "expectations" methods that you can use
| to assert different things. Of course, you may extend the Expectation API at any time.
|
*/

expect()->extend('toBeOne', function () {
    return $this->toBe(1);
});

/*
|--------------------------------------------------------------------------
| Functions
|--------------------------------------------------------------------------
|
| While Pest is very powerful out-of-the-box, you may have some testing code specific to your
| project that you don't want to repeat in every file. Here you can also expose helpers as
| global functions to help you to reduce the number of lines of code in your test files.
|
*/

function something()
{
    // ..
}

/**
 * Fakes the Meta Graph API by "METHOD path" (path without the version prefix and
 * query string, e.g. "GET me/accounts", "POST 123/subscribed_apps"). A value is a
 * JSON body (200), a [body, status] pair, or a callable receiving the request.
 * Anything not listed answers 500 so an unexpected call fails loudly.
 *
 * @param  array<string, mixed>  $routes
 */
function fakeMetaGraph(array $routes): void
{
    // Calling it again in the same test replaces the routes (a second Http::fake()
    // closure would never be reached: the first one answers every request).
    static $registered = [];
    $factory = Http::getFacadeRoot();
    $id = spl_object_id($factory);
    $GLOBALS['__metaGraphRoutes'] = $routes;

    if (($registered[$id] ?? null) === $factory) {
        return;
    }

    $registered = [$id => $factory];

    Http::fake(function (Request $request) {
        $routes = $GLOBALS['__metaGraphRoutes'];
        $path = (string) parse_url($request->url(), PHP_URL_PATH);
        $path = preg_replace('~^/v\d+\.\d+/~', '', $path);
        $key = strtoupper($request->method()).' '.$path;

        if (! array_key_exists($key, $routes)) {
            return Http::response(['error' => ['message' => 'unexpected '.$key, 'code' => 1]], 500);
        }

        $route = $routes[$key];

        if (is_callable($route)) {
            $route = $route($request);
        }

        if (is_array($route) && array_key_exists(0, $route) && is_int($route[1] ?? null)) {
            return Http::response($route[0], $route[1]);
        }

        return Http::response($route);
    });
}

/**
 * Puts the first order-aware return/exchange flow (commit 009704c: order → order_items → reason →
 * request → photos → summary) back live, for tests of the step types it exercises. The seeded flow
 * is the owner's 2026-09-19 flow (App\Bot\Flows\ReturnFlowUpgrade::definition()).
 */
function useOrderAwareReturnFlow(): void
{
    BotFlow::query()->where('key', 'return_exchange')
        ->update(['definition' => json_encode(ReturnFlowUpgrade::orderAwareDefinition(), JSON_UNESCAPED_UNICODE)]);
}

/**
 * Puts the 2026-09-17 definitions of cancel_edit / complaint / branches back live, for tests of
 * the step types they exercise. The seeded flows are the owner's 2026-09-19 flows
 * (App\Bot\Flows\OwnerFlowsUpgrade).
 */
function useLegacyOwnerFlows(string ...$keys): void
{
    foreach ($keys ?: ['cancel_edit', 'complaint', 'branches'] as $key) {
        BotFlow::query()->where('key', $key)
            ->update(['definition' => json_encode(OwnerFlowsUpgrade::legacyDefinitions()[$key], JSON_UNESCAPED_UNICODE)]);
    }
}
