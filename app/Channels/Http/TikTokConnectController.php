<?php

namespace App\Channels\Http;

use App\Channels\Adapters\TikTok\TikTokTokens;
use App\Channels\Integrations\ConnectionHealthCheck;
use App\Channels\Integrations\IntegrationException;
use App\Channels\Integrations\TikTokConnector;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * «الربط بتيك توك» on Settings → Channels (x6, 2026-10-24).
 *
 * connect  → the TikTok authorization page with a one-time `state`.
 * callback → verify state, exchange `code` (valid 10 minutes, once), save the messaging account, subscribe the
 *            DIRECT_MESSAGE webhook, run the health check, land back on the card with a `tiktok_connect` flash.
 * code     → the same with a code the owner pasted by hand: the app's registered redirect is his storefront
 *            (https://levoilestores.com/), so until the CRM callback is added there the code arrives in his address bar.
 *
 * Outcomes are flashed as codes the page translates (settings.integrations.tiktok.flash.*).
 */
class TikTokConnectController extends Controller
{
    private const STATE_KEY = 'tiktok_connect.state';

    public const FLASH_KEY = 'tiktok_connect';

    public function __construct(private readonly TikTokConnector $connector, private readonly ConnectionHealthCheck $health) {}

    /**
     * What the Channels page needs for the TikTok card.
     *
     * @return array<string, mixed>
     */
    public static function settings(): array
    {
        return [
            'enabled' => TikTokConnector::configured(),
            'app_id_set' => filled(config('services.tiktok.app_id')),
            'app_secret_set' => filled(config('services.tiktok.app_secret')),
            'app_id' => (string) config('services.tiktok.app_id', ''),
            'connect_url' => '/settings/channels/tiktok/connect',
            'redirect_uri' => TikTokConnector::redirectUri(),
            'callback_url' => TikTokConnector::callbackUrl(),
            'scopes' => array_values((array) config('services.tiktok.scopes', [])),
            'driver' => (string) config('services.tiktok.driver', 'fake'),
            'flash' => session(self::FLASH_KEY),
        ];
    }

    public function connect(Request $request): RedirectResponse
    {
        if (blank(config('services.tiktok.app_id'))) {
            return $this->back('app_id_missing');
        }

        if (blank(config('services.tiktok.app_secret'))) {
            return $this->back('app_secret_missing');
        }

        $state = Str::random(40);
        $request->session()->put(self::STATE_KEY, $state);

        return redirect()->away(TikTokConnector::authorizeUrl($state));
    }

    public function callback(Request $request): RedirectResponse
    {
        $expected = $request->session()->pull(self::STATE_KEY);
        $state = (string) $request->query('state', '');

        if (! is_string($expected) || $expected === '' || ! hash_equals($expected, $state)) {
            return $this->back('state_mismatch');
        }

        if ($request->filled('error') || $request->filled('error_description')) {
            return $this->back('cancelled', (string) $request->query('error_description', $request->query('error')));
        }

        $code = (string) ($request->query('code', $request->query('auth_code', '')));

        if ($code === '') {
            // TikTok skips the consent screen (and the code) for an already-authorized account unless disable_auto_auth=1.
            return $this->back('no_code');
        }

        return $this->finish($code, 'oauth');
    }

    /** A code pasted from the address bar after TikTok redirected to the app's registered (storefront) URL. */
    public function code(Request $request): JsonResponse
    {
        $data = $request->validate(['code' => ['required', 'string', 'min:6', 'max:512']]);
        $code = trim($data['code']);

        // The whole redirected URL may be pasted: take its code parameter.
        if (str_contains($code, 'code=')) {
            parse_str((string) parse_url($code, PHP_URL_QUERY), $query);
            $code = (string) ($query['code'] ?? $query['auth_code'] ?? $code);
        }

        try {
            [$account, $subscribe] = $this->connector->connectWithCode($code, 'code');
        } catch (IntegrationException $e) {
            return response()->json($e->toArray(), 422);
        }

        rescue(fn () => $this->health->run($account), report: true);

        return response()->json([
            'ok' => true,
            'subscribed' => $subscribe->success,
            'subscribe_error' => $subscribe->success ? null : $subscribe->error,
            'account' => self::present($account->refresh()),
        ]);
    }

    private function finish(string $code, string $method): RedirectResponse
    {
        try {
            [$account, $subscribe] = $this->connector->connectWithCode($code, $method);
        } catch (IntegrationException $e) {
            return $this->back($e->errorCode === 'tiktok_unreachable' ? 'unreachable' : 'code_invalid', $e->detail);
        }

        rescue(fn () => $this->health->run($account), report: true);

        if (! $subscribe->success) {
            return $this->back('subscribe_failed', $subscribe->error, $account->name);
        }

        return $this->back('connected', null, $account->name);
    }

    /**
     * The TikTok messaging account as the Channels card sees it: no token, ever.
     *
     * @return array<string, mixed>
     */
    public static function present(\App\Models\ChannelAccount $a): array
    {
        $profile = (array) ($a->profile ?? []);

        return [
            'id' => $a->id,
            'platform' => 'tiktok',
            'name' => $a->name,
            'external_id' => $a->external_id,
            'status' => $a->status,
            'connected_at' => ($a->connected_at ?? $a->created_at)?->toIso8601String(),
            'last_inbound_at' => ConnectionHealthCheck::lastInboundAt($a)?->toIso8601String(),
            'last_webhook_at' => $a->last_webhook_at?->toIso8601String(),
            'last_error' => ConnectionHealthCheck::problemText($a->last_error),
            'has_token' => filled($a->credentials['access_token'] ?? null),
            'profile' => [
                'picture' => $profile['picture'] ?? null,
                'username' => $profile['username'] ?? null,
                'category' => null,
                'method' => $profile['method'] ?? null,
                'page_id' => null,
                'waba_id' => null,
                'display_phone_number' => null,
                'verified_name' => $profile['display_name'] ?? null,
                'quality_rating' => null,
                'override_callback' => false,
                'has_advanced_access' => $profile['has_advanced_access'] ?? null,
                'webhook_subscribed_at' => $profile['webhook_subscribed_at'] ?? null,
                'token_expires_at' => TikTokTokens::refreshExpiresAt($a)?->toIso8601String(),
            ],
            'health' => $a->health,
            'health_status' => $a->health_status,
            'health_checked_at' => $a->health_checked_at?->toIso8601String(),
        ];
    }

    private function back(string $code, ?string $detail = null, ?string $name = null): RedirectResponse
    {
        return redirect()->route('settings.integrations.index')->with(self::FLASH_KEY, array_filter([
            'code' => $code,
            'name' => $name,
            'detail' => $detail !== null ? Str::limit($detail, 300) : null,
        ], fn ($v) => $v !== null));
    }
}
