<?php

namespace App\Ads\Alerts;

use App\Ads\Reports\AdsFilter;
use App\Inbox\Outcomes\ChatFunnel;
use Carbon\CarbonImmutable;

/**
 * The S3 chat funnel per ad (chats, to_agent, orders, delivered, returned, reasons = outcome counts) as the rules read it.
 * Not final: tests bind fixed rows (A6).
 */
class ChatSignals
{
    public function __construct(private readonly ChatFunnel $funnel) {}

    /**
     * @param  list<int>  $adIds
     * @return array<int, array{chats:int, to_agent:int, orders:int, delivered:int, returned:int, reasons: array<string,int>}>
     */
    public function forAds(array $adIds, string $from, string $to): array
    {
        if ($adIds === []) {
            return [];
        }

        return $this->funnel->forAds(
            $adIds,
            CarbonImmutable::parse($from, AdsFilter::TIMEZONE)->startOfDay(),
            CarbonImmutable::parse($to, AdsFilter::TIMEZONE)->endOfDay(),
        );
    }

    /** @return array{chats:int, to_agent:int, orders:int, delivered:int, returned:int, reasons: array<string,int>} */
    public static function empty(): array
    {
        return ['chats' => 0, 'to_agent' => 0, 'orders' => 0, 'delivered' => 0, 'returned' => 0, 'reasons' => []];
    }
}
