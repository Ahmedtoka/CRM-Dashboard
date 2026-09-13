<?php

namespace App\Channels\Adapters;

use App\Channels\Contracts\ChannelAdapter;
use App\Channels\Data\ChannelCapabilities;
use App\Channels\Data\DeliveryReceiptData;
use App\Channels\Data\InboundCommentData;
use App\Channels\Data\InboundMessageData;
use App\Channels\Data\SendResult;
use App\Enums\MessageStatus;
use App\Enums\Platform;
use App\Models\ChannelAccount;
use App\Models\CustomerIdentity;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * In-memory fake adapter used whenever the "fake" channel driver is active
 * (the default for local/demo/testing). Records every outbound call to a
 * static log so tests can assert on what was "sent" without hitting a
 * real platform API.
 */
final class FakeChannelAdapter implements ChannelAdapter
{
    /** @var array<int, array<string, mixed>> */
    private static array $sent = [];

    /** @var array{error: string, retryable: bool, authError: bool}|null */
    private static ?array $failNext = null;

    private ?string $defaultChannelId = null;

    public function __construct(private readonly Platform $platform) {}

    /**
     * Reset the static log between tests.
     */
    public static function reset(): void
    {
        self::$sent = [];
        self::$failNext = null;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public static function sent(): array
    {
        return self::$sent;
    }

    public static function failNext(string $error, bool $retryable = false, bool $authError = false): void
    {
        self::$failNext = ['error' => $error, 'retryable' => $retryable, 'authError' => $authError];
    }

    public function platform(): Platform
    {
        return $this->platform;
    }

    public function capabilities(): ChannelCapabilities
    {
        return match ($this->platform) {
            Platform::WhatsApp => new ChannelCapabilities(
                privateReply: false,
                hideComment: false,
                windowHours: 24,
                humanAgentHours: 0,
                templatesOutsideWindow: true,
            ),
            Platform::Facebook, Platform::Instagram => new ChannelCapabilities(
                privateReply: true,
                hideComment: true,
                windowHours: 24,
                humanAgentHours: 168,
                templatesOutsideWindow: false,
            ),
            Platform::TikTok => new ChannelCapabilities(
                privateReply: false,
                hideComment: true,
                windowHours: 48,
                humanAgentHours: 0,
                templatesOutsideWindow: false,
            ),
        };
    }

    public function handshake(Request $request): ?Response
    {
        return response('OK');
    }

    /**
     * Fake payloads carry no signature, so they are only trusted where nobody
     * outside can reach them (local/testing) or when an operator explicitly opts
     * in with CRM_ALLOW_FAKE_WEBHOOKS=true (e.g. a public demo server).
     */
    public function verifySignature(Request $request): bool
    {
        return app()->environment(['local', 'testing']) || (bool) config('crm.allow_fake_webhooks', false);
    }

    public function normalize(array $payload): array
    {
        $events = [];

        foreach ($payload['events'] ?? [] as $event) {
            $dto = match ($event['type'] ?? null) {
                'message' => new InboundMessageData(
                    platform: $this->platform,
                    channelExternalId: $this->channelExternalId($event),
                    customerExternalId: (string) $event['customer_id'],
                    customerName: $event['name'] ?? '',
                    externalMessageId: (string) $event['id'],
                    body: $event['text'] ?? '',
                    occurredAt: CarbonImmutable::parse($event['at']),
                    customerPhone: $event['phone'] ?? null,
                ),
                'comment' => new InboundCommentData(
                    platform: $this->platform,
                    channelExternalId: $this->channelExternalId($event),
                    postExternalId: (string) $event['post_id'],
                    commentExternalId: (string) $event['comment_id'],
                    customerExternalId: (string) $event['customer_id'],
                    customerName: $event['name'] ?? '',
                    body: $event['text'] ?? '',
                    occurredAt: CarbonImmutable::parse($event['at']),
                    postCaption: $event['post_caption'] ?? null,
                    isAd: $event['is_ad'] ?? false,
                ),
                'receipt' => new DeliveryReceiptData(
                    platform: $this->platform,
                    externalMessageId: (string) $event['message_id'],
                    status: MessageStatus::from($event['status']),
                    occurredAt: CarbonImmutable::parse($event['at']),
                ),
                default => null,
            };

            if ($dto !== null) {
                $events[] = $dto;
            }
        }

        return $events;
    }

    public function sendText(ChannelAccount $account, CustomerIdentity $to, string $text, array $options = []): SendResult
    {
        return $this->record('sendText', [
            'account_id' => $account->id,
            'to' => $to->external_id,
            'text' => $text,
            'options' => $options,
        ]);
    }

    public function replyToComment(ChannelAccount $account, string $commentExternalId, string $text): SendResult
    {
        return $this->record('replyToComment', [
            'account_id' => $account->id,
            'comment_external_id' => $commentExternalId,
            'text' => $text,
        ]);
    }

    public function hideComment(ChannelAccount $account, string $commentExternalId): SendResult
    {
        return $this->record('hideComment', [
            'account_id' => $account->id,
            'comment_external_id' => $commentExternalId,
        ]);
    }

    public function sendPrivateReply(ChannelAccount $account, string $commentExternalId, string $text): SendResult
    {
        return $this->record('sendPrivateReply', [
            'account_id' => $account->id,
            'comment_external_id' => $commentExternalId,
            'text' => $text,
        ]);
    }

    /**
     * The page/number the event arrived on: an explicit `channel_id` in the payload,
     * else the platform's existing account (one canonical account per platform, e.g.
     * `demo-whatsapp`), so fake traffic never splits conversations across accounts.
     *
     * @param  array<string, mixed>  $event
     */
    private function channelExternalId(array $event): string
    {
        if (! empty($event['channel_id'])) {
            return (string) $event['channel_id'];
        }

        return $this->defaultChannelId ??= (string) (ChannelAccount::where('platform', $this->platform->value)->orderBy('id')->value('external_id')
            ?? 'demo-'.$this->platform->value);
    }

    /**
     * @param  array<string, mixed>  $args
     */
    private function record(string $method, array $args): SendResult
    {
        $entry = array_merge([
            'method' => $method,
            'platform' => $this->platform->value,
        ], $args);

        if (self::$failNext !== null) {
            $failure = self::$failNext;
            self::$failNext = null;
            $entry['result'] = 'fail';
            self::$sent[] = $entry;

            return SendResult::fail($failure['error'], $failure['retryable'], $failure['authError'] ?? false);
        }

        $entry['result'] = 'ok';
        self::$sent[] = $entry;

        return SendResult::ok('fake_'.bin2hex(random_bytes(6)));
    }
}
