<?php

namespace App\Confirmations\WhatsApp;

use App\Confirmations\ConfirmationPayload;
use App\Confirmations\ConfirmationSettings;
use App\Confirmations\Contracts\ConfirmationSender;
use App\Enums\MessageStatus;
use App\Enums\OrderConfirmationStatus;
use App\Enums\Platform;
use App\Inbox\CustomerResolver;
use App\Inbox\InboxIngestor;
use App\Inbox\Jobs\SendOutboundMessage;
use App\Inbox\OutboundService;
use App\Models\ChannelAccount;
use App\Models\Conversation;
use App\Models\CustomerIdentity;
use App\Models\Message;
use App\Models\Order;
use App\Models\OrderConfirmation;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * Sends the order confirmation / reminder template on WhatsApp (slice W2, spec 2026-10-16).
 * The template goes through OutboundService like any other message, so staff see it in the
 * customer's thread and can retry it. At most one confirmation and one reminder per row; a
 * delivery failure never throws, it marks the row (see ConfirmationMessageWatcher for failures
 * WhatsApp reports later).
 */
final class WhatsAppConfirmationSender implements ConfirmationSender
{
    public function __construct(
        private readonly ConfirmationSettings $settings,
        private readonly OutboundService $outbound,
        private readonly CustomerResolver $resolver,
        private readonly InboxIngestor $ingestor,
    ) {}

    public function send(OrderConfirmation $confirmation, string $kind = 'confirm'): void
    {
        $kind = $kind === 'reminder' ? 'reminder' : 'confirm';
        $claim = Cache::lock('order-confirmation-send:'.$confirmation->id, 60);

        if (! $claim->get()) {
            return;
        }

        try {
            $confirmation->refresh();

            if (! $this->due($confirmation, $kind)) {
                return;
            }

            $this->sendClaimed($confirmation, $kind);
        } finally {
            $claim->release();
        }
    }

    private function due(OrderConfirmation $c, string $kind): bool
    {
        if ($kind === 'reminder') {
            return $c->status->awaitingCustomer() && $c->reminded_at === null && $c->outbound_message_id !== null;
        }

        // A failed send may go again (e.g. once the template is approved); a sent one never does.
        return $c->status === OrderConfirmationStatus::Failed
            || ($c->status === OrderConfirmationStatus::Scheduled && $c->outbound_message_id === null);
    }

    private function sendClaimed(OrderConfirmation $c, string $kind): void
    {
        $order = $c->order;
        $account = $c->channelAccount;
        $waId = preg_replace('/\D+/', '', (string) $c->phone_e164) ?? '';

        if ($order === null) {
            $this->fail($c, $kind, 'order_missing');

            return;
        }

        if ($account === null || $account->platform !== Platform::WhatsApp || $account->status === 'disconnected') {
            $this->fail($c, $kind, 'no_whatsapp_account');

            return;
        }

        if ($waId === '') {
            $this->fail($c, $kind, 'no_phone');

            return;
        }

        try {
            $conversation = DB::transaction(fn () => $this->conversationFor($order, $account, $waId, (string) $c->phone_e164));

            $params = TemplateParams::forOrder($order);
            $template = [
                'name' => $this->settings->template($kind),
                'language' => $this->settings->templateLanguage(),
                'params' => array_values($params),
                'buttons' => array_map(fn (string $action) => ConfirmationPayload::make($c->id, $action), TemplateParams::BUTTONS),
            ];

            $message = $this->outbound->sendTemplate($conversation, TemplateParams::preview($kind, $params), $template);
        } catch (Throwable $e) {
            Log::warning('order_confirmation.send_failed', ['confirmation_id' => $c->id, 'kind' => $kind, 'exception' => $e::class]);
            $this->fail($c, $kind, SendOutboundMessage::errorCode($e));

            return;
        }

        // With a synchronous queue the send already ran; otherwise it is still queued and a later
        // failure reaches the row through ConfirmationMessageWatcher.
        $message = Message::query()->find($message->id) ?? $message;
        $failed = $message->status === MessageStatus::Failed;
        $link = ['conversation_id' => $conversation->id, 'customer_id' => $conversation->customer_id];

        if ($kind === 'reminder') {
            $c->forceFill($link + [
                'reminded_at' => now(),
                'reminder_message_id' => $message->id,
                'last_error' => $failed ? $this->error($message) : null,
            ])->save();

            return;
        }

        $c->forceFill($link + [
            'status' => $failed ? OrderConfirmationStatus::Failed : OrderConfirmationStatus::Sent,
            'sent_at' => $failed ? null : now(),
            'outbound_message_id' => $message->id,
            'last_error' => $failed ? $this->error($message) : null,
        ])->save();
    }

    /**
     * The customer's WhatsApp thread on this account. The number's identity is reused when it
     * exists; otherwise it joins the order's customer, unless that customer already chats from
     * another WhatsApp number (a send picks the customer's latest identity, so the number gets
     * its own customer instead). An existing conversation is used as it is (a resolved one is
     * not reopened: the customer's reply does that).
     */
    private function conversationFor(Order $order, ChannelAccount $account, string $waId, string $phone): Conversation
    {
        $identity = CustomerIdentity::query()->where('platform', Platform::WhatsApp)->where('external_id', $waId)->first()
            ?? $this->identityFor($order, $waId, $phone);

        $existing = Conversation::query()->where('customer_id', $identity->customer_id)
            ->where('channel_account_id', $account->id)->latest('id')->first();

        return $existing ?? $this->ingestor->openConversationFor($identity, $account);
    }

    private function identityFor(Order $order, string $waId, string $phone): CustomerIdentity
    {
        $customer = $order->customer;
        $name = trim((string) ($order->shipping_name ?: $customer?->name));

        $hasOtherNumber = $customer !== null && CustomerIdentity::query()
            ->where('customer_id', $customer->id)->where('platform', Platform::WhatsApp)->exists();

        if ($customer !== null && ! $hasOtherNumber) {
            try {
                return CustomerIdentity::create([
                    'customer_id' => $customer->id,
                    'platform' => Platform::WhatsApp,
                    'external_id' => $waId,
                    'display_name' => $name !== '' ? $name : $waId,
                ]);
            } catch (UniqueConstraintViolationException) {
                return CustomerIdentity::query()->where('platform', Platform::WhatsApp)->where('external_id', $waId)->firstOrFail();
            }
        }

        return $this->resolver->resolve(Platform::WhatsApp, $waId, $name, phone: $phone);
    }

    private function error(Message $message): string
    {
        return Str::limit((string) ($message->error ?: 'send_failed'), 250);
    }

    private function fail(OrderConfirmation $c, string $kind, string $error): void
    {
        if ($kind === 'reminder') {
            // The reminder is attempted once; the row stays with the customer for the no-response step.
            $c->forceFill(['reminded_at' => now(), 'last_error' => $error])->save();

            return;
        }

        $c->forceFill(['status' => OrderConfirmationStatus::Failed, 'last_error' => $error])->save();
    }
}
