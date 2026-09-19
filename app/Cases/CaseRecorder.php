<?php

namespace App\Cases;

use App\Bot\Flow\Orders\OrderLookup;
use App\Bot\Flow\Orders\OrderSnapshot;
use App\Bot\Flows\ReturnPolicyChecker;
use App\Enums\UserRole;
use App\Events\ConversationUpdated;
use App\Inbox\UserNotifier;
use App\Models\Conversation;
use App\Models\ConversationNote;
use App\Models\Order;
use App\Models\SupportCase;
use App\Models\User;
use App\Support\SafeBroadcast;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Throwable;

/**
 * Turns a flow's collected data into a SupportCase (design §4): priority,
 * policy notes, a system note on the conversation, a `case.created`
 * notification to active supervisors/admins and a ConversationUpdated
 * broadcast. The conversation stays with the bot.
 */
class CaseRecorder
{
    private const HIGH_REASONS = ['defective', 'wrong_item', 'missing_item'];

    private const HIGH_COMPLAINTS = ['branch', 'delivery'];

    /** Flow-state bookkeeping that is not case data. */
    private const INTERNAL_KEYS = ['case_id', 'order_verify', 'verified_order_ids', 'order_lookup_contact', 'items_pending', 'order_choices'];

    public function __construct(
        private readonly ReturnPolicyChecker $policy,
        private readonly OrderLookup $orders,
        private readonly UserNotifier $notifier,
    ) {}

    public function record(Conversation $c, string $type, array $data): SupportCase
    {
        if (! in_array($type, SupportCase::TYPES, true)) {
            throw new InvalidArgumentException("Unknown case type [{$type}].");
        }

        $data = array_diff_key($data, array_flip(self::INTERNAL_KEYS));
        $order = is_numeric($data['order_id'] ?? null) ? Order::find((int) $data['order_id']) : null;

        if ($type === 'delivery_followup' && ($open = $this->openFollowUp($c, $order, $data)) !== null) {
            return $open;
        }

        $case = DB::transaction(function () use ($c, $type, $data, $order) {
            $case = SupportCase::create([
                'conversation_id' => $c->id,
                'customer_id' => $c->customer_id,
                'platform' => $c->platform->value,
                'type' => $type,
                'status' => 'new',
                'priority' => $this->priority($type, $data),
                'order_id' => $order?->id,
                'order_number' => filled($data['order_number'] ?? null) ? (string) $data['order_number'] : null,
                'data' => $data,
                'photo_attachment_ids' => $this->photoIds($data),
                'policy_notes' => $this->policyNotes($type, $data, $order),
            ]);

            $text = CaseSummary::text($case);
            $case->update(['summary' => $text]);

            ConversationNote::create([
                'conversation_id' => $c->id,
                'user_id' => null,
                'body' => $text,
                'mentions' => [],
            ]);

            // An exchange also gets a one-line note the team can act on (2026-09-19).
            if ($type === 'exchange') {
                ConversationNote::create([
                    'conversation_id' => $c->id,
                    'user_id' => null,
                    'body' => CaseSummary::exchangeNote($data),
                    'mentions' => [],
                ]);
            }

            return $case;
        });

        $this->notify($c, $case);
        SafeBroadcast::send(new ConversationUpdated($c));

        return $case;
    }

    /**
     * A re-check of the same order must not open another case, note and notification: the
     * conversation's non-closed follow-up for that order (by id, else by number) is reused.
     */
    private function openFollowUp(Conversation $c, ?Order $order, array $data): ?SupportCase
    {
        $number = filled($data['order_number'] ?? null) ? (string) $data['order_number'] : null;

        if ($order === null && $number === null) {
            return null;
        }

        return SupportCase::query()
            ->where('conversation_id', $c->id)
            ->where('type', 'delivery_followup')
            ->where('status', '!=', 'closed')
            ->when($order !== null,
                fn ($q) => $q->where('order_id', $order->id),
                fn ($q) => $q->whereNull('order_id')->where('order_number', $number))
            ->latest('id')
            ->first();
    }

    private function priority(string $type, array $data): string
    {
        $high = $type === 'delivery_followup'
            || in_array($data['reason'] ?? null, self::HIGH_REASONS, true)
            || in_array($data['complaint_type'] ?? null, self::HIGH_COMPLAINTS, true);

        return $high ? 'high' : 'medium';
    }

    /** @return list<int> image attachment ids from every photo field (the `legacy` marker has none) */
    private function photoIds(array $data): array
    {
        $ids = [];

        foreach ($data as $key => $value) {
            if (! is_string($key) || ! str_ends_with($key, '_photo') || ! is_array($value)) {
                continue;
            }

            foreach ($value as $id) {
                if (is_int($id) || (is_string($id) && ctype_digit($id))) {
                    $ids[] = (int) $id;
                }
            }
        }

        return array_values(array_unique($ids));
    }

    /** @return list<string> */
    private function policyNotes(string $type, array $data, ?Order $order): array
    {
        return match ($type) {
            'return_exchange', 'return', 'exchange' => $this->policy->notes($data),
            'cancel_edit' => $this->cancelWindowNotes($data, $order),
            default => [],
        };
    }

    /** @return list<string> only when the order step found the order */
    private function cancelWindowNotes(array $data, ?Order $order): array
    {
        $placed = $order?->placed_at ?? $order?->created_at;

        if (blank($data['order_number'] ?? null) || ($placed === null && blank($data['order_placed_at'] ?? null))) {
            return [];
        }

        try {
            $placedAt = $placed !== null ? CarbonImmutable::instance($placed) : CarbonImmutable::parse((string) $data['order_placed_at']);
        } catch (Throwable) {
            return [];
        }

        $snapshot = new OrderSnapshot((int) ($order?->id ?? 0), (string) $data['order_number'], $placedAt, 'shopify', (string) ($data['order_status_key'] ?? ''), null, null);
        $left = $this->orders->cancelWindowLeftMinutes($snapshot, CarbonImmutable::now());

        return [$left > 0 ? "باقي على مهلة الإلغاء/التعديل: {$left} دقيقة" : 'انتهت مهلة الإلغاء/التعديل'];
    }

    private function notify(Conversation $c, SupportCase $case): void
    {
        $excerpt = CaseSummary::excerpt($case);

        User::query()
            ->where('is_active', true)
            ->whereIn('role', [UserRole::Supervisor->value, UserRole::Admin->value])
            ->get()
            ->each(fn (User $u) => rescue(fn () => $this->notifier->notify($u, 'case.created', [
                'case_id' => $case->id,
                'conversation_id' => $c->id,
                'type' => $case->type,
                'excerpt' => $excerpt,
            ])));
    }
}
