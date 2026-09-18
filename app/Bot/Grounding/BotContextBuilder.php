<?php

namespace App\Bot\Grounding;

use App\Bot\CatalogSearch;
use App\Bot\Knowledge\KnowledgeBase;
use App\Bot\Knowledge\SizeChart;
use App\Enums\BotIntent;
use App\Models\BotSetting;

/**
 * Builds the grounding for one inbound message by its classified intent
 * (spec §4.3). Bounded: at most 5 products, one knowledge query, one
 * shipping-zone lookup.
 */
final class BotContextBuilder
{
    public function __construct(
        private readonly GovernorateMatcher $governorates,
        private readonly ShippingFeeAnswer $fees,
        private readonly KnowledgeBase $knowledge,
        private readonly CatalogSearch $catalog,
    ) {}

    public function build(string $text, BotIntent $intent): BotContext
    {
        $lines = [];
        $code = $this->governorates->match($text);

        // Shipping fees come only from the synced Shopify rates (ShippingFeeAnswer).
        if ($code !== null && in_array($intent, [BotIntent::Shipping, BotIntent::Price, BotIntent::Other], true)) {
            if (($fact = $this->fees->governorateFact($code)) !== null) {
                $lines[] = $fact;
            }
        } elseif ($intent === BotIntent::Shipping) {
            $fee = $this->fees->for($text);

            if ($fee['fact'] !== null) {
                $lines[] = $fee['fact'];
            } elseif ($fee['asks_governorate']) {
                $lines[] = 'مصاريف الشحن بتختلف حسب المحافظة: '.ShippingFeeAnswer::ASK_GOVERNORATE;
            }
        }

        $knowledgeKeys = match ($intent) {
            BotIntent::Shipping => ['shipping_times'],
            BotIntent::ExchangeReturn => ['exchange_policy', 'return_policy'],
            BotIntent::Payment => ['payment_methods'],
            BotIntent::Greeting => ['store_intro', 'working_hours_text'],
            BotIntent::Price, BotIntent::Availability => ['fabric_care'],
            default => ['store_intro', 'working_hours_text', 'fabric_care'],
        };

        // Handover intents also look the catalog up, so the summary note can
        // name the products the customer asked to order.
        $catalog = in_array($intent, [BotIntent::Price, BotIntent::Availability, BotIntent::Other, BotIntent::Greeting], true) || $intent->handsOver()
            ? $this->catalog->groupedLinesFor($text) : [];

        if (in_array($intent, [BotIntent::Price, BotIntent::Availability], true)) {
            array_push($lines, ...$catalog);
        }

        foreach ($this->knowledge->many($knowledgeKeys) as $entry) {
            $lines[] = $this->knowledge->line($entry);
        }

        if (! in_array($intent, [BotIntent::Price, BotIntent::Availability], true)) {
            array_push($lines, ...$catalog);
        }

        if ($intent === BotIntent::SizeChart) {
            $lines[] = '[جدول المقاسات] '.str_replace("\n", ' · ', SizeChart::fromSettings(BotSetting::current())->toText());
        }

        // In-memory synonym scan only (the catalog query itself is capped at
        // 8 tokens in CatalogSearch); bounded to the first 40 tokens.
        $tokens = array_map(fn ($t) => preg_replace('/^[؟?!.]+|[؟?!.]+$/u', '', $t) ?? $t, array_slice(preg_split('/[\s\/،,]+/u', $text) ?: [], 0, 40));

        return new BotContext(
            lines: array_values(array_unique($lines)),
            governorate: $code,
            products: array_map(fn ($l) => trim(explode('|', $l)[0]), $catalog),
            sizes: array_values(array_unique(array_filter(array_map(fn ($t) => Synonyms::size($t), $tokens)))),
            colors: array_values(array_unique(array_filter(array_map(fn ($t) => Synonyms::color($t), $tokens)))),
        );
    }
}
