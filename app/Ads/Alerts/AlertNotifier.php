<?php

namespace App\Ads\Alerts;

use App\Ads\Reports\AdsFilter;
use App\Inbox\UserNotifier;
use App\Models\AdsAlert;
use App\Models\UserNotification;
use Carbon\CarbonImmutable;

/**
 * In-app only (D14). Shadow mode (alerts.notify_enabled=false) sends nothing. When on: new critical alerts of one run →
 * one grouped bell item per user who can see them; 09:00 Cairo → one grouped digest item per user with open items.
 */
final class AlertNotifier
{
    public const TYPE = 'ads.alerts';

    public const DIGEST_TYPE = 'ads.alerts_digest';

    public const LINK = '/ads/decisions';

    public function __construct(
        private readonly UserNotifier $notifier,
        private readonly RuleSettings $settings,
        private readonly AlertScope $scope,
        private readonly AlertStore $store,
    ) {}

    /** @param  list<int>  $alertIds */
    public function critical(array $alertIds): int
    {
        if ($alertIds === [] || ! $this->settings->notifyEnabled()) {
            return 0;
        }
        $ids = AdsAlert::query()->whereIn('id', $alertIds)->where('severity', Severity::CRITICAL)->whereNull('notified_at')->pluck('id')->all();
        if ($ids === []) {
            return 0;
        }

        $sent = 0;
        foreach ($this->scope->recipients() as $user) {
            $mine = $this->scope->visible($user)->whereIn('ads_alerts.id', $ids)->get();
            if ($mine->isEmpty()) {
                continue;
            }
            $this->notifier->notify($user, self::TYPE, [
                'count' => $mine->count(), 'critical' => $mine->count(),
                'money' => (int) round((float) $mine->sum('money_at_risk_per_day')), 'link' => self::LINK,
            ]);
            $sent++;
        }
        foreach (AdsAlert::query()->whereIn('id', $ids)->get() as $a) {
            $a->forceFill(['notified_at' => now()])->save();
            $this->store->event($a, 'notified');
        }

        return $sent;
    }

    public function digest(CarbonImmutable $now): int
    {
        if (! $this->settings->notifyEnabled()) {
            return 0;
        }
        $dayStart = $now->setTimezone(AdsFilter::TIMEZONE)->startOfDay()->utc();

        $sent = 0;
        foreach ($this->scope->recipients() as $user) {
            $already = UserNotification::query()->where('user_id', $user->id)->where('type', self::DIGEST_TYPE)->where('created_at', '>=', $dayStart)->exists();
            if ($already) {
                continue;
            }
            $open = $this->scope->visible($user)->where('state', AdsAlert::OPEN)->where('severity', '!=', Severity::INFO)->get();
            if ($open->isEmpty()) {
                continue;
            }
            $this->notifier->notify($user, self::DIGEST_TYPE, [
                'count' => $open->count(), 'critical' => $open->where('severity', Severity::CRITICAL)->count(),
                'money' => (int) round((float) $open->sum('money_at_risk_per_day')), 'link' => self::LINK,
            ]);
            $sent++;
        }

        return $sent;
    }
}
