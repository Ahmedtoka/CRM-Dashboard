<?php

namespace App\Inbox\Jobs;

use App\Analytics\ActivityLogger;
use App\Analytics\LatencyRecorder;
use App\Channels\ChannelHealth;
use App\Channels\ChannelRegistry;
use App\Channels\Data\SendResult;
use App\Enums\ActorType;
use App\Enums\MessageStatus;
use App\Enums\SenderType;
use App\Events\MessageUpdated;
use App\Models\CustomerIdentity;
use App\Models\Message;
use App\Support\SafeBroadcast;
use Carbon\CarbonImmutable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Str;
use Throwable;

class SendOutboundMessage implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 3;

    /** @var array<int, int> */
    public array $backoff = [2, 10];

    /**
     * @param  array{tag?: string, template?: array}  $options
     */
    public function __construct(public readonly int $messageId, public readonly array $options = [])
    {
        $this->onQueue('outbound');
    }

    public function handle(ChannelRegistry $registry, ActivityLogger $logger, LatencyRecorder $latency): void
    {
        $message = Message::with(['conversation.channelAccount', 'user'])->find($this->messageId);

        if ($message === null || $message->status !== MessageStatus::Queued) {
            return;
        }

        $conversation = $message->conversation;

        $identity = CustomerIdentity::where('customer_id', $conversation->customer_id)
            ->where('platform', $conversation->platform)
            ->latest('id')
            ->first();

        if ($identity === null) {
            $result = SendResult::fail("Customer has no {$conversation->platform->label()} identity.");
        } else {
            // Latency window (spec §11.3): moderator pressed send (message created_at) to
            // the moment we are about to call the platform's API, recorded before the call
            // itself so a slow/failed provider doesn't skew "did we reach it in time".
            $latency->outbound($message, CarbonImmutable::now());

            try {
                $result = $registry->adapter($conversation->platform)->sendText(
                    $conversation->channelAccount,
                    $identity,
                    (string) $message->body,
                    $this->options,
                );
            } catch (Throwable $e) {
                $result = SendResult::fail($e->getMessage(), retryable: true);
            }
        }

        if ($result->success) {
            $message->forceFill([
                'status' => MessageStatus::Sent,
                'external_id' => $result->externalId,
                'sent_at' => now(),
                'error' => null,
            ])->save();

            SafeBroadcast::send(new MessageUpdated($message));

            return;
        }

        // Expired/revoked credentials break the whole channel: flag it and alert admins.
        app(ChannelHealth::class)->recordSendFailure($conversation->channelAccount, $result);

        if ($result->retryable && $this->attempts() < $this->tries) {
            $this->release($this->backoff[$this->attempts() - 1] ?? end($this->backoff));

            return;
        }

        $this->markFailed($message, $result->error ?? 'Unknown platform error', $logger);
    }

    public function failed(?Throwable $e): void
    {
        $message = Message::find($this->messageId);

        if ($message !== null && $message->status === MessageStatus::Queued) {
            $this->markFailed($message, $e?->getMessage() ?? 'Sending failed', app(ActivityLogger::class));
        }
    }

    private function markFailed(Message $message, string $error, ActivityLogger $logger): void
    {
        $message->forceFill([
            'status' => MessageStatus::Failed,
            'error' => Str::limit($error, 250),
        ])->save();

        $actor = match (true) {
            $message->user_id !== null => ActorType::User,
            $message->sender_type === SenderType::Bot => ActorType::Bot,
            default => ActorType::System,
        };

        $logger->log($actor, $message->user, ActivityLogger::MESSAGE_FAILED, $message, $message->conversation, ['error' => $error]);

        SafeBroadcast::send(new MessageUpdated($message));
    }
}
