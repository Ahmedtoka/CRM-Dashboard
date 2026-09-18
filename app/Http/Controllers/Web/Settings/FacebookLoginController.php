<?php

namespace App\Http\Controllers\Web\Settings;

use App\Channels\Adapters\MetaGraphClient;
use App\Channels\Integrations\ConnectionHealthCheck;
use App\Channels\Integrations\FacebookPageConnector;
use App\Channels\Integrations\IntegrationException;
use App\Http\Controllers\Controller;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

/**
 * "Connect with Facebook" for the Messenger channel (Facebook Login, manual flow).
 *
 * connect  → redirect to the Facebook login dialog with a one-time `state`.
 * callback → verify state, exchange the code for a (long-lived) user token and read
 *            `GET /me/accounts` — the Pages this person manages (the documented use
 *            of `pages_show_list`). The list, page tokens included, is kept only in
 *            the session, encrypted and short-lived; the user token is discarded.
 * pages    → picker: the Pages without any token.
 * select   → save the chosen Page on the Messenger account and subscribe its webhooks.
 *
 * Outcomes reach Settings → Integrations as a `facebook_connect` flash of a code (+ page
 * name / Graph message) that the page translates, so it reads right in ar and en.
 */
class FacebookLoginController extends Controller
{
    private const STATE_KEY = 'facebook_login.state';

    private const PAGES_KEY = 'facebook_login.pages';

    private const PAGES_TTL_MINUTES = 15;

    private const SCOPES = [
        'pages_show_list',
        'pages_messaging',
        'pages_manage_metadata',
        'pages_read_engagement',
        'pages_read_user_content',
        'pages_manage_engagement',
    ];

    public function __construct(private readonly FacebookPageConnector $connector) {}

    /**
     * What the Channels page needs to render the button and the redirect-URI hint.
     *
     * @return array{enabled: bool, secret_missing: bool, redirect_uri: string, flash: mixed}
     */
    public static function settings(): array
    {
        return [
            'enabled' => filled(config('crm.meta.app_id')),
            'secret_missing' => blank(config('crm.meta.app_secret')),
            'redirect_uri' => self::redirectUri(),
            'flash' => session('facebook_connect'),
        ];
    }

    /**
     * The absolute callback URL, always built on APP_URL (the address registered in
     * Meta's "Valid OAuth Redirect URIs"), never on the host this request came in on.
     */
    public static function redirectUri(): string
    {
        return rtrim((string) config('app.url'), '/').route('settings.channels.facebook.callback', [], false);
    }

    public function connect(Request $request): RedirectResponse
    {
        if (blank(config('crm.meta.app_id'))) {
            return $this->back('app_id_missing');
        }

        if (blank(config('crm.meta.app_secret'))) {
            return $this->back('app_secret_missing');
        }

        $state = Str::random(40);
        $request->session()->put(self::STATE_KEY, $state);

        $query = [
            'client_id' => config('crm.meta.app_id'),
            'redirect_uri' => self::redirectUri(),
            'state' => $state,
            'response_type' => 'code',
        ];

        if (filled(config('crm.meta.login_config_id'))) {
            $query['config_id'] = config('crm.meta.login_config_id');
        } else {
            $query['scope'] = implode(',', self::SCOPES);
        }

        return redirect()->away('https://www.facebook.com/'.$this->version().'/dialog/oauth?'.http_build_query($query));
    }

    public function callback(Request $request): RedirectResponse
    {
        $expected = $request->session()->pull(self::STATE_KEY);
        $state = (string) $request->query('state', '');

        if (! is_string($expected) || $expected === '' || ! hash_equals($expected, $state)) {
            return $this->back('state_mismatch');
        }

        // The person closed the dialog or pressed "Cancel" / "Not now".
        if ($request->filled('error') || $request->filled('error_reason')) {
            return $this->back('cancelled');
        }

        $code = (string) $request->query('code', '');

        if ($code === '') {
            return $this->back('cancelled');
        }

        try {
            $short = $this->oauth()->get('oauth/access_token', [
                'client_id' => config('crm.meta.app_id'),
                'client_secret' => config('crm.meta.app_secret'),
                'redirect_uri' => self::redirectUri(),
                'code' => $code,
            ]);

            if ($short->failed() || blank($short->json('access_token'))) {
                return $this->back('graph_error', $short->json('error.message'));
            }

            $long = $this->oauth()->get('oauth/access_token', [
                'grant_type' => 'fb_exchange_token',
                'client_id' => config('crm.meta.app_id'),
                'client_secret' => config('crm.meta.app_secret'),
                'fb_exchange_token' => $short->json('access_token'),
            ]);

            // A failed long-lived exchange is not fatal: the short-lived token still lists the pages.
            $userToken = (string) ($long->successful() && filled($long->json('access_token'))
                ? $long->json('access_token')
                : $short->json('access_token'));

            $pages = $this->connector->pagesForToken($userToken);
        } catch (ConnectionException) {
            return $this->back('graph_error');
        } catch (IntegrationException $e) {
            return $this->back('graph_error', $e->detail);
        }

        if ($pages === []) {
            return $this->back('no_pages');
        }

        $request->session()->put(self::PAGES_KEY, encrypt([
            'expires_at' => now()->addMinutes(self::PAGES_TTL_MINUTES)->getTimestamp(),
            'pages' => $pages,
        ]));

        return redirect()->route('settings.channels.facebook.pages');
    }

    public function pages(Request $request): Response|RedirectResponse
    {
        $pages = $this->sessionPages($request);

        if ($pages === null) {
            return $this->back('expired');
        }

        $connectedId = $this->connector->liveAccount()?->external_id;

        return Inertia::render('settings/FacebookPages', [
            // Deliberately rebuilt field by field: page access tokens never leave the server.
            'pages' => array_map(fn (array $page) => [
                'id' => $page['id'],
                'name' => $page['name'],
                'category' => $page['category'],
                'picture' => $page['picture'],
                'tasks' => $page['tasks'],
                'missing_tasks' => $this->missingTasks($page['tasks']),
                'connected' => $connectedId !== null && (string) $connectedId === $page['id'],
            ], $pages),
        ]);
    }

    public function select(Request $request, string $pageId): RedirectResponse
    {
        $page = collect($this->sessionPages($request) ?? [])->firstWhere('id', $pageId);

        abort_if($page === null, 404);

        if ($this->missingTasks($page['tasks']) !== []) {
            return $this->back('missing_tasks', null, $page['name']);
        }

        $request->session()->forget(self::PAGES_KEY);

        [$account, $result] = $this->connector->connect($page, 'login');

        // Land on the Integrations card in its real state (token scopes, subscription).
        rescue(fn () => app(ConnectionHealthCheck::class)->run($account), report: true);

        if (! $result->success) {
            return $this->back('subscribe_failed', $result->error, $page['name']);
        }

        return $this->back('connected', null, $page['name']);
    }

    /**
     * The pages list from the session, or null when missing, tampered with or expired.
     *
     * @return list<array<string, mixed>>|null
     */
    private function sessionPages(Request $request): ?array
    {
        $stored = $request->session()->get(self::PAGES_KEY);

        if (! is_string($stored)) {
            return null;
        }

        try {
            $data = decrypt($stored);
        } catch (DecryptException) {
            return null;
        }

        if (! is_array($data) || ($data['expires_at'] ?? 0) < now()->getTimestamp()) {
            $request->session()->forget(self::PAGES_KEY);

            return null;
        }

        return $data['pages'] ?? [];
    }

    /**
     * @param  list<string>|null  $tasks
     * @return list<string>
     */
    private function missingTasks(?array $tasks): array
    {
        return FacebookPageConnector::missingTasks($tasks);
    }

    private function oauth(): PendingRequest
    {
        return Http::baseUrl('https://graph.facebook.com/'.$this->version())->timeout(10)->acceptJson();
    }

    private function version(): string
    {
        return (string) config('crm.meta.graph_version', 'v23.0');
    }

    private function back(string $code, ?string $detail = null, ?string $name = null): RedirectResponse
    {
        return redirect()->route('settings.integrations.index')->with('facebook_connect', array_filter([
            'code' => $code,
            'name' => $name,
            'detail' => $detail !== null ? MetaGraphClient::redact($detail) : null,
        ], fn ($v) => $v !== null));
    }
}
