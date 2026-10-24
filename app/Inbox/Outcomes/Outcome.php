<?php

namespace App\Inbox\Outcomes;

/**
 * How a conversation episode ended for the business (control room S3, D13; restructured
 * 2026-10-24, docs/round-2026-10-24/finish-api.md §1): ONE pick that says what happened in the
 * chat. One row per episode in `conversation_outcomes`.
 *
 * Three groups in the «خلصت» sheet: a sale (ordered, or why she did not buy), a service chat
 * (an order question, a return / exchange / complaint / order change — each records its case),
 * and the rest (she never replied, spam, other). `service` is the pre-restructure key: still
 * readable in old rows and accepted from the installed app, never offered in the sheet.
 * `unknown` is the silent auto-close and the mobile API's fallback (D16).
 */
enum Outcome: string
{
    case Ordered = 'ordered';
    case Price = 'price';
    case SizeOut = 'size_out';
    case Shipping = 'shipping';
    case Browsing = 'browsing';
    case OrderInquiry = 'order_inquiry';
    case ReturnCase = 'return_case';
    case ExchangeCase = 'exchange_case';
    case ComplaintCase = 'complaint_case';
    case OrderChange = 'order_change';
    case NoAnswer = 'no_answer';
    case Spam = 'spam';
    case Other = 'other';
    case Service = 'service';
    case Unknown = 'unknown';

    /** The sheet's groups, in order (finish-api.md §1). */
    public const GROUPS = [
        'sale' => [self::Ordered, self::Price, self::SizeOut, self::Shipping, self::Browsing],
        'service' => [self::OrderInquiry, self::ReturnCase, self::ExchangeCase, self::ComplaintCase, self::OrderChange],
        'rest' => [self::NoAnswer, self::Spam, self::Other],
    ];

    /** Handover categories (bot_summary.category / conversations.handover_category) → the service outcome they suggest. */
    private const HANDOVER_MAP = [
        'return' => self::ReturnCase, 'return_exchange' => self::ReturnCase,
        'exchange' => self::ExchangeCase,
        'complaint' => self::ComplaintCase, 'defect' => self::ComplaintCase,
        'cancel_order' => self::OrderChange, 'edit_order' => self::OrderChange, 'cancel_edit' => self::OrderChange,
        'order_status' => self::OrderInquiry, 'delivery_followup' => self::OrderInquiry, 'late_order' => self::OrderInquiry,
        'delayed_order' => self::OrderInquiry, 'address' => self::OrderInquiry, 'payment' => self::OrderInquiry,
        'order_not_found' => self::OrderInquiry, 'order_hold' => self::OrderInquiry, 'order_returned' => self::OrderInquiry,
        'failed_delivery_attempt' => self::OrderInquiry, 'order_details_missing' => self::OrderInquiry,
        'order_verification_failed' => self::OrderInquiry,
    ];

    /** @return list<self> what a person may pick in «خلصت» (every group, in sheet order; never service or unknown) */
    public static function agentChoices(): array
    {
        return array_merge(...array_values(self::GROUPS));
    }

    /** @return list<string> */
    public static function agentValues(): array
    {
        return array_map(fn (self $o) => $o->value, self::agentChoices());
    }

    /** @return list<string> what the endpoints accept: the picks, plus the legacy `service` the installed app still sends */
    public static function acceptedValues(): array
    {
        return [...self::agentValues(), self::Service->value];
    }

    /** @return list<string> the "why not bought" reasons (C 3.2; the ads funnel and its alert rules) */
    public static function lostValues(): array
    {
        return array_values(array_map(fn (self $o) => $o->value, array_filter(self::cases(), fn (self $o) => $o->isLostSale())));
    }

    /** @return list<string> the outcomes that record a support case */
    public static function caseValues(): array
    {
        return array_values(array_map(fn (self $o) => $o->value, array_filter(self::cases(), fn (self $o) => $o->caseType() !== null)));
    }

    /**
     * The request's `outcome` as an Outcome: a legacy `service` becomes the case outcome of the
     * case type sent with it, else «استفسار عن أوردر». Null for an unknown key.
     */
    public static function fromRequest(?string $value, ?string $caseType = null): ?self
    {
        $o = $value === null ? null : self::tryFrom($value);

        if ($o === self::Service) {
            return ($caseType !== null ? self::fromCaseType($caseType) : null) ?? self::OrderInquiry;
        }

        return $o;
    }

    /** The outcome a support case of this type records. */
    public static function fromCaseType(string $type): ?self
    {
        return match ($type) {
            'return', 'return_exchange' => self::ReturnCase,
            'exchange' => self::ExchangeCase,
            'complaint' => self::ComplaintCase,
            'cancel_edit' => self::OrderChange,
            'delivery_followup' => self::OrderInquiry,
            default => null,
        };
    }

    /** The service outcome a handover category suggests; null for a sales handover or no category. */
    public static function fromHandoverCategory(?string $category): ?self
    {
        return $category === null ? null : (self::HANDOVER_MAP[$category] ?? null);
    }

    public function isLostSale(): bool
    {
        return in_array($this, [self::Price, self::SizeOut, self::Shipping, self::NoAnswer, self::Browsing, self::Other], true);
    }

    /** A service chat (the queue's `problem` kind, D-15), old or new key. */
    public function isService(): bool
    {
        return in_array($this, [self::OrderInquiry, self::ReturnCase, self::ExchangeCase, self::ComplaintCase, self::OrderChange, self::Service], true);
    }

    /** The support case type this outcome records (opened at the close when none is open), null for the rest. */
    public function caseType(): ?string
    {
        return match ($this) {
            self::ReturnCase => 'return',
            self::ExchangeCase => 'exchange',
            self::ComplaintCase => 'complaint',
            self::OrderChange => 'cancel_edit',
            default => null,
        };
    }

    /** Does the close send the thanks (and the rating) by default? Not to a customer who never replied, never to spam. */
    public function sendsClosing(): bool
    {
        return ! in_array($this, [self::NoAnswer, self::Spam], true);
    }

    /** The default `closing_skipped_reason` when this outcome sends nothing. */
    public function skipReason(): ?string
    {
        return match ($this) {
            self::NoAnswer => 'no_reply',
            self::Spam => 'spam',
            default => null,
        };
    }

    /** Spam goes straight back to the bot: no thanks, no return window (Q8). */
    public function endsWithBot(): bool
    {
        return $this === self::Spam;
    }

    public function label(): string
    {
        return __('labels.outcomes.'.$this->value);
    }
}
