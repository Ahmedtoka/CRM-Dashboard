<?php

namespace App\Ads\Alerts;

use App\Ads\Alerts\Rules\ChatsNoOrders;
use App\Ads\Alerts\Rules\InboxSlowForAds;
use App\Ads\Alerts\Rules\SalesBelowBreakeven;
use App\Ads\Alerts\Rules\ScaleWinner;
use App\Ads\Alerts\Rules\SpendNoResult;
use App\Ads\Alerts\Rules\SpendSpikeToday;
use App\Models\AdAccount;
use App\Models\AdsAlert;
use Carbon\CarbonImmutable;

/**
 * Evaluates one ad account (spec 7.2, 7.6): stale data skips performance rules; a performance Stop on the last healthy ad
 * of its ad set becomes «ضيف بديل الأول»; an info suggestion never sits beside a high/critical problem of the same ad; the
 * store persists the rest. DB reads only, no platform call, nothing paused automatically (D6).
 * Thresholds, targets and break-even are EGP: an account in another currency runs the fact rules only, with no money at
 * risk (its spend would be compared with EGP orders and EGP floors).
 */
final class AlertEngine
{
    public const PERFORMANCE_STOPS = [SpendNoResult::ID, SalesBelowBreakeven::ID, ChatsNoOrders::ID];

    public const CURRENCY = 'EGP';

    /** Rules that compare spend with EGP amounts (orders, targets, floors): skipped for a non-EGP account. */
    public const MONEY_RULES = [SpendSpikeToday::ID, SpendNoResult::ID, SalesBelowBreakeven::ID, ChatsNoOrders::ID, ScaleWinner::ID];

    public function __construct(private readonly RuleRegistry $registry, private readonly AlertStore $store) {}

    /** @return array{opened: list<int>, refreshed: int, resolved: int, skipped: list<string>, fresh: bool} */
    public function evaluateAccount(AdAccount $account, string $schedule, ?CarbonImmutable $now = null): array
    {
        $ctx = RuleContext::for($account, $now);
        $fresh = $ctx->fresh()['ok'];
        $currency = strtoupper((string) ($account->currency ?: self::CURRENCY));
        $egp = $currency === self::CURRENCY;
        $ran = [];
        $skipped = [];
        $findings = [];
        foreach ($this->registry->for($schedule) as $rule) {
            if (($rule->isPerformance() && ! $fresh) || (! $egp && in_array($rule->id(), self::MONEY_RULES, true))) {
                $skipped[] = $rule->id();

                continue;
            }
            $ran[] = $rule;
            foreach ($rule->evaluate($ctx) as $finding) {
                $findings[] = $finding;
            }
        }

        $findings = $this->dropInfoBesideProblems($this->replacementGate($this->inboxSlowCap($findings, $ctx), $ctx));
        if (! $egp) {
            $findings = array_map(fn (Finding $f) => $f->with(['moneyAtRiskPerDay' => 0.0, 'evidence' => $f->evidence + ['currency' => $currency]]), $findings);
        }
        $result = $this->store->sync($account->id, $ran, $findings, $ctx->ads->keys()->map(fn ($id) => (int) $id)->all(), $ctx->now);

        return $result + ['skipped' => $skipped, 'fresh' => $fresh];
    }

    /**
     * Catalogue 1.4: a Messages performance Stop is capped at medium, with the suffix «الإنبوكس كان بطيء» (params
     * inbox_slow), when the ad's own chats in the rule window waited too long for a first reply (median > 10 min or
     * > 25 % unanswered within an hour, the msg.inbox_slow_for_ads thresholds): the ad may not be the problem.
     *
     * @param  list<Finding>  $findings
     * @return list<Finding>
     */
    private function inboxSlowCap(array $findings, RuleContext $ctx): array
    {
        $byWindow = [];
        foreach ($findings as $f) {
            if ($this->inboxCappable($f, $ctx)) {
                $w = $f->evidence['window'];
                $byWindow[$w['from'].'|'.$w['to']][] = (string) $ctx->ads[$f->adId]->external_id;
            }
        }
        if ($byWindow === []) {
            return $findings;
        }
        $waits = [];
        foreach ($byWindow as $key => $externals) {
            [$from, $to] = explode('|', $key);
            $waits[$key] = $ctx->data->firstReplyWaitsByAd(array_values(array_unique($externals)), $from, $to);
        }

        return array_map(function (Finding $f) use ($ctx, $waits) {
            if (! $this->inboxCappable($f, $ctx)) {
                return $f;
            }
            $list = $waits[$f->evidence['window']['from'].'|'.$f->evidence['window']['to']][(string) $ctx->ads[$f->adId]->external_id] ?? [];
            if ($list === []) {
                return $f;
            }
            $late = count(array_filter($list, fn (?float $w) => $w === null || $w > InboxSlowForAds::UNANSWERED_AFTER_MIN));
            $share = $late / count($list);
            $median = (float) Stats::median(array_map(fn (?float $w) => $w ?? 1.0e9, $list));
            if ($median <= InboxSlowForAds::SLOW_MEDIAN_MIN && $share <= InboxSlowForAds::UNANSWERED_SHARE) {
                return $f;
            }

            return $f->with([
                'severity' => Severity::rank($f->severity) > Severity::rank(Severity::MEDIUM) ? Severity::MEDIUM : $f->severity,
                'params' => $f->params + ['inbox_slow' => true],
                'evidence' => $f->evidence + ['inbox' => [
                    'chats' => count($list), 'median_minutes' => $median >= 1.0e9 ? null : $median, 'unanswered_share' => round($share, 3),
                    'capped_from' => $f->severity,
                ]],
            ]);
        }, $findings);
    }

    private function inboxCappable(Finding $f, RuleContext $ctx): bool
    {
        return $f->family === Family::MESSAGES && $f->adId !== null && $ctx->ads->has($f->adId)
            && in_array($f->ruleId, [SpendNoResult::ID, ChatsNoOrders::ID], true)
            && isset($f->evidence['window']['from'], $f->evidence['window']['to']);
    }

    /**
     * @param  list<Finding>  $findings
     * @return list<Finding>
     */
    private function replacementGate(array $findings, RuleContext $ctx): array
    {
        $unhealthy = [];
        foreach ($findings as $f) {
            if ($f->adId !== null && Severity::rank($f->severity) >= Severity::rank(Severity::HIGH)) {
                $unhealthy[] = $f->adId;
            }
        }
        $open = AdsAlert::query()->where('ad_account_id', $ctx->account->id)->whereIn('state', AdsAlert::LIVE_STATES)
            ->whereIn('severity', [Severity::CRITICAL, Severity::HIGH])->whereNotNull('ad_id')->pluck('ad_id')->map(fn ($id) => (int) $id)->all();
        $unhealthy = array_values(array_unique([...$unhealthy, ...$open]));

        return array_map(function (Finding $f) use ($ctx, $unhealthy) {
            if ($f->action !== 'stop' || $f->adId === null || ! in_array($f->ruleId, self::PERFORMANCE_STOPS, true)) {
                return $f;
            }
            $ad = $ctx->ads->get($f->adId);
            if ($ad === null || $ctx->gates->hasHealthyAlternative($ad, $ctx->ads, $unhealthy)) {
                return $f;
            }

            return $f->with(['action' => 'add_replacement', 'params' => $f->params + ['no_alternative' => true]]);
        }, $findings);
    }

    /**
     * @param  list<Finding>  $findings
     * @return list<Finding>
     */
    private function dropInfoBesideProblems(array $findings): array
    {
        $problems = [];
        foreach ($findings as $f) {
            if ($f->adId !== null && Severity::rank($f->severity) >= Severity::rank(Severity::HIGH)) {
                $problems[$f->adId] = true;
            }
        }

        return array_values(array_filter($findings, fn (Finding $f) => $f->severity !== Severity::INFO || $f->adId === null || ! isset($problems[$f->adId])));
    }
}
