<?php

namespace App\Today;

use App\Http\Support\DateRange;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;

/**
 * The day the manager's page reports (Cairo calendar). Today runs from the Cairo midnight to now; «امبارح» is the
 * whole previous day, the end-of-day report (G12). The ads card reads complete days, so today's ads window starts
 * yesterday (wireframe: «امبارح كامل + النهارده لحد دلوقتي»).
 */
final readonly class TodayWindow
{
    public const TZ = 'Africa/Cairo';

    public const MODES = ['today', 'yesterday'];

    private function __construct(
        public string $mode,
        public string $date,
        public CarbonImmutable $from,
        public CarbonImmutable $to,
        public string $adsFromDate,
        public string $adsToDate,
    ) {}

    public static function for(string $mode, ?CarbonImmutable $now = null): self
    {
        $now ??= CarbonImmutable::now();
        $local = $now->setTimezone(self::TZ);
        $today = $local->toDateString();
        $yesterday = $local->subDay()->toDateString();

        return $mode === 'yesterday'
            ? new self('yesterday', $yesterday, DateRange::startOfCairoDay($yesterday), DateRange::endOfCairoDay($yesterday), $yesterday, $yesterday)
            : new self('today', $today, DateRange::startOfCairoDay($today), $now->utc(), $yesterday, $today);
    }

    public static function fromRequest(Request $r): self
    {
        $day = (string) $r->query('day', 'today');

        return self::for(in_array($day, self::MODES, true) ? $day : 'today');
    }

    public function isToday(): bool
    {
        return $this->mode === 'today';
    }

    /** Deliveries and returns are read on a complete day: yesterday's when the page shows today. */
    public function outcomeDay(): self
    {
        return $this->isToday() ? self::for('yesterday') : $this;
    }
}
