<?php

namespace App\Settings;

use App\Enums\QuickReplyScope;
use App\Models\AdAccount;
use App\Models\Branch;
use App\Models\ChannelAccount;
use App\Models\City;
use App\Models\MediaBuyer;
use App\Models\QueueSetting;
use App\Models\QuickReply;
use App\Models\Tag;
use App\Models\User;
use App\Onboarding\OnboardingProgress;
use App\Shopify\Connection\IntegrationRepository;
use App\TestLinks\TestScope;

/**
 * «الإعدادات» as one page (A5, IA §3.3 / DESIGN §3 SettingsHubCard): groups of settings, each item with its link and a
 * one-line state read with one cheap query. Visibility mirrors the routes' own middleware, so a card never links to
 * a 403. Labels live in the front end (`settings_hub.*`); this only says which items and what state.
 */
final class SettingsHub
{
    public function __construct(
        private readonly IntegrationRepository $shopify,
        private readonly OnboardingProgress $onboarding,
    ) {}

    /** @return list<array{id: string, items: list<array<string, mixed>>}> */
    public function for(User $user): array
    {
        $admin = $user->isAdmin();
        $manager = $user->isSupervisorOrAbove();

        $groups = [
            ['id' => 'account', 'items' => array_values(array_filter([
                ['id' => 'profile', 'href' => '/settings/profile', 'text' => $user->name],
                // RestrictAdsRoles keeps the ads roles out of the saved replies (an inbox tool).
                $user->isAdsRole() ? null : $this->quickReplies($user),
            ]))],
        ];

        if ($manager) {
            $groups[] = ['id' => 'team', 'items' => array_values(array_filter([
                $admin ? ['id' => 'users', 'href' => '/settings/users', 'count' => User::query()->where('is_active', true)->count()] : null,
                ['id' => 'queue', 'href' => '/settings/queue', 'state' => QueueSetting::current()->enabled ? 'on' : 'off'],
                ['id' => 'tags', 'href' => '/settings/tags', 'count' => Tag::query()->count()],
            ]))];
        }

        if ($admin) {
            $groups[] = ['id' => 'channels', 'items' => [$this->channels(), $this->shopifyItem()]];
            $groups[] = ['id' => 'store', 'items' => [
                ['id' => 'cities', 'href' => '/settings/cities', 'count' => City::query()->count()],
                ['id' => 'branch_hours', 'href' => '/settings/branch-hours', 'count' => Branch::query()->where('is_active', true)->count()],
                ['id' => 'order_confirmations', 'href' => '/settings/order-confirmations'],
            ]];
        }

        if ($manager) {
            $groups[] = ['id' => 'ads', 'items' => [
                ['id' => 'ad_accounts', 'href' => '/settings/ads/accounts', 'count' => AdAccount::query()->where('is_active', true)->count()],
                ['id' => 'ad_buyers', 'href' => '/settings/ads/buyers', 'count' => MediaBuyer::query()->where('is_active', true)->count()],
                ['id' => 'ad_rules', 'href' => '/settings/ads/rules'],
            ]];
        }

        if ($admin) {
            $progress = $this->onboarding->build();
            $groups[] = ['id' => 'start', 'items' => [[
                'id' => 'onboarding', 'href' => '/onboarding', 'done' => $progress['done'], 'total' => $progress['total'],
                'tone' => $progress['complete'] ? 'ok' : 'warn',
            ]]];

            if (config('crm.dev_tools')) {
                $groups[] = ['id' => 'dev', 'items' => [
                    ['id' => 'simulator', 'href' => '/simulator', 'tone' => 'off'],
                    ['id' => 'latency', 'href' => '/reports/latency', 'tone' => 'off'],
                ]];
            }
        }

        return $groups;
    }

    /** @return array<string, mixed> */
    private function quickReplies(User $user): array
    {
        $mine = QuickReply::query()->where('scope', QuickReplyScope::Personal->value)->where('user_id', $user->id)->count();

        // A moderator manages only her own replies: «ردودي الجاهزة» with her count (IA §3.3).
        return $user->isSupervisorOrAbove()
            ? ['id' => 'quick_replies', 'href' => '/settings/quick-replies', 'mine' => $mine, 'shared' => QuickReply::query()->where('scope', QuickReplyScope::Shared->value)->count()]
            : ['id' => 'quick_replies', 'href' => '/settings/quick-replies', 'mine' => $mine, 'personal' => true];
    }

    /** @return array<string, mixed> */
    private function channels(): array
    {
        $accounts = ChannelAccount::query()->where('driver', '!=', TestScope::DRIVER)->where('is_load_test', false);
        $broken = (clone $accounts)->whereNotNull('last_error')->count();

        return ['id' => 'integrations', 'href' => '/settings/integrations', 'count' => (clone $accounts)->count(), 'tone' => $broken > 0 ? 'danger' : 'ok'];
    }

    /** @return array<string, mixed> */
    private function shopifyItem(): array
    {
        $shop = $this->shopify->current();

        return ['id' => 'shopify', 'href' => '/settings/shopify', 'text' => $shop?->shop_domain,
            'state' => $shop === null ? 'off' : ($shop->last_error ? 'error' : 'connected'),
            'tone' => $shop === null ? 'off' : ($shop->last_error ? 'danger' : 'ok')];
    }
}
