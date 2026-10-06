<?php

namespace App\Ads\Alerts;

use App\Ads\Alerts\Rules\OutOfStock;
use App\Ads\Reports\AdsFilter;
use App\Inbox\UserNotifier;
use App\Models\AdsAlert;
use App\Models\Product;
use App\Models\User;
use App\Models\UserNotification;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * In-app only (D14). Shadow mode (alerts.notify_enabled=false) sends nothing. When on: new critical alerts of one run →
 * one grouped bell item per user who can see them; out-of-stock alerts → one item per product per day; 09:00 Cairo →
 * one grouped digest item per user with open items.
 */
final class AlertNotifier
{
    public const TYPE = 'ads.alerts';

    public const DIGEST_TYPE = 'ads.alerts_digest';

    public const STOCK_TYPE = 'ads.alerts_stock';

    public const LINK = '/ads/decisions';

    public function __construct(
        private readonly UserNotifier $notifier,
        private readonly RuleSettings $settings,
        private readonly AlertScope $scope,
        private readonly AlertStore $store,
    ) {}

    /**
     * New alerts of one run: critical ones (except stock) → one grouped bell item per user who can see them; an
     * out-of-stock alert at ANY severity (Messages / multi-product check stock is high, R-06 hands StockWatcher's notice
     * over to it) → one item per product per user per Cairo day.
     *
     * @param  list<int>  $alertIds
     */
    public function critical(array $alertIds): int
    {
        if ($alertIds === [] || ! $this->settings->notifyEnabled()) {
            return 0;
        }
        $rows = AdsAlert::query()->whereIn('id', $alertIds)->whereNull('notified_at')
            ->where(fn ($q) => $q->where('severity', Severity::CRITICAL)->orWhere('rule_id', OutOfStock::ID))->get();
        if ($rows->isEmpty()) {
            return 0;
        }
        $stock = $rows->filter(fn (AdsAlert $a) => $a->rule_id === OutOfStock::ID && $a->product_id !== null);
        $grouped = $rows->diffKeys($stock)->pluck('id')->all();

        $sent = 0;
        $recipients = $this->scope->recipients();
        if ($grouped !== []) {
            foreach ($recipients as $user) {
                $mine = $this->scope->visible($user)->whereIn('ads_alerts.id', $grouped)->get();
                if ($mine->isEmpty()) {
                    continue;
                }
                $this->notifier->notify($user, self::TYPE, [
                    'count' => $mine->count(), 'critical' => $mine->count(),
                    'money' => (int) round((float) $mine->sum('money_at_risk_per_day')), 'link' => self::LINK,
                ]);
                $sent++;
            }
        }
        $sent += $this->stock($stock->groupBy('product_id'), $recipients);

        foreach ($rows as $a) {
            $a->forceFill(['notified_at' => now()])->save();
            $this->store->event($a, 'notified');
        }

        return $sent;
    }

    /**
     * @param  Collection<int|string, Collection<int, AdsAlert>>  $byProduct
     * @param  Collection<int, User>  $recipients
     */
    private function stock(Collection $byProduct, Collection $recipients): int
    {
        if ($byProduct->isEmpty()) {
            return 0;
        }
        $dayStart = CarbonImmutable::now(AdsFilter::TIMEZONE)->startOfDay()->utc();
        $titles = Product::withTrashed()->whereIn('id', $byProduct->keys())->pluck('title', 'id');

        $sent = 0;
        foreach ($byProduct as $productId => $alerts) {
            $ids = $alerts->pluck('id')->all();
            foreach ($recipients as $user) {
                $mine = $this->scope->visible($user)->whereIn('ads_alerts.id', $ids)->get();
                if ($mine->isEmpty()) {
                    continue;
                }
                $already = UserNotification::query()->where('user_id', $user->id)->where('type', self::STOCK_TYPE)
                    ->where('created_at', '>=', $dayStart)->get(['data'])
                    ->contains(fn (UserNotification $n) => (int) ($n->data['product_id'] ?? 0) === (int) $productId);
                if ($already) {
                    continue;
                }
                $this->notifier->notify($user, self::STOCK_TYPE, [
                    'product_id' => (int) $productId, 'product' => (string) ($titles[$productId] ?? ''),
                    'count' => $mine->count(), 'critical' => $mine->where('severity', Severity::CRITICAL)->count(),
                    'money' => (int) round((float) $mine->sum('money_at_risk_per_day')), 'link' => self::LINK,
                ]);
                $sent++;
            }
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
