<?php

namespace App\Confirmations\FollowUp;

use App\Cases\CaseRecorder;
use App\Confirmations\Events\OrderConfirmationAnswered;
use App\Confirmations\Events\OrderConfirmationEditNote;
use App\Confirmations\Events\OrderConfirmationNoResponse;
use App\Confirmations\TagSync;
use App\Enums\OrderConfirmationStatus;
use App\Inbox\UserNotifier;
use App\Models\ConversationNote;
use App\Models\OrderConfirmation;
use App\Models\SupportCase;
use Illuminate\Events\Dispatcher;

/**
 * What happens after a customer answers or stays silent (spec 2026-10-16, slice W3):
 * the Shopify tag, and for cancel/edit a `cancel_edit` case for staff. CaseRecorder
 * sends its `case.created` bell to active supervisors and admins; a confirmation with
 * no conversation cannot hold a case, so it gets an `order_confirmation.*_request`
 * bell instead. Listeners never throw back into the WhatsApp reply handler.
 */
final class ConfirmationFollowUp
{
    public function __construct(
        private readonly TagSync $tags,
        private readonly CaseRecorder $cases,
        private readonly UserNotifier $notifier,
    ) {}

    public function subscribe(Dispatcher $events): void
    {
        $events->listen(OrderConfirmationAnswered::class, [self::class, 'onAnswered']);
        $events->listen(OrderConfirmationEditNote::class, [self::class, 'onEditNote']);
        $events->listen(OrderConfirmationNoResponse::class, [self::class, 'onNoResponse']);
    }

    public function onAnswered(OrderConfirmationAnswered $event): void
    {
        $status = match ($event->answer) {
            'confirm' => OrderConfirmationStatus::Confirmed,
            'edit' => OrderConfirmationStatus::EditRequested,
            'cancel' => OrderConfirmationStatus::CancelRequested,
            default => null,
        };
        $confirmation = $event->confirmation->fresh();

        if ($status === null || $confirmation === null) {
            return;
        }

        $this->tags->push($confirmation, $status->shopifyTag());

        if ($event->answer !== 'confirm') {
            rescue(fn () => $this->openCase($confirmation->fresh(), $event->answer));
        }
    }

    public function onEditNote(OrderConfirmationEditNote $event): void
    {
        $confirmation = $event->confirmation->fresh();
        $note = trim((string) $confirmation?->edit_note);
        $case = $confirmation?->supportCase;

        // No case yet: openCase() copies edit_note from the row when it opens one.
        if ($note === '' || $case === null || ($case->data['edit_note'] ?? null) === $note) {
            return;
        }

        rescue(function () use ($case, $note) {
            $case->update(['data' => array_merge($case->data ?? [], ['edit_note' => $note])]);
            $this->note($case, __('cases.confirmation.edit_note', ['note' => $note], 'ar'));
        });
    }

    public function onNoResponse(OrderConfirmationNoResponse $event): void
    {
        if (($confirmation = $event->confirmation->fresh()) !== null) {
            $this->tags->push($confirmation, OrderConfirmationStatus::NoResponse->shopifyTag());
        }
    }

    /** @param  'cancel'|'edit'  $request */
    private function openCase(OrderConfirmation $confirmation, string $request): void
    {
        $open = $confirmation->supportCase;

        // A second tap (or cancel after edit) reuses the open case instead of opening another one.
        if ($open !== null && $open->status !== 'closed') {
            if (($open->data['request'] ?? null) !== $request) {
                $open->update(['data' => array_merge($open->data ?? [], ['request' => $request])]);
                $this->note($open, __('cases.confirmation.request_changed', [
                    'request' => __("cases.confirmation.requests.{$request}", [], 'ar'),
                ], 'ar'));
            }

            return;
        }

        $order = $confirmation->order;
        $conversation = $confirmation->conversation ?? $order?->conversation;
        $data = array_filter([
            'request' => $request,
            'order_id' => $order?->id ?? $confirmation->order_id,
            'order_number' => $order?->order_number ?? $order?->shopify_order_name,
            'source' => 'whatsapp_confirmation',
            'confirmation_id' => $confirmation->id,
            'edit_note' => filled($confirmation->edit_note) ? trim($confirmation->edit_note) : null,
        ], fn ($v) => $v !== null && $v !== '');

        if ($conversation === null) {
            $this->notifier->notifySupervisors("order_confirmation.{$request}_request", $data + [
                'excerpt' => __("cases.confirmation.requests.{$request}", [], 'ar').($data['order_number'] ?? null ? ' · '.$data['order_number'] : ''),
            ]);

            return;
        }

        $case = $this->cases->record($conversation, 'cancel_edit', $data);
        $confirmation->update(['case_id' => $case->id]);
    }

    private function note(SupportCase $case, string $body): void
    {
        ConversationNote::create([
            'conversation_id' => $case->conversation_id,
            'user_id' => null,
            'body' => $body,
            'mentions' => [],
        ]);
    }
}
