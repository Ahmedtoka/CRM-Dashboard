<?php

namespace App\Bot\Router;

use App\Models\BotFlow;
use App\Models\Conversation;
use App\Models\SupportCase;

/**
 * «طلبك متسجل» (bot-hybrid.md R5, owner B6): a request is already recorded only when it is the
 * same type, for the same order, and — when both name pieces — for overlapping pieces, opened in
 * this conversation in the last CASE_EXISTS_HOURS. A second return for a different order (or for
 * other pieces of the same order) is a new request, not a duplicate.
 */
final class CaseLock
{
    public const CASE_EXISTS_HOURS = 24;

    /** The open case a new request of $flowKey for $orderRef would repeat, or null. */
    public static function forFlow(Conversation $c, string $flowKey, ?string $orderRef): ?SupportCase
    {
        $orderRef = $orderRef !== null ? ltrim(trim($orderRef), '#') : null;

        if ($orderRef === null || $orderRef === '') {
            return null; // which order is not known yet: the flow asks, and RecordCaseStep checks again
        }

        $types = self::caseTypes($flowKey);

        return $types === [] ? null : self::open($c, $types)
            ->filter(fn (SupportCase $case) => ltrim((string) $case->order_number, '#') === $orderRef)
            ->first();
    }

    /**
     * At record time: an open case of the same type for the same order whose pieces overlap the
     * ones picked now (no pieces on either side counts as the same request).
     *
     * @param  array<string, mixed>  $data  the flow data about to be recorded
     */
    public static function duplicate(Conversation $c, string $type, array $data): ?SupportCase
    {
        $orderId = is_numeric($data['order_id'] ?? null) ? (int) $data['order_id'] : null;
        $orderNumber = filled($data['order_number'] ?? null) ? ltrim((string) $data['order_number'], '#') : null;

        if ($orderId === null && $orderNumber === null) {
            return null;
        }

        $mine = self::lineIds($data);

        return self::open($c, [$type])->first(function (SupportCase $case) use ($orderId, $orderNumber, $mine) {
            $sameOrder = ($orderId !== null && (int) $case->order_id === $orderId)
                || ($orderNumber !== null && ltrim((string) $case->order_number, '#') === $orderNumber);

            if (! $sameOrder) {
                return false;
            }

            $theirs = self::lineIds(is_array($case->data) ? $case->data : []);

            return $mine === [] || $theirs === [] || array_intersect($mine, $theirs) !== [];
        });
    }

    /** @return list<string> the case types a flow records: its record_case steps, and a status step's follow-up */
    public static function caseTypes(string $flowKey): array
    {
        $types = [];

        foreach ((array) (BotFlow::active($flowKey)?->definition['steps'] ?? []) as $step) {
            if (($step['type'] ?? null) === 'record_case') {
                $types[] = (string) ($step['case_type'] ?? '');
            } elseif (($step['type'] ?? null) === 'status') {
                $types[] = 'delivery_followup';
            }
        }

        return array_values(array_unique(array_filter($types)));
    }

    /** @param  list<string>  $types */
    private static function open(Conversation $c, array $types)
    {
        return SupportCase::query()
            ->where('conversation_id', $c->id)
            ->whereIn('type', $types)
            ->whereIn('status', SupportCase::OPEN_STATUSES)
            ->where('created_at', '>=', now()->subHours(self::CASE_EXISTS_HOURS))
            ->latest('id')
            ->get();
    }

    /** @return list<int> */
    private static function lineIds(array $data): array
    {
        return array_values(array_filter(array_map(
            fn ($r) => is_array($r) && is_numeric($r['line_item_id'] ?? null) ? (int) $r['line_item_id'] : null,
            (array) ($data['selected_items'] ?? []),
        )));
    }
}
