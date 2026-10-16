<?php

namespace App\Confirmations\WhatsApp;

use App\Bot\ArabicNormalizer;
use App\Confirmations\ConfirmationPayload;
use App\Confirmations\Events\OrderConfirmationAnswered;
use App\Confirmations\Events\OrderConfirmationEditNote;
use App\Enums\MessageDirection;
use App\Enums\OrderConfirmationStatus;
use App\Enums\Platform;
use App\Enums\SenderType;
use App\Inbox\OutboundService;
use App\Models\Conversation;
use App\Models\CustomerIdentity;
use App\Models\Message;
use App\Models\OrderConfirmation;
use Illuminate\Support\Facades\Log;

/**
 * The customer's answer to an order confirmation (slice W2, spec 2026-10-16), run on every inbound
 * WhatsApp message before the bot (App\Inbox\InboundInterceptors). It takes:
 *   - a tap on the template's buttons (payload `oc:{id}:{action}`);
 *   - the free text that follows «تعديل», within EDIT_WINDOW_HOURS, as the edit note;
 *   - an exact typed «تمام» / «الغي» right after the template (nothing looser: anything else goes to
 *     the bot or staff untouched).
 * Returns true when it handled the message, so the bot stays out of it.
 */
final class ConfirmationReplyHandler
{
    public const EDIT_WINDOW_HOURS = 2;

    public const REPLY_CONFIRMED = 'شكراً ليكي! اتأكد الأوردر وهنبدأ نجهزه ونشحنه في أقرب وقت.';

    public const REPLY_CANCEL = 'تمام، بلغنا الفريق بطلب الإلغاء وهيتواصلوا معاكي';

    public const REPLY_EDIT = 'ابعتيلنا التعديل أو العنوان الجديد في رسالة';

    public const REPLY_EDIT_THANKS = 'شكراً ليكي، وصلنا التعديل والفريق هيراجعه ويأكد معاكي.';

    public const REPLY_ALREADY = 'اتسجل ردك قبل كده، شكراً ليكي.';

    /** Exact typed answers (after ArabicNormalizer and punctuation removal). */
    private const TYPED = [
        'تمام' => 'confirm', 'اكد' => 'confirm', 'موافق' => 'confirm', 'confirm' => 'confirm',
        'الغي' => 'cancel', 'الغاء' => 'cancel', 'cancel' => 'cancel',
    ];

    private const STATUS_FOR = [
        'confirm' => OrderConfirmationStatus::Confirmed,
        'edit' => OrderConfirmationStatus::EditRequested,
        'cancel' => OrderConfirmationStatus::CancelRequested,
    ];

    public function __construct(private readonly OutboundService $outbound, private readonly ArabicNormalizer $normalizer) {}

    /**
     * Read-only: whether handle() will take this message. The inbox asks before it records the
     * message (InboundInterceptors), so a handled answer leaves no unread count, no queue ticket and
     * no reopened thread behind.
     *
     * @param  array<int, mixed>  $attachments
     */
    public function claims(?string $payload, string $body, array $attachments, Conversation $conversation): bool
    {
        return $this->decide($payload, $body, $attachments, $conversation) !== null;
    }

    public function handle(Message $message, Conversation $conversation): bool
    {
        if ($message->direction !== MessageDirection::In) {
            return false;
        }

        $decision = $this->decide($message->payload, (string) $message->body, (array) ($message->attachments ?? []), $conversation);

        return match ($decision['type'] ?? null) {
            'foreign' => $this->ignoreForeign($decision['id'], $conversation),
            'already' => $this->already($conversation),
            'answer' => $this->answer($decision['confirmation'], $decision['action'], $conversation)
                || ($this->sameAnswer($decision['confirmation']->refresh()->status, $decision['action']) && $this->already($conversation)),
            'edit_note' => $this->saveEditNote($decision['confirmation'], $decision['text'], $conversation),
            default => false,
        };
    }

    /**
     * What this message is for the confirmation flow, or null when it is not ours.
     *
     * @param  array<int, mixed>  $attachments
     * @return array<string, mixed>|null
     */
    private function decide(?string $payload, string $body, array $attachments, Conversation $conversation): ?array
    {
        if ($conversation->platform !== Platform::WhatsApp) {
            return null;
        }

        if (filled($payload)) {
            $parsed = ConfirmationPayload::parse($payload);

            return $parsed === null ? null : $this->decideTap($parsed['id'], $parsed['action'], $conversation);
        }

        if (($note = $this->pendingEditNote($body, $conversation)) !== null) {
            return ['type' => 'edit_note', 'confirmation' => $note, 'text' => trim($body)];
        }

        return $this->decideTyped($body, $attachments, $conversation);
    }

    /** @return array<string, mixed>|null */
    private function decideTap(int $id, string $action, Conversation $conversation): ?array
    {
        $confirmation = OrderConfirmation::query()->find($id);

        if ($confirmation === null || ! $this->belongsTo($confirmation, $conversation)) {
            return ['type' => 'foreign', 'id' => $id];
        }

        if ($this->sameAnswer($confirmation->status, $action)) {
            return ['type' => 'already'];
        }

        // Not answerable from here (e.g. confirm after a cancel request): staff read it.
        return in_array($confirmation->status->value, $this->answerableFrom($action), true)
            ? ['type' => 'answer', 'confirmation' => $confirmation, 'action' => $action]
            : null;
    }

    private function ignoreForeign(int $id, Conversation $conversation): bool
    {
        // A forwarded template, or a payload that names someone else's order: never acted on.
        Log::warning('order_confirmation.foreign_payload', ['confirmation_id' => $id, 'conversation_id' => $conversation->id]);

        return true;
    }

    private function already(Conversation $conversation): bool
    {
        $this->reply($conversation, self::REPLY_ALREADY);

        return true;
    }

    /** The tap repeats what is already recorded (by her, or by staff after a call). */
    private function sameAnswer(OrderConfirmationStatus $status, string $action): bool
    {
        return $status === self::STATUS_FOR[$action]
            || ($action === 'confirm' && $status === OrderConfirmationStatus::CalledConfirmed)
            || ($action === 'cancel' && $status === OrderConfirmationStatus::CalledCancelled);
    }

    /**
     * Statuses an answer may come from. Confirm only while waiting for her; a cancel or an edit is
     * still taken after a confirm, an edit request, a failed call or a failed send (she may change
     * her mind). Anything else (e.g. confirm after a cancel request) is left to staff.
     *
     * @return list<string>
     */
    private function answerableFrom(string $action): array
    {
        $waiting = [OrderConfirmationStatus::Sent, OrderConfirmationStatus::NoResponse];
        $later = [OrderConfirmationStatus::Confirmed, OrderConfirmationStatus::EditRequested, OrderConfirmationStatus::Unreachable,
            OrderConfirmationStatus::Failed, OrderConfirmationStatus::CalledConfirmed];

        return array_map(fn (OrderConfirmationStatus $s) => $s->value, $action === 'confirm' ? $waiting : [...$waiting, ...$later]);
    }

    /**
     * Moves the confirmation to the answer's status (once: a concurrent second tap loses the
     * conditional update), tells W3 and answers the customer. False when the answer is not taken.
     */
    private function answer(OrderConfirmation $confirmation, string $action, Conversation $conversation): bool
    {
        $values = [
            'status' => self::STATUS_FOR[$action]->value,
            'answer' => $action,
            'answered_at' => now(),
            'conversation_id' => $confirmation->conversation_id ?? $conversation->id,
            'updated_at' => now(),
        ];

        if ($action === 'edit') {
            $values['edit_note_requested_at'] = now();
        }

        $won = OrderConfirmation::query()->whereKey($confirmation->id)
            ->whereIn('status', $this->answerableFrom($action))
            ->where('status', '!=', self::STATUS_FOR[$action]->value)
            ->update($values);

        if ($won !== 1) {
            return false;
        }

        $confirmation->refresh();

        OrderConfirmationAnswered::dispatch($confirmation, $action);

        $this->reply($conversation, match ($action) {
            'confirm' => self::REPLY_CONFIRMED,
            'edit' => self::REPLY_EDIT,
            'cancel' => self::REPLY_CANCEL,
        });

        return true;
    }

    /** The first free text after «تعديل» (within the window) is the edit itself. */
    private function pendingEditNote(string $body, Conversation $conversation): ?OrderConfirmation
    {
        if (trim($body) === '') {
            return null;
        }

        return $this->ofConversation($conversation)
            ->where('status', OrderConfirmationStatus::EditRequested->value)
            ->whereNull('edit_note')
            ->where('edit_note_requested_at', '>=', now()->subHours(self::EDIT_WINDOW_HOURS))
            ->latest('id')
            ->first();
    }

    private function saveEditNote(OrderConfirmation $confirmation, string $text, Conversation $conversation): bool
    {
        $saved = OrderConfirmation::query()->whereKey($confirmation->id)->whereNull('edit_note')
            ->update(['edit_note' => mb_substr($text, 0, 2000), 'updated_at' => now()]);

        if ($saved !== 1) {
            return false;
        }

        OrderConfirmationEditNote::dispatch($confirmation->refresh());

        $this->reply($conversation, self::REPLY_EDIT_THANKS);

        return true;
    }

    /**
     * «تمام» / «الغي» typed right after the template, while it still waits for her.
     *
     * @param  array<int, mixed>  $attachments
     * @return array<string, mixed>|null
     */
    private function decideTyped(string $body, array $attachments, Conversation $conversation): ?array
    {
        if ($attachments !== []) {
            return null;
        }

        $words = trim(preg_replace('/\s+/u', ' ', preg_replace('/[^\p{L}\p{N}\s]/u', '', $this->normalizer->normalize($body)) ?? '') ?? '');
        $action = self::TYPED[$words] ?? null;

        if ($action === null) {
            return null;
        }

        $confirmation = $this->ofConversation($conversation)
            ->whereIn('status', [OrderConfirmationStatus::Sent->value, OrderConfirmationStatus::NoResponse->value])
            ->latest('id')
            ->first();

        if ($confirmation === null) {
            return null;
        }

        // Only an answer to the template itself: once anyone wrote after it, «تمام» may mean anything.
        $lastOutbound = $conversation->messages()
            ->where('direction', MessageDirection::Out->value)
            ->whereIn('sender_type', [SenderType::Bot->value, SenderType::User->value])
            ->max('id');

        if ($lastOutbound === null || ! in_array((int) $lastOutbound, array_filter([$confirmation->outbound_message_id, $confirmation->reminder_message_id]), true)) {
            return null;
        }

        return ['type' => 'answer', 'confirmation' => $confirmation, 'action' => $action];
    }

    private function ofConversation(Conversation $conversation)
    {
        return OrderConfirmation::query()->where(fn ($q) => $q
            ->where('conversation_id', $conversation->id)
            ->orWhere('customer_id', $conversation->customer_id));
    }

    /** The tap came from the thread or the customer the template went to, or from its number. */
    private function belongsTo(OrderConfirmation $confirmation, Conversation $conversation): bool
    {
        if ($confirmation->conversation_id === $conversation->id
            || ($confirmation->customer_id !== null && $confirmation->customer_id === $conversation->customer_id)) {
            return true;
        }

        $waId = preg_replace('/\D+/', '', (string) $confirmation->phone_e164);

        return $waId !== '' && CustomerIdentity::query()
            ->where('customer_id', $conversation->customer_id)
            ->where('platform', Platform::WhatsApp)
            ->where('external_id', $waId)
            ->exists();
    }

    private function reply(Conversation $conversation, string $text): void
    {
        // A closed window or a send problem never undoes the recorded answer.
        rescue(fn () => $this->outbound->sendBot($conversation, $text), function ($e) use ($conversation) {
            Log::warning('order_confirmation.reply_failed', ['conversation_id' => $conversation->id, 'exception' => $e::class]);
        }, report: false);
    }
}
