<?php

namespace App\Navigation;

use App\Inbox\ConversationQuery;
use App\Models\BotSuggestion;
use App\Models\OrderConfirmation;
use App\Models\QueueSetting;
use App\Models\SupportCase;
use App\Models\User;
use App\Queue\BoardAccess;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;

/**
 * The menu's badges (redesign 2026-10-17, IA §3.1.6): counts on waiting work only, red once it is past an SLA.
 * Shared on every page, so each count is one cheap query and the set is cached per user for TTL seconds. The room
 * reuses the inbox's own filters (as «النهارده»'s urgent strip does), so the badge and the list never disagree. The
 * ads counts (decisions, launches) are already shared by the Ads Hub and are not repeated here.
 */
final class NavBadges
{
    public const TTL = 30;

    public function __construct(private readonly ConversationQuery $conversations) {}

    /** @return array<string, int|bool> */
    public function for(User $user): array
    {
        return Cache::remember("nav-badges:{$user->id}", self::TTL, fn () => $this->compute($user));
    }

    /** @return array<string, int|bool> */
    public function compute(User $user, ?CarbonImmutable $now = null): array
    {
        if ($user->isAdsRole()) {
            return [];
        }
        $now ??= CarbonImmutable::now();

        $open = SupportCase::query()->whereIn('status', SupportCase::OPEN_STATUSES);
        $badges = [
            'confirmations' => OrderConfirmation::query()->needsCall()->count(),
            'cases' => (clone $open)->count(),
            'cases_urgent' => (clone $open)->whereNotNull('sla_due_at')->where('sla_due_at', '<', $now)->exists(),
        ];

        if (BoardAccess::allows($user) && QueueSetting::current()->enabled) {
            $badges['board'] = $this->conversations->filtered($user, ['queue' => 'waiting'])->count();
            $badges['board_urgent'] = $this->conversations->filtered($user, ['queue' => 'overdue'])->exists();
        }

        if ($user->isSupervisorOrAbove()) {
            $badges['learning'] = BotSuggestion::query()->where('status', 'pending')->count();
        }

        return $badges;
    }
}
