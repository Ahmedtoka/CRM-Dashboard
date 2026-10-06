<?php

namespace App\Inbox\Outcomes;

/**
 * How a conversation episode ended for the business (control room S3, D13). One per episode in
 * `conversation_outcomes`. `ordered` and `service` can be automatic; `unknown` is only the
 * mobile API's fallback (D16) and an idle chat nobody answered.
 */
enum Outcome: string
{
    case Ordered = 'ordered';
    case Price = 'price';
    case SizeOut = 'size_out';
    case Shipping = 'shipping';
    case NoAnswer = 'no_answer';
    case Browsing = 'browsing';
    case Service = 'service';
    case Other = 'other';
    case Unknown = 'unknown';

    /** @return list<self> what a person may pick in the close menu (ordered is automatic, unknown is a fallback) */
    public static function agentChoices(): array
    {
        return [self::Price, self::SizeOut, self::Shipping, self::Browsing, self::NoAnswer, self::Service, self::Other];
    }

    /** @return list<string> */
    public static function agentValues(): array
    {
        return array_map(fn (self $o) => $o->value, self::agentChoices());
    }

    /** @return list<string> the "why not bought" reasons (C 3.2) */
    public static function lostValues(): array
    {
        return array_values(array_map(fn (self $o) => $o->value, array_filter(self::cases(), fn (self $o) => $o->isLostSale())));
    }

    public function isLostSale(): bool
    {
        return in_array($this, [self::Price, self::SizeOut, self::Shipping, self::NoAnswer, self::Browsing, self::Other], true);
    }

    public function label(): string
    {
        return __('labels.outcomes.'.$this->value);
    }
}
