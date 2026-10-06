<?php

namespace App\Ads\Alerts;

use App\Ads\Alerts\Rules\ChatPriceShare;
use App\Ads\Alerts\Rules\ChatSizeOutShare;
use App\Ads\Alerts\Rules\ChatsNoOrders;
use App\Ads\Alerts\Rules\HighRefusal;
use App\Ads\Alerts\Rules\InboxSlowForAds;
use App\Ads\Alerts\Rules\OutOfStock;
use App\Ads\Alerts\Rules\PriceMismatch;
use App\Ads\Alerts\Rules\ProductUnavailable;
use App\Ads\Alerts\Rules\ReactivateRestocked;
use App\Ads\Alerts\Rules\SalesBelowBreakeven;
use App\Ads\Alerts\Rules\ScaleWinner;
use App\Ads\Alerts\Rules\SizesBroken;
use App\Ads\Alerts\Rules\SpendNoResult;
use App\Ads\Alerts\Rules\SpendSpikeToday;

/** The rules running tonight (spec 7.2, data we have). msg.cpo_above_target stays off until the link rate is measured (R-08). */
final class RuleRegistry
{
    public const RULES = [
        OutOfStock::class, SpendSpikeToday::class, SpendNoResult::class, SalesBelowBreakeven::class, ChatsNoOrders::class,
        PriceMismatch::class, SizesBroken::class, ProductUnavailable::class, InboxSlowForAds::class, HighRefusal::class,
        ScaleWinner::class, ReactivateRestocked::class, ChatSizeOutShare::class, ChatPriceShare::class,
    ];

    public const DISABLED = ['msg.cpo_above_target'];

    /** @return list<Rule> */
    public function all(): array
    {
        return array_map(fn (string $class) => app($class), self::RULES);
    }

    /** @return list<Rule> hourly runs the hourly rules; daily runs every rule */
    public function for(string $schedule): array
    {
        return array_values(array_filter($this->all(), fn (Rule $r) => $schedule === Rule::DAILY || $r->schedule() === Rule::HOURLY));
    }
}
