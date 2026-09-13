<?php

namespace App\Http\Support;

use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * Report date range: `from`/`to` are Cairo calendar dates (Y-m-d), converted to the UTC
 * start of `from` and end of `to`. Both default to today in Cairo.
 */
final readonly class DateRange
{
    public function __construct(
        public CarbonImmutable $from,
        public CarbonImmutable $to,
        public string $fromDate,
        public string $toDate,
    ) {}

    public static function fromRequest(Request $request): self
    {
        $request->validate([
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d'],
        ]);

        $tz = (string) config('crm.timezone_display', 'Africa/Cairo');
        $today = CarbonImmutable::now($tz)->toDateString();

        $fromDate = $request->filled('from') ? (string) $request->input('from') : $today;
        $toDate = $request->filled('to') ? (string) $request->input('to') : max($fromDate, $today);

        if ($toDate < $fromDate) {
            throw ValidationException::withMessages(['to' => 'The end date must be on or after the start date.']);
        }

        return new self(
            CarbonImmutable::createFromFormat('Y-m-d', $fromDate, $tz)->startOfDay()->utc(),
            CarbonImmutable::createFromFormat('Y-m-d', $toDate, $tz)->endOfDay()->utc(),
            $fromDate,
            $toDate,
        );
    }

    /**
     * UTC start of a Cairo calendar date (Y-m-d).
     */
    public static function startOfCairoDay(string $date): CarbonImmutable
    {
        return CarbonImmutable::createFromFormat('Y-m-d', $date, (string) config('crm.timezone_display', 'Africa/Cairo'))->startOfDay()->utc();
    }

    /**
     * UTC end of a Cairo calendar date (Y-m-d).
     */
    public static function endOfCairoDay(string $date): CarbonImmutable
    {
        return CarbonImmutable::createFromFormat('Y-m-d', $date, (string) config('crm.timezone_display', 'Africa/Cairo'))->endOfDay()->utc();
    }

    /**
     * @return array{from: string, to: string}
     */
    public function toArray(): array
    {
        return ['from' => $this->fromDate, 'to' => $this->toDate];
    }
}
