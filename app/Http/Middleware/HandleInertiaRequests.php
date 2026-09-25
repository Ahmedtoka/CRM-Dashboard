<?php

namespace App\Http\Middleware;

use App\Channels\Integrations\ConnectionHealthCheck;
use App\Enums\Platform;
use App\Models\ChannelAccount;
use App\Models\User;
use App\Onboarding\OnboardingProgress;
use App\Shopify\Connection\IntegrationRepository;
use Illuminate\Foundation\Inspiring;
use Illuminate\Http\Request;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    public function __construct(private readonly IntegrationRepository $shopifyIntegrations) {}

    /**
     * The root template that's loaded on the first page visit.
     *
     * @see https://inertiajs.com/server-side-setup#root-template
     *
     * @var string
     */
    protected $rootView = 'app';

    /**
     * Determines the current asset version.
     *
     * @see https://inertiajs.com/asset-versioning
     */
    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    /**
     * Define the props that are shared by default.
     *
     * @see https://inertiajs.com/shared-data
     *
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        [$message, $author] = str(Inspiring::quotes()->random())->explode('-');

        /** @var User|null $user */
        $user = $request->user();

        return array_merge(parent::share($request), [
            'name' => config('app.name'),
            'quote' => ['message' => trim($message), 'author' => trim($author)],
            'auth' => [
                'user' => $user ? [
                    'id' => $user->id,
                    'name' => $user->name,
                    'email' => $user->email,
                    'email_verified_at' => $user->email_verified_at?->toIso8601String(),
                    'role' => $user->role?->value,
                    'color' => $user->color,
                    'locale' => $user->locale,
                    'platforms' => array_map(fn (Platform $p) => $p->value, $user->platforms()),
                    'preferences' => $user->notificationPreferences(),
                ] : null,
            ],
            'locale' => fn () => app()->getLocale(),
            'translations' => fn () => $this->translations(app()->getLocale()),
            'platforms' => array_map(fn (Platform $p) => [
                'value' => $p->value,
                'label' => $p->label(),
                'color' => $p->color(),
            ], Platform::cases()),
            // Spec §9: channel credential errors alert admins (Shopify error/disconnected included, spec §7).
            'channelAlerts' => fn () => $user?->isAdmin()
                ? ChannelAccount::where('status', 'error')->orderBy('id')->get(['id', 'platform', 'name', 'last_error'])
                    // `last_error` holds a stable `problem:<code>` for our own health checks;
                    // the sentence is chosen here, in the reading admin's locale.
                    ->map(fn (ChannelAccount $a) => [
                        'id' => $a->id,
                        'platform' => $a->platform?->value,
                        'name' => $a->name,
                        'last_error' => ConnectionHealthCheck::problemText($a->last_error),
                    ])
                    ->concat($this->shopifyAlerts())
                : [],
            'broadcasting' => fn () => $this->broadcasting(),
            'whatsappTemplates' => fn () => config('crm.whatsapp_templates', []),
            // «ابدأ من هنا» (2026-09-26): the admin's setup progress, for the menu item and its badge.
            'onboarding' => fn () => $user?->isAdmin() ? collect(app(OnboardingProgress::class)->build())->only(['done', 'total', 'percent', 'complete', 'dismissed'])->all() : null,
            // Developer-only nav entries (simulator, latency report) show only when this is on.
            'devTools' => (bool) config('crm.dev_tools'),
        ]);
    }

    /**
     * UI strings from lang/{locale}.json (added by the frontend tasks); empty when absent.
     *
     * @return array<string, string>
     */
    private function translations(string $locale): array
    {
        $path = lang_path($locale.'.json');

        if (! is_file($path)) {
            return [];
        }

        return json_decode((string) file_get_contents($path), true) ?: [];
    }

    /**
     * A `shopify` pseudo channel-alert row (spec §7) — same shape as a
     * `ChannelAccount` row so AppLayout's banner renders it unchanged.
     *
     * @return array<int, array{id: string, platform: string, name: string, last_error: ?string}>
     */
    private function shopifyAlerts(): array
    {
        $integration = $this->shopifyIntegrations->current();

        if ($integration === null || ! in_array($integration->status, ['error', 'disconnected'], true)) {
            return [];
        }

        return [[
            'id' => 'shopify',
            'platform' => 'shopify',
            'name' => $integration->shop_domain ?? 'Shopify',
            'last_error' => $integration->last_error,
        ]];
    }

    /**
     * @return array{key: string, host: ?string, port: int|string|null, scheme: ?string}|null
     */
    private function broadcasting(): ?array
    {
        // Hosted Pusher (e.g. on Cloudways, where a Reverb daemon/port is impractical).
        if (config('broadcasting.default') === 'pusher') {
            $pusher = config('broadcasting.connections.pusher');

            if (empty($pusher['key'])) {
                return null;
            }

            return [
                'driver' => 'pusher',
                'key' => $pusher['key'],
                'cluster' => $pusher['options']['cluster'] ?? 'mt1',
                'host' => null,
                'port' => null,
                'scheme' => 'https',
            ];
        }

        $reverb = config('broadcasting.connections.reverb');

        if (empty($reverb['key'])) {
            return null;
        }

        return [
            'key' => $reverb['key'],
            'host' => $reverb['options']['host'] ?? null,
            'port' => $reverb['options']['port'] ?? null,
            'scheme' => $reverb['options']['scheme'] ?? null,
        ];
    }
}
