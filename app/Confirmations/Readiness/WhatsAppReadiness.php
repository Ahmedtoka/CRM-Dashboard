<?php

namespace App\Confirmations\Readiness;

use App\Channels\Integrations\WhatsAppConnector;
use App\Confirmations\ConfirmationSettings;
use App\Confirmations\OrderConfirmations;
use App\Confirmations\WhatsApp\TemplateDefinitions;
use App\Models\ChannelAccount;

/**
 * «جاهزية تأكيد الأوردرات بالواتساب» (round 2026-10-24): one checklist the admin reads before switching the real
 * Le Voile number on. Every line is a fact the code can see — which ChannelAccount the confirmations go from
 * (OrderConfirmations::whatsAppAccount, the same rule the job uses), its Phone Number ID / WABA / token, the
 * approval state of the two templates on Meta, the settings (on, 3-minute delay) and the environment rule
 * (production sends only from a live number; a local copy never from one). No secret ever leaves this class.
 */
final class WhatsAppReadiness
{
    public function __construct(
        private readonly OrderConfirmations $confirmations,
        private readonly ConfirmationSettings $settings,
        private readonly WhatsAppConnector $connector,
        private readonly TemplateStatusSource $templates,
    ) {}

    /** @return array<string, mixed> */
    public function check(): array
    {
        $account = $this->confirmations->whatsAppAccount();
        // The number on file even when the job would not pick it (so the card can say why).
        $onFile = $account ?? $this->connector->liveAccount(includeDisconnected: true)
            ?? ChannelAccount::query()->where('platform', 'whatsapp')->where('is_load_test', false)->where('driver', '!=', 'test')->orderBy('id')->first();

        $checks = [];
        $add = function (string $key, ?bool $ok, ?string $detail = null, array $extra = []) use (&$checks) {
            $checks[] = ['key' => $key, 'ok' => $ok, 'detail' => $detail] + $extra;
        };

        $add('account', $account !== null, $onFile === null ? null : ($onFile->profile['display_phone_number'] ?? $onFile->name), [
            'reason' => $account !== null ? null : $this->whyNotUsable($onFile),
        ]);
        $add('phone_number_id', $onFile !== null && filled($onFile->external_id), $onFile?->external_id);
        $add('waba_id', $onFile !== null && $onFile->wabaId() !== null, $onFile?->wabaId());
        $hasToken = $onFile !== null && filled($onFile->credentials['access_token'] ?? null);
        $add('token', $hasToken, null);
        $add('callback_https', str_starts_with(WhatsAppConnector::callbackUrl(), 'https://'), WhatsAppConnector::callbackUrl());

        $templates = $this->templates($onFile, $hasToken);
        $approved = $templates !== null && collect($templates)->every(fn ($t) => $t['status'] === 'APPROVED');
        $add('templates', $templates === null ? null : $approved, $templates === null ? ($this->templateError ?? null) : null);

        $add('enabled', $this->settings->enabled(), null);
        $add('delay', $this->settings->delayMinutes() >= 1, (string) $this->settings->delayMinutes());
        $add('environment', $this->environmentOk($onFile), app()->environment());

        $required = ['account', 'phone_number_id', 'waba_id', 'token', 'templates', 'enabled', 'environment'];
        $ready = collect($checks)->whereIn('key', $required)->every(fn ($c) => $c['ok'] === true);

        return [
            'ready' => $ready,
            'account' => $onFile === null ? null : [
                'id' => $onFile->id,
                'name' => $onFile->name,
                'phone' => $onFile->profile['display_phone_number'] ?? null,
                'verified_name' => $onFile->profile['verified_name'] ?? null,
                'driver' => $onFile->driver,
                'status' => $onFile->status,
                'quality_rating' => $onFile->profile['quality_rating'] ?? null,
                'is_orders_number' => $account !== null && $account->id === $onFile->id,
            ],
            'checks' => $checks,
            'templates' => $templates ?? [],
            'settings' => [
                'enabled' => $this->settings->enabled(),
                'delay_minutes' => $this->settings->delayMinutes(),
                'template_confirm' => $this->settings->template('confirm'),
                'template_reminder' => $this->settings->template('reminder'),
                'template_language' => $this->settings->templateLanguage(),
                'skip_chat_orders' => $this->settings->skipChatOrders(),
                'max_order_age_minutes' => $this->settings->maxOrderAgeMinutes(),
            ],
            'environment' => app()->environment(),
            'live_allowed_here' => app()->isProduction() || (bool) config('crm.confirmations.allow_live_outside_production', false),
        ];
    }

    private ?string $templateError = null;

    /** @return list<array{name:string, language:string, status:string, reason:?string}>|null null when Meta could not be asked */
    private function templates(?ChannelAccount $account, bool $hasToken): ?array
    {
        if ($account === null || ! $hasToken) {
            $this->templateError = 'no_account';

            return null;
        }

        $names = array_values(array_unique([$this->settings->template('confirm'), $this->settings->template('reminder')]));
        $language = $this->settings->templateLanguage();

        try {
            $statuses = $account->isLive()
                ? $this->templates->statuses($account, $names, $language)
                : (new FakeTemplateStatus)->statuses($account, $names, $language);
        } catch (TemplateLookupFailed $e) {
            $this->templateError = $e->reason;

            return null;
        }

        return array_map(fn (string $name) => [
            'name' => $name,
            'language' => $language,
            'status' => $statuses[$name]['status'] ?? 'MISSING',
            'reason' => $statuses[$name]['reason'] ?? null,
            // The two templates the tooling can submit (whatsapp:confirmation-templates --submit).
            'known' => array_key_exists($name, TemplateDefinitions::all()),
        ], $names);
    }

    private function whyNotUsable(?ChannelAccount $onFile): string
    {
        return match (true) {
            $onFile === null => 'none',
            $onFile->status !== 'connected' => 'disconnected',
            $onFile->is_load_test => 'load_test',
            app()->isProduction() && ! $onFile->isLive() => 'fake_in_production',
            ! app()->isProduction() && $onFile->isLive() => 'live_outside_production',
            default => 'unknown',
        };
    }

    private function environmentOk(?ChannelAccount $account): bool
    {
        if (app()->isProduction()) {
            return $account === null || $account->isLive();
        }

        // A local or staging copy may not message real customers from the real number.
        return $account === null || ! $account->isLive() || (bool) config('crm.confirmations.allow_live_outside_production', false);
    }
}
