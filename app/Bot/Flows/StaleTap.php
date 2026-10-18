<?php

namespace App\Bot\Flows;

use App\Bot\Flows\Steps\OrderItemsStep;
use App\Bot\Flows\Steps\OrderStep;
use App\Enums\SenderType;
use App\Inbox\OutboundService;
use App\Inbox\WindowClosedException;
use App\Models\BotFlow;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\OrderItem;
use App\Models\SupportCase;
use Illuminate\Support\Facades\Log;

/**
 * A tap on a button of a request that is already over (staff complaints, round b1, 2026-10-19):
 * «رجّع القطعة دي» on an old picture card after «لا تمام» or a close. Never left to a model that
 * only sees «رجّع القطعة دي» — the tap is validated against the message that issued it (it must be
 * a button the bot sent in THIS chat: its issued-at proves she was shown that order after proving
 * it is hers) and answered at once:
 *
 *   - a piece of an order picker (`item:<id>`) → the piece is already in an open request: its
 *     number; otherwise the request restarts for that piece, the order not asked again
 *     («الطلب اللي فات اتقفل، فهنبدأ طلب جديد للقطعة دي»);
 *   - any other old button → «الزرار ده من طلب قديم خلص» above the main menu (the current options).
 */
class StaleTap
{
    /** Request types whose cases list their pieces (`data.selected_items`). */
    private const PIECE_TYPES = ['return', 'exchange', 'return_exchange'];

    public function __construct(
        private readonly FlowEngine $flows,
        private readonly FlowPrompter $prompter,
        private readonly OutboundService $outbound,
    ) {}

    /** @return bool whether the tap was answered (false: not a step button) */
    public function answer(Conversation $c, string $payload): bool
    {
        if (! str_starts_with($payload, 'step:')) {
            return false;
        }

        [$flowKey, $stepKey, $value] = array_pad(explode(':', substr($payload, 5), 3), 3, '');
        $issued = $this->issuedIn($c, $payload);

        if ($issued !== null && preg_match('/^item:(\d+)$/', $value, $m) === 1 && $this->restartForPiece($c, $flowKey, $stepKey, (int) $m[1])) {
            return true;
        }

        Log::info('bot.stale_tap', ['conversation_id' => $c->id, 'payload' => $payload, 'issued_message_id' => $issued?->id]);
        FlowState::clear($c);
        $this->flows->start($c, ConversationRouter::MAIN_MENU, lead: $this->script('stale_tap_closed'));

        return true;
    }

    /** The bot message of this chat that carried the button (its issued-at), or null: never offered here. */
    public function issuedIn(Conversation $c, string $payload): ?Message
    {
        $needle = '%"'.str_replace(['\\', '%'], ['\\\\', '\\%'], $payload).'"%';

        return Message::query()->where('conversation_id', $c->id)->where('sender_type', SenderType::Bot->value)
            ->where(fn ($q) => $q->where('buttons', 'like', $needle)->orWhere('cards', 'like', $needle))
            ->latest('id')->first();
    }

    private function restartForPiece(Conversation $c, string $flowKey, string $stepKey, int $itemId): bool
    {
        $definition = BotFlow::active($flowKey)?->definition;
        $step = $definition['steps'][$stepKey] ?? null;
        $item = OrderItem::query()->with('order')->find($itemId);

        if (! is_array($step) || ($step['type'] ?? null) !== 'order_items' || OrderItemsStep::isPlain($step) || $item?->order === null) {
            return false;
        }

        if (($case = $this->openCaseFor($c, $item)) !== null) {
            $title = trim($item->title);
            $this->send($c, str_replace(['{title}', '{case_id}'], [$title, (string) $case->id], $this->script('stale_piece_recorded')), [FlowPrompter::MAIN_MENU_BUTTON]);
            FlowState::clear($c);

            return true;
        }

        $prefill = app(OrderStep::class)->provenData($item->order) + array_filter([
            'request_kind' => $this->kindLeadingTo((array) $definition['steps'], $stepKey),
            'item_ids' => [$item->id],
        ]);

        $this->send($c, $this->script('stale_piece_restart'));
        $this->flows->start($c, $flowKey, $prefill);

        return true;
    }

    /** The open request of this chat (last day) that already has this piece. */
    private function openCaseFor(Conversation $c, OrderItem $item): ?SupportCase
    {
        return SupportCase::query()->where('conversation_id', $c->id)->whereIn('type', self::PIECE_TYPES)
            ->whereIn('status', SupportCase::OPEN_STATUSES)->where('created_at', '>=', now()->subDay())
            ->latest('id')->get()
            ->first(fn (SupportCase $case) => collect((array) ($case->data['selected_items'] ?? []))
                ->contains(fn ($row) => is_array($row) && (int) ($row['line_item_id'] ?? 0) === (int) $item->id));
    }

    /** «إرجاع» leads to `return_items`: the kind the picker was asked under, so it is not asked again. */
    private function kindLeadingTo(array $steps, string $stepKey): ?string
    {
        foreach ($steps as $step) {
            if (($step['type'] ?? null) !== 'choice' || ($step['field'] ?? null) !== 'request_kind') {
                continue;
            }

            foreach ((array) ($step['options'] ?? []) as $option) {
                if (($option['next'] ?? null) === $stepKey && in_array($option['value'] ?? null, ['return', 'exchange', 'mixed'], true)) {
                    return (string) $option['value'];
                }
            }
        }

        return null;
    }

    private function script(string $key): string
    {
        return $this->prompter->script($key) ?? (string) (FlowScripts::all()[$key]['body'] ?? '');
    }

    /** @param  list<array{title:string, payload:string}>  $buttons */
    private function send(Conversation $c, string $text, array $buttons = []): void
    {
        try {
            $this->outbound->sendBot($c, $text, 0, true, $buttons);
        } catch (WindowClosedException) {
            Log::info('bot.stale_tap_window_closed', ['conversation_id' => $c->id]);
        }
    }
}
