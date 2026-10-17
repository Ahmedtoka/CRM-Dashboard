<?php

namespace App\Http\Controllers\Shopify;

use App\Http\Controllers\Controller;
use App\Http\Middleware\ShopifyFrameAncestors;
use App\Models\ShopifyWebhookSubscription;
use App\Shopify\App\IdTokenVerifier;
use App\Shopify\App\InstallShopifyApp;
use App\Shopify\App\InvalidIdToken;
use App\Shopify\App\ShopifyAccessTokens;
use App\Shopify\App\ShopifyApps;
use App\Shopify\App\ShopNotAllowed;
use App\Shopify\Client\ShopifyException;
use App\Shopify\Connection\IntegrationRepository;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

/**
 * The Partners app's embedded home (shopify-app.md §2.2, §5.2): a stateless Blade page inside
 * Shopify admin. No session, cookie, CSRF or Inertia: every request proves itself with a Shopify
 * ID token (the `id_token` query parameter on the first load, or App Bridge's Authorization header
 * on POST /shopify/app/session). A valid token is exchanged for an expiring offline token and the
 * install is stored (InstallShopifyApp). The CRM itself opens in its own tab, never in the iframe.
 */
class EmbeddedAppController extends Controller
{
    public function __construct(
        private readonly ShopifyApps $apps,
        private readonly IdTokenVerifier $verifier,
        private readonly ShopifyAccessTokens $tokens,
        private readonly InstallShopifyApp $installer,
        private readonly IntegrationRepository $integrations,
    ) {}

    public function show(Request $request): Response
    {
        $app = $this->apps->primary();
        abort_if($app === null, 404);

        $idToken = (string) $request->query('id_token', '');
        $status = null;

        if ($idToken !== '') {
            $result = $this->connect($request, $idToken);
            if ($result instanceof Response) {
                return $result;
            }
            $status = $result;
        }

        return response()->view('shopify.app', [
            'apiKey' => $app->clientId,
            'status' => $status,
            'crmUrl' => rtrim((string) config('app.url'), '/'),
        ]);
    }

    public function session(Request $request): Response
    {
        abort_if($this->apps->primary() === null, 404);

        $result = $this->connect($request, (string) $request->bearerToken());

        return $result instanceof Response ? $result : response()->json($result);
    }

    /** @return array<string, mixed>|Response the status for the page, or the error response */
    private function connect(Request $request, string $idToken): array|Response
    {
        try {
            $token = $this->verifier->verify($idToken);
        } catch (InvalidIdToken $e) {
            return $this->error($request, 401, 'invalid_token');
        } catch (ShopNotAllowed) {
            return $this->error($request, 403, 'shop_not_allowed');
        }

        $request->attributes->set(ShopifyFrameAncestors::SHOP_ATTRIBUTE, $token->shop);

        try {
            $current = $this->integrations->current();
            if ($current !== null && filled($current->shop_domain) && strcasecmp((string) $current->shop_domain, $token->shop) !== 0) {
                throw new ShopNotAllowed($token->shop);
            }

            $set = $this->tokens->exchange($token, $idToken);
            $integration = $this->installer->install($token->app, $token->shop, $set);
        } catch (ShopNotAllowed) {
            return $this->error($request, 403, 'other_store');
        } catch (ShopifyException $e) {
            Log::warning('shopify.app.exchange_failed', ['shop' => $token->shop, 'kind' => $e->kind, 'error' => $e->getMessage()]);

            return $this->error($request, 502, 'exchange_failed');
        }

        $last = ShopifyWebhookSubscription::query()->max('last_received_at');

        return [
            'shop_domain' => $integration->shop_domain,
            'shop_name' => $integration->shop_name,
            'status' => $integration->status,
            'auth_mode' => $integration->auth_mode,
            'access_token_expires_at' => $integration->access_token_expires_at?->toIso8601String(),
            'last_webhook_at' => $last !== null ? Carbon::parse($last)->toIso8601String() : null,
        ];
    }

    private function error(Request $request, int $status, string $code): Response
    {
        if ($request->expectsJson() || $request->isMethod('post')) {
            return response()->json(['error' => $code], $status);
        }

        return response()->view('shopify.app', [
            'apiKey' => (string) $this->apps->primary()?->clientId,
            'status' => null,
            'error' => $code,
            'crmUrl' => rtrim((string) config('app.url'), '/'),
        ], $status);
    }
}
