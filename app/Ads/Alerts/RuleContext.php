<?php

namespace App\Ads\Alerts;

use App\Ads\Reports\AdsFilter;
use App\Models\Ad;
use App\Models\AdAccount;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/** Everything one account's evaluation shares: clock (Cairo), live ads, memoised sums, gates, break-even and targets. */
final class RuleContext
{
    /** @var array<string, array<int, array<string, mixed>>> */
    private array $sumsMemo = [];

    /** @var array<string, array<int, float>> */
    private array $setMemo = [];

    /** @var array<string, mixed>|null */
    private ?array $fresh = null;

    /** @param  Collection<int, Ad>  $ads  live ads keyed by id */
    public function __construct(
        public readonly AdAccount $account,
        public readonly CarbonImmutable $now,
        public readonly Collection $ads,
        public readonly AlertData $data,
        public readonly Gates $gates,
        public readonly RuleSettings $settings,
        private readonly BreakEven $breakEven,
        private readonly Targets $targets,
    ) {}

    public static function for(AdAccount $account, ?CarbonImmutable $now = null): self
    {
        $now = ($now ?? CarbonImmutable::now(AdsFilter::TIMEZONE))->setTimezone(AdsFilter::TIMEZONE);
        $data = app(AlertData::class);

        return new self($account, $now, $data->liveAds($account->id), $data, app(Gates::class), app(RuleSettings::class), app(BreakEven::class), app(Targets::class));
    }

    public function today(): CarbonImmutable
    {
        return $this->now->startOfDay();
    }

    public function day(int $offset): string
    {
        return $this->today()->addDays($offset)->toDateString();
    }

    /** @return array<int, array<string, mixed>> sums of every live ad over [from, to] */
    public function sums(string $from, string $to): array
    {
        return $this->sumsMemo[$from.'|'.$to] ??= $this->data->sums($this->ads->keys()->all(), $from, $to);
    }

    /** @return array{spend: float, purchases: float, purchase_value: float, msg_conversations: int, days_live: int, first_day: ?string} */
    public function sumOf(int $adId, string $from, string $to): array
    {
        return $this->sums($from, $to)[$adId] ?? AlertData::emptySums();
    }

    public function family(Ad $ad): string
    {
        return Family::of($ad->campaign?->objective, $this->sumOf($ad->id, $this->day(-30), $this->day(0))['msg_conversations']);
    }

    /** Average spend per day over the last 3 complete days: the money at stake per day (U 5.1.3). */
    public function dailySpend(int $adId): float
    {
        return round($this->sumOf($adId, $this->day(-3), $this->day(-1))['spend'] / 3, 2);
    }

    /** @return array{ok: bool, last_ok_at: ?string, age_hours: ?float} */
    public function fresh(): array
    {
        return $this->fresh ??= $this->gates->dataFresh($this->account, $this->now, $this->data);
    }

    public function barelyDelivered(Ad $ad, string $from, string $to): bool
    {
        if ($ad->ad_set_id === null) {
            return false;
        }
        $sets = $this->setMemo[$from.'|'.$to] ??= $this->data->adSetSpend($this->ads->pluck('ad_set_id')->filter()->unique()->values()->all(), $from, $to);

        return $this->gates->barelyDelivered($this->sumOf($ad->id, $from, $to)['spend'], $sets[$ad->ad_set_id] ?? 0.0);
    }

    public function isLearning(int $adId): bool
    {
        return $this->gates->learning($this->sumOf($adId, $this->day(-30), $this->day(0))['first_day'], $this->day(0));
    }

    /** @return array<string, mixed> BreakEven::explain() of this account */
    public function breakEven(): array
    {
        return $this->breakEven->explain($this->account->id);
    }

    /** @return array{cpp: ?float, cpp_source: string, cpo: ?float, cpo_source: string, cpc: ?float} */
    public function targets(): array
    {
        return $this->targets->forAccount($this->account->id, $this->today());
    }
}
