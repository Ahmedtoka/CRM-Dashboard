<?php

namespace App\Inbox\Outcomes;

use App\Bot\HandoverSummary;
use App\Enums\MessageDirection;
use App\Enums\SenderType;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\QueueEntry;
use App\Models\SupportCase;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * What the «خلصت» sheet shows preselected, and why (docs/round-2026-10-24/finish-api.md §2):
 * read from the chat's facts in this episode, the latest fact winning —
 *  1. a support case opened in the episode → the case outcome of its type;
 *  2. a real order placed in the episode → ordered;
 *  3. a service handover with no case yet → the service outcome of its category (the close opens the case);
 *  4. we replied and she never wrote again → no_answer;
 *  5. we replied and she answered → browsing («استفسرت وهترجع»), unless the handover was a service one;
 *  nothing when nobody replied to her (the moderator picks).
 *
 * Pure reads; the moderator confirms or changes it, and the recorder stores both.
 */
final class OutcomeSuggestion
{
    public function __construct(private readonly OutcomeRecorder $outcomes) {}

    /**
     * @return array{outcome: string, why: string, params: array<string, mixed>, case_id: ?int, order_id: ?int}|null
     */
    public function for(Conversation $c, ?QueueEntry $entry = null, ?CarbonInterface $since = null, bool $sinceKnown = false): ?array
    {
        $since = $sinceKnown ? $since : $this->outcomes->episode($c)['since'];

        $case = $this->episodeCase($c, $since);
        $order = $this->outcomes->episodeOrder($c, $since);

        if ($case !== null && ($order === null || $case->created_at?->greaterThanOrEqualTo($order->created_at))) {
            $outcome = Outcome::fromCaseType((string) $case->type) ?? Outcome::OrderInquiry;
            $open = in_array($case->status, SupportCase::OPEN_STATUSES, true);

            return self::row($outcome, $open ? 'case_open' : 'case_resolved', ['case_id' => $case->id, 'type' => $case->type], caseId: $case->id);
        }

        if ($order !== null) {
            return self::row(Outcome::Ordered, 'order_placed', ['number' => $order->order_number, 'created_at' => $order->created_at?->toIso8601String()], orderId: $order->id);
        }

        $category = $this->category($c, $entry, $since);
        $service = Outcome::fromHandoverCategory($category);

        if ($service !== null) {
            return self::row($service, 'handover', ['category' => $category, 'category_label' => HandoverSummary::categoryLabel((string) $category)]);
        }

        $talk = $this->talk($c, $entry, $since);

        if ($talk['first_reply_at'] === null) {
            return null;
        }

        if (! $talk['customer_after_reply']) {
            $minutes = $talk['last_reply_at'] !== null ? max(0, (int) $talk['last_reply_at']->diffInMinutes(now())) : 0;

            return self::row(Outcome::NoAnswer, 'no_reply', ['minutes' => $minutes]);
        }

        return self::row(Outcome::Browsing, 'answered', []);
    }

    /** The latest support case opened in this episode (the chat's own cases). */
    private function episodeCase(Conversation $c, ?CarbonInterface $since): ?SupportCase
    {
        return SupportCase::query()->where('conversation_id', $c->id)
            ->when($since !== null, fn ($q) => $q->where('created_at', '>', $since))
            ->latest('id')->first(['id', 'type', 'status', 'created_at']);
    }

    private function category(Conversation $c, ?QueueEntry $entry, ?CarbonInterface $since): ?string
    {
        $category = is_array($entry?->bot_summary) ? ($entry->bot_summary['category'] ?? null) : null;

        if ($category === null && $c->handover_at !== null && ($since === null || $c->handover_at->greaterThan($since))) {
            $category = $c->handover_category;
        }

        return is_string($category) && $category !== '' ? $category : null;
    }

    /**
     * Our replies and her real messages in this window (the entry's delivery, else the episode start).
     *
     * @return array{first_reply_at: ?CarbonInterface, last_reply_at: ?CarbonInterface, customer_after_reply: bool}
     */
    private function talk(Conversation $c, ?QueueEntry $entry, ?CarbonInterface $since): array
    {
        $from = $entry?->delivered_at ?? $since;
        $base = Message::query()->where('conversation_id', $c->id)->where('is_spam', false)
            ->when($from !== null, fn ($q) => $q->where('created_at', '>=', $from));

        $replies = (clone $base)->where('direction', MessageDirection::Out->value)->where('sender_type', SenderType::User->value)
            ->selectRaw('MIN(created_at) as first_at, MAX(created_at) as last_at')->first();
        $firstAt = $replies?->first_at !== null ? CarbonImmutable::parse($replies->first_at, (string) config('app.timezone')) : null;
        $lastAt = $replies?->last_at !== null ? CarbonImmutable::parse($replies->last_at, (string) config('app.timezone')) : null;

        if ($firstAt === null) {
            return ['first_reply_at' => null, 'last_reply_at' => null, 'customer_after_reply' => false];
        }

        $after = (clone $base)->where('direction', MessageDirection::In->value)->where('sender_type', SenderType::Customer->value)
            ->where('created_at', '>', $firstAt)->orderBy('id')->limit(50)->get();
        $real = $after->first(fn (Message $m) => ! $this->outcomes->isAckOrRating($m));

        return ['first_reply_at' => $firstAt, 'last_reply_at' => $lastAt, 'customer_after_reply' => $real !== null];
    }

    /** @return array{outcome: string, why: string, params: array<string, mixed>, case_id: ?int, order_id: ?int} */
    private static function row(Outcome $outcome, string $why, array $params, ?int $caseId = null, ?int $orderId = null): array
    {
        return ['outcome' => $outcome->value, 'why' => $why, 'params' => $params, 'case_id' => $caseId, 'order_id' => $orderId];
    }
}
