<?php

namespace App\Inbox;

use App\Bot\HandoverSummary;
use App\Models\Conversation;
use App\Models\ConversationNote;
use App\Models\QueueEntry;
use Carbon\CarbonInterface;

/**
 * What the bot learned before the handover, as data (C 2.1, G3): the queue ticket's summary
 * (reason, category, topic, order number, flow lines) plus the handover note's products, sizes,
 * colours, governorate and last message. Only this episode's note (`$since` = the last end).
 */
final class HandoverDigest
{
    /** @return array{reason:?string, category:?string, topic:?string, order_number:?string, lines:list<array{label:?string,value:string}>, products:list<string>, sizes:list<string>, colors:list<string>, governorate:?string, last_message:?string}|null */
    public function for(Conversation $c, ?QueueEntry $entry, ?CarbonInterface $since): ?array
    {
        $summary = is_array($entry?->bot_summary) ? $entry->bot_summary : [];
        $note = ConversationNote::query()->where('conversation_id', $c->id)->whereNull('user_id')
            ->where('body', 'like', HandoverSummary::NOTE_HEADER.'%')
            ->when($since !== null, fn ($q) => $q->where('created_at', '>', $since))
            ->latest('id')->first(['id', 'body']);

        if ($summary === [] && $note === null) {
            return null;
        }

        [$fields, $bullets] = $note !== null ? self::parse((string) $note->body) : [[], []];
        $lines = array_values(array_filter(array_map(fn ($l) => self::line((string) $l), $summary['lines'] ?? [])));
        $str = fn ($v) => is_scalar($v) && trim((string) $v) !== '' ? trim((string) $v) : null;

        return [
            'reason' => isset($summary['reason']) ? HandoverSummary::reasonLabel((string) $summary['reason']) : ($fields[HandoverSummary::LABEL_REASON] ?? null),
            'category' => isset($summary['category']) ? HandoverSummary::categoryLabel((string) $summary['category']) : ($fields[HandoverSummary::LABEL_CATEGORY] ?? null),
            'topic' => $str($summary['topic'] ?? null) ?? $str($c->handover_topic),
            'order_number' => $str($summary['order_number'] ?? null),
            'lines' => $lines !== [] ? $lines : $bullets,
            'products' => self::list($fields[HandoverSummary::LABEL_PRODUCTS] ?? null),
            'sizes' => self::list($fields[HandoverSummary::LABEL_SIZES] ?? null),
            'colors' => self::list($fields[HandoverSummary::LABEL_COLORS] ?? null),
            'governorate' => $fields[HandoverSummary::LABEL_GOVERNORATE] ?? null,
            'last_message' => isset($fields[HandoverSummary::LABEL_LAST]) ? trim($fields[HandoverSummary::LABEL_LAST], ' «»') : null,
        ];
    }

    /** @return array{0: array<string,string>, 1: list<array{label:?string,value:string}>} labelled fields, then the flow's bullet lines */
    private static function parse(string $body): array
    {
        $fields = [];
        $bullets = [];

        foreach (array_slice(preg_split('/\R/u', $body) ?: [], 1) as $raw) {
            $raw = trim($raw);
            if ($raw === '') {
                continue;
            }
            if (str_starts_with($raw, '•')) {
                $bullets[] = self::line($raw);

                continue;
            }
            [$label, $value] = array_pad(explode(': ', $raw, 2), 2, null);
            if ($value !== null && ! isset($fields[$label])) {
                $fields[$label] = trim($value);
            }
        }

        return [$fields, array_values(array_filter($bullets))];
    }

    /** "• label: value" → {label, value}; a line without a label keeps its text as the value. */
    private static function line(string $raw): ?array
    {
        $text = trim(ltrim(trim($raw), '•'));
        if ($text === '') {
            return null;
        }
        [$label, $value] = array_pad(explode(': ', $text, 2), 2, null);

        return $value === null ? ['label' => null, 'value' => $text] : ['label' => trim($label), 'value' => trim($value)];
    }

    /** @return list<string> */
    private static function list(?string $value): array
    {
        return $value === null ? [] : array_values(array_filter(array_map('trim', preg_split('/[،,]/u', $value) ?: [])));
    }
}
