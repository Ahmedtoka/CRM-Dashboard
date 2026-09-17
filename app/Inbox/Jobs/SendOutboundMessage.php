<?php

namespace App\Inbox\Jobs;

use App\Analytics\ActivityLogger;
use App\Analytics\LatencyRecorder;
use App\Channels\ChannelHealth;
use App\Channels\ChannelRegistry;
use App\Channels\Data\SendResult;
use App\Enums\ActorType;
use App\Enums\Handler;
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
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
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
     * Realistic worst case for a WhatsApp voice-note attachment send: ffmpeg
     * transcode (15s cap) + the media upload (30s, single try) + the media
     * /messages send (10s timeout, up to 2 tries ≈ 20s) + an optional caption
     * follow-up (10s, single try) ≈ 75s. The job's own 75s timeout sits below
     * the crm-outbound supervisor worker's --timeout=80 (itself under the redis
     * connection's 90s retry_after), so the job always stops itself before the
     * worker would kill it.
     */
    public int $timeout = 75;

    /**
     * A send that hits the timeout is marked failed (failed() runs) rather than
     * retried: the provider may already have delivered it, and a blind retry
     * could send it twice. The moderator can retry from the failed bubble.
     */
    public bool $failOnTimeout = true;

    /**
     * @param  array{tag?: string, template?: array}  $options
     * @param  bool  $skipIfHumanTookOver  a delayed part of a bot reply (final fix wave I10): not sent
     *                                     once a person has taken the conversation from the bot
     */
    public function __construct(public readonly int $messageId, public readonly array $options = [], public readonly bool $skipIfHumanTookOver = false)
    {
        $this->onQueue('outbound');
    }

    /** Seconds the send claim is held at most — above $timeout, so a killed worker's claim expires on its own. */
    public const CLAIM_SECONDS = 90;

    public static function claimKey(int $messageId): string
    {
        return "send-outbound-message:{$messageId}";
    }

    /**
     * Atomic send claim: only one worker at a time may run the send for a message
     * (a duplicate delivery, a retry racing a manual retry, a chain release racing
     * the original chain, …). The claim is an atomic cache lock rather than a new
     * `sending` status, so the status set seen by the UI, mobile app, retry rules
     * and analytics is unchanged. The status is re-read inside the claim, so the
     * worker that loses the race and runs afterwards finds the message Sent (or
     * Failed) and does nothing. A worker that cannot take the claim returns: the
     * holder owns the message, including its own retry via release().
     */
    public function handle(ChannelRegistry $registry, ActivityLogger $logger, LatencyRecorder $latency): void
    {
        $claim = Cache::lock(self::claimKey($this->messageId), self::CLAIM_SECONDS);

        if (! $claim->get()) {
            return;
        }

        try {
            $this->sendClaimed($registry, $logger, $latency);
        } finally {
            $claim->release();
        }
    }

    private function sendClaimed(ChannelRegistry $registry, ActivityLogger $logger, LatencyRecorder $latency): void
    {
        $message = Message::with(['conversation.channelAccount', 'user', 'mediaAttachments'])->find($this->messageId);

        if ($message === null || $message->status !== MessageStatus::Queued) {
            return;
        }

        $conversation = $message->conversation;

        if ($this->skipIfHumanTookOver && $conversation->handler !== Handler::Bot) {
            // Kept as a failed bubble so the agent can still see (and retry) what the bot had queued.
            $this->markFailed($message, 'human_took_over', $logger);

            return;
        }

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

            $attachment = $message->mediaAttachments->first();
            $hasBody = $message->body !== null && $message->body !== '';

            if ($attachment === null && ! $hasBody) {
                // A caption-less row (body null) only ever exists because it was
                // created to carry an attachment (see OutboundService::dispatchHuman).
                // If that MessageAttachment row is gone by the time this job runs,
                // never fall through to sendText() with an empty body — that would
                // silently deliver a blank message to the customer.
                $result = SendResult::fail('media_attachment_missing');
            } else {
                try {
                    $adapter = $registry->adapter($conversation->platform);

                    $result = $attachment !== null
                        ? $adapter->sendAttachment(
                            $conversation->channelAccount,
                            $identity,
                            $attachment,
                            $hasBody ? (string) $message->body : null,
                            $this->options,
                        )
                        : $adapter->sendText(
                            $conversation->channelAccount,
                            $identity,
                            (string) $message->body,
                            ! empty($message->buttons) ? array_merge($this->options, ['quick_replies' => $message->buttons]) : $this->options,
                        );
                } catch (Throwable $e) {
                    $result = SendResult::fail(self::errorCode($e), retryable: true);
                }
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
            $this->markFailed($message, $e !== null ? self::errorCode($e) : 'send_failed', app(ActivityLogger::class));
        }

        // This link's own message already went out: the run that sent it also
        // continued the chain, so re-dispatching the rest here would send them twice.
        if ($message?->status === MessageStatus::Sent) {
            return;
        }

        $this->releaseRemainingChain();
    }

    /**
     * Code-style error for a thrown exception (final fix wave I1, mirroring
     * DownloadInboundMedia::failed()). A raw exception message is never
     * persisted, broadcast or logged: a connection exception's message embeds
     * the full request URI, including `appsecret_proof` and token parameters.
     */
    public static function errorCode(Throwable $e): string
    {
        return match (true) {
            $e instanceof ConnectionException => 'provider_connection_error',
            $e instanceof RequestException => 'provider_request_error:'.$e->response->status(),
            default => 'send_exception:'.class_basename($e),
        };
    }

    /**
     * A multi-attachment send runs as a Bus chain (final fix wave M2), and
     * Laravel stops a chain at the first link whose job fails outright. The
     * rest were already committed as queued messages, so hand each one back to
     * the queue as its own independent job instead of stranding it in
     * "queued": order among those remaining links is no longer guaranteed,
     * but none is dropped (each job is still idempotent on its message status).
     */
    private function releaseRemainingChain(): void
    {
        $remaining = $this->chained;
        $this->chained = [];

        foreach ($remaining as $serialized) {
            $next = unserialize($serialized);

            if ($next instanceof self) {
                dispatch($next);
            }
        }
    }

    private function markFailed(Message $message, string $error, ActivityLogger $logger): void
    {
        $error = Str::limit($error, 250);

        $message->forceFill([
            'status' => MessageStatus::Failed,
            'error' => $error,
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
