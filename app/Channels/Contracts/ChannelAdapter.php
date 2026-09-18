<?php

namespace App\Channels\Contracts;

use App\Channels\Data\ChannelCapabilities;
use App\Channels\Data\DeliveryReceiptData;
use App\Channels\Data\InboundCommentData;
use App\Channels\Data\InboundMessageData;
use App\Channels\Data\SendResult;
use App\Enums\Platform;
use App\Models\ChannelAccount;
use App\Models\CustomerIdentity;
use App\Models\MessageAttachment;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

interface ChannelAdapter
{
    public function platform(): Platform;

    public function capabilities(): ChannelCapabilities;

    /**
     * GET verify handshake (webhook subscription challenge).
     */
    public function handshake(Request $request): ?Response;

    public function verifySignature(Request $request): bool;

    /**
     * @return array<InboundMessageData|InboundCommentData|DeliveryReceiptData>
     */
    public function normalize(array $payload): array;

    /**
     * @param  array{tag?: string, template?: array{name: string, language: string, params: array}}  $options
     */
    public function sendText(ChannelAccount $account, CustomerIdentity $to, string $text, array $options = []): SendResult;

    /**
     * @param  array{tag?: string}  $options
     */
    public function sendAttachment(ChannelAccount $account, CustomerIdentity $to, MessageAttachment $attachment, ?string $caption = null, array $options = []): SendResult;

    public function replyToComment(ChannelAccount $account, string $commentExternalId, string $text): SendResult;

    public function hideComment(ChannelAccount $account, string $commentExternalId): SendResult;

    public function sendPrivateReply(ChannelAccount $account, string $commentExternalId, string $text): SendResult;

    /** Best-effort typing indicator; adapters without one do nothing. Never throws. */
    public function typing(ChannelAccount $account, CustomerIdentity $to, bool $on): void;
}
