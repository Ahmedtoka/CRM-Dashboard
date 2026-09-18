<?php

namespace App\Http\Controllers\Web\Settings;

use App\Channels\Data\SendResult;
use App\Channels\Integrations\ChannelDisconnector;
use App\Channels\Integrations\ConnectionHealthCheck;
use App\Channels\Integrations\FacebookPageConnector;
use App\Channels\Integrations\InstagramConnector;
use App\Channels\Integrations\IntegrationException;
use App\Channels\Integrations\WhatsAppConnector;
use App\Channels\MetaPageSubscriber;
use App\Enums\Platform;
use App\Http\Controllers\Controller;
use App\Models\ChannelAccount;
use App\Shopify\Connection\IntegrationRepository;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Settings → Integrations (admin only): one card per platform for the owner —
 * Facebook Messenger, Instagram, WhatsApp Business, Shopify, TikTok (later).
 *
 * Only live accounts appear here; simulator/demo accounts stay on the Advanced page
 * (Settings → Channels). Tokens are accepted from the browser but never sent back:
 * every account goes through present(), and a pasted System User token is kept in
 * the session (encrypted, 15 minutes) between "list pages" and "connect".
 */
class IntegrationController extends Controller
{
    private const SYSTEM_TOKEN_KEY = 'integrations.system_token';

    private const SYSTEM_TOKEN_TTL_MINUTES = 15;

    public function __construct(
        private readonly FacebookPageConnector $facebook,
        private readonly InstagramConnector $instagram,
        private readonly WhatsAppConnector $whatsapp,
        private readonly ConnectionHealthCheck $health,
        private readonly ChannelDisconnector $disconnector,
        private readonly MetaPageSubscriber $subscriber,
        private readonly IntegrationRepository $shopify,
    ) {}

    public function index(): Response
    {
        return Inertia::render('settings/Integrations', [
            'accounts' => $this->accountsPayload(),
            'shopify' => $this->shopifyPayload(),
            'meta' => [
                'app_id_set' => filled(config('crm.meta.app_id')),
                'app_secret_set' => filled(config('crm.meta.app_secret')),
                'verify_token' => (string) config('crm.meta.verify_token'),
                'callback_urls' => [
                    'facebook' => $this->callbackUrl('facebook'),
                    'instagram' => $this->callbackUrl('instagram'),
                    'whatsapp' => WhatsAppConnector::callbackUrl(),
                ],
                'can_override_callback' => WhatsAppConnector::canOverrideCallback(),
                'required_scopes' => ConnectionHealthCheck::SCOPES,
            ],
            'facebookLogin' => FacebookLoginController::settings(),
        ]);
    }

    /**
     * The System User option, step 1: list the Pages the pasted token can manage.
     * An empty list is not an error — the page then asks for the Page ID.
     */
    public function systemTokenPages(Request $request): JsonResponse
    {
        $data = $request->validate(['token' => ['required', 'string', 'min:20', 'max:1024']]);
        $token = trim($data['token']);

        try {
            $pages = $this->facebook->pagesForToken($token);
        } catch (IntegrationException $e) {
            return response()->json($e->toArray(), 422);
        }

        $request->session()->put(self::SYSTEM_TOKEN_KEY, encrypt([
            'token' => $token,
            'pages' => $pages,
            'expires_at' => now()->addMinutes(self::SYSTEM_TOKEN_TTL_MINUTES)->getTimestamp(),
        ]));

        return response()->json([
            'ok' => true,
            'pages' => array_map(fn (array $p) => [
                'id' => $p['id'],
                'name' => $p['name'],
                'category' => $p['category'],
                'picture' => $p['picture'],
                'missing_tasks' => FacebookPageConnector::missingTasks($p['tasks']),
            ], $pages),
        ]);
    }

    /**
     * The System User option, step 2: connect a listed Page, or a Page ID typed in
     * when the token listed none (its Page token is then derived from the system
     * user token via `GET /{page-id}?fields=access_token`).
     */
    public function systemTokenConnect(Request $request): JsonResponse
    {
        $data = $request->validate(['page_id' => ['required', 'string', 'regex:/^\d{5,30}$/']]);
        $stored = $this->systemToken($request);

        if ($stored === null) {
            return response()->json((new IntegrationException('token_expired'))->toArray(), 422);
        }

        $page = collect($stored['pages'])->firstWhere('id', $data['page_id']);

        try {
            $page ??= $this->facebook->pageForToken($stored['token'], $data['page_id']);
        } catch (IntegrationException $e) {
            return response()->json($e->toArray(), 422);
        }

        if (($missing = FacebookPageConnector::missingTasks($page['tasks'] ?? null)) !== []) {
            return response()->json((new IntegrationException('missing_tasks', implode(', ', $missing)))->toArray(), 422);
        }

        [$account, $subscribe] = $this->facebook->connect($page, 'system_user');
        $request->session()->forget(self::SYSTEM_TOKEN_KEY);

        return $this->connected($account, $subscribe);
    }

    /** What the Instagram card offers once Facebook is connected. */
    public function instagramDiscover(): JsonResponse
    {
        $facebook = $this->facebook->liveAccount();

        if ($facebook === null) {
            return response()->json((new IntegrationException('facebook_not_connected'))->toArray(), 422);
        }

        try {
            $ig = $this->instagram->discover($facebook);
        } catch (IntegrationException $e) {
            return response()->json($e->toArray(), 422);
        }

        return response()->json(['ok' => true, 'instagram' => $ig, 'page_name' => $facebook->name]);
    }

    public function instagramConnect(): JsonResponse
    {
        $facebook = $this->facebook->liveAccount();

        if ($facebook === null) {
            return response()->json((new IntegrationException('facebook_not_connected'))->toArray(), 422);
        }

        try {
            [$account, $subscribe] = $this->instagram->connect($facebook);
        } catch (IntegrationException $e) {
            return response()->json($e->toArray(), 422);
        }

        return $this->connected($account, $subscribe);
    }

    public function whatsappPhoneNumbers(Request $request): JsonResponse
    {
        $data = $request->validate([
            'waba_id' => ['required', 'string', 'regex:/^\d{5,30}$/'],
            'access_token' => ['required', 'string', 'min:20', 'max:1024'],
        ]);

        try {
            $numbers = $this->whatsapp->phoneNumbers($data['waba_id'], trim($data['access_token']));
        } catch (IntegrationException $e) {
            return response()->json($e->toArray(), 422);
        }

        return response()->json(['ok' => true, 'phone_numbers' => $numbers]);
    }

    public function whatsappConnect(Request $request): JsonResponse
    {
        $data = $request->validate([
            'waba_id' => ['required', 'string', 'regex:/^\d{5,30}$/'],
            'phone_number_id' => ['required', 'string', 'regex:/^\d{5,30}$/'],
            'access_token' => ['required', 'string', 'min:20', 'max:1024'],
            'override_callback' => ['sometimes', 'boolean'],
        ]);

        try {
            [$account, $subscribe] = $this->whatsapp->connect(
                $data['waba_id'],
                $data['phone_number_id'],
                trim($data['access_token']),
                (bool) ($data['override_callback'] ?? true),
            );
        } catch (IntegrationException $e) {
            return response()->json($e->toArray(), 422);
        }

        return $this->connected($account, $subscribe);
    }

    /** "Test": run the health check now and return the refreshed card. */
    public function test(ChannelAccount $channel): JsonResponse
    {
        $this->ensureManaged($channel);

        $this->health->run($channel);

        return response()->json(['ok' => true, 'account' => $this->present($channel->refresh())]);
    }

    /** One-click fixes offered by a problem card. */
    public function fix(Request $request, ChannelAccount $channel): JsonResponse
    {
        $this->ensureManaged($channel);
        $request->validate(['action' => ['required', Rule::in(['resubscribe'])]]);

        $result = match ($channel->platform) {
            Platform::WhatsApp => $this->whatsapp->subscribe($channel),
            Platform::Instagram => ($page = $channel->linkedFacebookAccount()) !== null && filled($page->external_id)
                ? $this->subscriber->subscribe($page, (string) $page->external_id)
                : SendResult::fail('facebook_not_connected'),
            default => $this->subscriber->subscribe($channel, (string) $channel->external_id),
        };

        $this->health->run($channel);

        return response()->json([
            'ok' => $result->success,
            'error' => $result->success ? null : 'subscribe_failed',
            'detail' => $result->success ? null : $result->error,
            'account' => $this->present($channel->refresh()),
        ], $result->success ? 200 : 422);
    }

    public function destroy(ChannelAccount $channel): JsonResponse
    {
        $this->ensureManaged($channel);

        $ids = $this->disconnector->disconnect($channel);

        return response()->json(['ok' => true, 'disconnected' => $ids, 'accounts' => $this->accountsPayload()]);
    }

    /**
     * After any connect: run the health check right away so the card lands in its real
     * state (connected / problem) instead of an optimistic "connected".
     */
    private function connected(ChannelAccount $account, SendResult $subscribe): JsonResponse
    {
        $this->health->run($account);

        return response()->json([
            'ok' => true,
            'subscribed' => $subscribe->success,
            'subscribe_error' => $subscribe->success ? null : $subscribe->error,
            'account' => $this->present($account->refresh()),
            'accounts' => $this->accountsPayload(),
        ]);
    }

    /** Only live Meta accounts are managed from this page (demo accounts stay on Advanced). */
    private function ensureManaged(ChannelAccount $channel): void
    {
        abort_unless($channel->isLive() && in_array($channel->platform, [Platform::Facebook, Platform::Instagram, Platform::WhatsApp], true), 404);
    }

    /**
     * @return array{facebook: ?array<string, mixed>, instagram: ?array<string, mixed>, whatsapp: ?array<string, mixed>}
     */
    private function accountsPayload(): array
    {
        return [
            'facebook' => ($a = $this->facebook->liveAccount(includeDisconnected: true)) ? $this->present($a) : null,
            'instagram' => ($a = $this->instagram->liveAccount(includeDisconnected: true)) ? $this->present($a) : null,
            'whatsapp' => ($a = $this->whatsapp->liveAccount(includeDisconnected: true)) ? $this->present($a) : null,
        ];
    }

    /**
     * Built field by field: no credentials, ever.
     *
     * @return array<string, mixed>
     */
    private function present(ChannelAccount $a): array
    {
        $profile = $a->profile ?? [];

        return [
            'id' => $a->id,
            'platform' => $a->platform->value,
            'name' => $a->name,
            'external_id' => $a->external_id,
            'status' => $a->status,
            'connected_at' => ($a->connected_at ?? $a->created_at)?->toIso8601String(),
            'last_inbound_at' => ConnectionHealthCheck::lastInboundAt($a)?->toIso8601String(),
            'last_webhook_at' => $a->last_webhook_at?->toIso8601String(),
            'last_error' => $a->last_error,
            'has_token' => filled($a->graphToken()),
            'profile' => [
                'picture' => $profile['picture'] ?? null,
                'username' => $profile['username'] ?? null,
                'category' => $profile['category'] ?? null,
                'method' => $profile['method'] ?? null,
                'page_id' => $profile['page_id'] ?? null,
                'waba_id' => $a->platform === Platform::WhatsApp ? $a->wabaId() : null,
                'display_phone_number' => $profile['display_phone_number'] ?? null,
                'verified_name' => $profile['verified_name'] ?? null,
                'quality_rating' => $profile['quality_rating'] ?? null,
                'override_callback' => (bool) ($profile['override_callback'] ?? false),
            ],
            'health' => $a->health,
            'health_status' => $a->health_status,
            'health_checked_at' => $a->health_checked_at?->toIso8601String(),
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function shopifyPayload(): ?array
    {
        $integration = $this->shopify->current();

        if ($integration === null) {
            return null;
        }

        return [
            'status' => $integration->status,
            'shop_name' => $integration->shop_name,
            'shop_domain' => $integration->shop_domain,
            'connected_at' => $integration->connected_at?->toIso8601String(),
            'last_error' => $integration->last_error,
        ];
    }

    private function callbackUrl(string $platform): string
    {
        return rtrim((string) config('app.url'), '/').'/webhooks/'.$platform;
    }

    /**
     * @return array{token: string, pages: list<array<string, mixed>>}|null
     */
    private function systemToken(Request $request): ?array
    {
        $stored = $request->session()->get(self::SYSTEM_TOKEN_KEY);

        if (! is_string($stored)) {
            return null;
        }

        try {
            $data = decrypt($stored);
        } catch (DecryptException) {
            return null;
        }

        if (! is_array($data) || ($data['expires_at'] ?? 0) < now()->getTimestamp() || blank($data['token'] ?? null)) {
            $request->session()->forget(self::SYSTEM_TOKEN_KEY);

            return null;
        }

        return ['token' => (string) $data['token'], 'pages' => (array) ($data['pages'] ?? [])];
    }
}
