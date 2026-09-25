<?php

namespace App\Onboarding;

use App\Bot\Knowledge\KnowledgeDefaults;
use App\Channels\Integrations\FacebookPageConnector;
use App\Enums\Platform;
use App\Http\Controllers\Web\Settings\FacebookLoginController;
use App\Models\BotKnowledgeEntry;
use App\Models\BotSetting;
use App\Models\BotTestLink;
use App\Models\ChannelAccount;
use App\Models\User;
use App\Shopify\Connection\IntegrationRepository;

/**
 * «ابدأ من هنا» (owner, 2026-09-26): the steps a new store's admin walks through — connect the
 * Facebook page, link Instagram, connect WhatsApp and Shopify, fill the store's own information,
 * switch the bot on and open a team test link — each read from what is really connected, so
 * the page is always the truth and never a checklist someone ticks by hand. A fresh admin with
 * no channel connected lands here after login until something is connected or she skips it.
 */
class OnboardingProgress
{
    /** Steps whose completion ends the automatic redirect (the store can work without the rest). */
    public const REQUIRED = ['facebook', 'bot'];

    public function __construct(
        private readonly FacebookPageConnector $facebook,
        private readonly IntegrationRepository $shopify,
    ) {}

    /**
     * @return array{steps:list<array<string, mixed>>, done:int, total:int, percent:int, complete:bool, dismissed:bool}
     */
    public function build(): array
    {
        $settings = BotSetting::current();
        $facebook = $this->facebook->liveAccount();
        $instagram = $this->live(Platform::Instagram);
        $whatsapp = $this->live(Platform::WhatsApp);
        $shopify = $this->shopify->current();
        $templatesLeft = BotKnowledgeEntry::query()->whereIn('key', KnowledgeDefaults::CORE_KEYS)->where('is_template', true)->count();
        $login = FacebookLoginController::settings();

        $steps = [
            $this->step('facebook', $facebook !== null && $facebook->status === 'connected', $login['enabled'] ? '/settings/channels/facebook/connect' : '/settings/integrations', external: (bool) $login['enabled'], detail: $facebook?->name),
            $this->step('instagram', $instagram !== null, '/settings/integrations#instagram', detail: $instagram?->name, blockedBy: $facebook === null ? 'facebook' : null),
            $this->step('whatsapp', $whatsapp !== null, '/settings/integrations#whatsapp', detail: $whatsapp?->name),
            $this->step('shopify', $shopify !== null && $shopify->status === 'connected', '/settings/shopify', detail: $shopify?->shop_domain),
            $this->step('store_info', filled($settings->store_url) && $templatesLeft === 0, '/settings/bot-replies', detail: $templatesLeft > 0 ? (string) $templatesLeft : null),
            $this->step('bot', (bool) $settings->enabled, '/settings/bot'),
            $this->step('test_link', BotTestLink::query()->where('is_active', true)->exists(), '/settings/bot-test-links'),
        ];

        $done = count(array_filter($steps, fn (array $s) => $s['done']));
        $required = array_filter($steps, fn (array $s) => in_array($s['key'], self::REQUIRED, true));

        return [
            'steps' => $steps,
            'done' => $done,
            'total' => count($steps),
            'percent' => (int) round($done / count($steps) * 100),
            'complete' => count(array_filter($required, fn (array $s) => $s['done'])) === count($required),
            'dismissed' => $settings->onboarding_dismissed_at !== null,
        ];
    }

    /** A fresh admin with nothing connected lands on the page after login, until she connects something or skips. */
    public function shouldRedirect(?User $user): bool
    {
        if ($user === null || ! $user->isAdmin() || BotSetting::current()->onboarding_dismissed_at !== null) {
            return false;
        }

        return ! ChannelAccount::query()->where('driver', 'live')->where('status', '!=', 'disconnected')->exists();
    }

    /** @return array<string, mixed> */
    private function step(string $key, bool $done, string $href, bool $external = false, ?string $detail = null, ?string $blockedBy = null): array
    {
        return ['key' => $key, 'done' => $done, 'href' => $href, 'external' => $external, 'detail' => $detail, 'blocked_by' => $blockedBy, 'required' => in_array($key, self::REQUIRED, true)];
    }

    private function live(Platform $platform): ?ChannelAccount
    {
        return ChannelAccount::query()->where('platform', $platform->value)->where('driver', 'live')->where('status', '!=', 'disconnected')->orderBy('id')->first();
    }
}
