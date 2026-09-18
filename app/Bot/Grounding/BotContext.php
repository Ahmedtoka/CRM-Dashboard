<?php

namespace App\Bot\Grounding;

use App\Enums\BotIntent;

/**
 * Per-message grounding for the bot (spec §4.3): the facts the AI may use
 * (`lines`) plus what was detected, for the handover summary note.
 */
final readonly class BotContext
{
    /**
     * @param  list<string>  $lines  catalog, shipping quote, knowledge and size chart lines
     * @param  list<string>  $products  product titles matched in the catalog
     * @param  list<string>  $sizes  canonical sizes mentioned by the customer
     * @param  list<string>  $colors  canonical colours mentioned by the customer
     */
    public function __construct(
        public array $lines,
        public ?string $governorate,
        public array $products,
        public array $sizes,
        public array $colors,
    ) {}

    /**
     * A price/availability question about a product the catalog doesn't have
     * (and no shipping quote to answer instead): hand over rather than let
     * the AI answer from unrelated grounding.
     */
    public function missingProductFor(BotIntent $intent): bool
    {
        return in_array($intent, [BotIntent::Price, BotIntent::Availability], true)
            && $this->products === []
            && $this->governorate === null;
    }
}
