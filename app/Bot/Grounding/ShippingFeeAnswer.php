<?php

namespace App\Bot\Grounding;

use App\Commerce\ShippingQuote;
use App\Models\ShippingZoneRegion;

/**
 * The shipping-fee fact for a "الشحن بكام" question, always from the Shopify
 * rates synced into shipping_zones / shipping_rates (daily by
 * shopify:sync-shipping); no fee is ever written in a script or knowledge text.
 *
 * - A governorate in the text: that governorate's Shopify rate.
 * - No governorate, one rate for every governorate: that flat rate.
 * - No governorate, rates differ: ask for the governorate.
 * - Nothing synced for it: no number and no question (the fee shows at checkout).
 */
final class ShippingFeeAnswer
{
    public const ASK_GOVERNORATE = 'ممكن أعرف حضرتك من أنهي محافظة عشان أقولك مصاريف الشحن بالظبط؟ 🌸';

    public function __construct(
        private readonly GovernorateMatcher $governorates,
        private readonly ShippingQuote $quotes,
    ) {}

    /** @return array{fact: ?string, asks_governorate: bool, governorate: ?string} */
    public function for(string $text): array
    {
        $code = $this->governorates->match($text);

        if ($code !== null) {
            return ['fact' => $this->governorateFact($code), 'asks_governorate' => false, 'governorate' => $code];
        }

        $flat = $this->quotes->domesticFlatRate();

        if ($flat !== null) {
            return ['fact' => 'مصاريف الشحن لكل المحافظات: '.self::amount($flat->price).' جنيه', 'asks_governorate' => false, 'governorate' => null];
        }

        // Nothing synced from Shopify yet: asking for the governorate would not help.
        $synced = ShippingZoneRegion::query()->where('country_code', 'EG')->exists();

        return ['fact' => null, 'asks_governorate' => $synced, 'governorate' => null];
    }

    public function governorateFact(string $code): ?string
    {
        $rate = $this->quotes->shopifyRate($code);

        return $rate === null ? null : 'مصاريف الشحن لـ'.$this->governorates->name($code).': '.self::amount($rate->price).' جنيه';
    }

    public function matchGovernorate(string $text): ?string
    {
        return $this->governorates->match($text);
    }

    private static function amount(string $price): string
    {
        return rtrim(rtrim($price, '0'), '.');
    }
}
