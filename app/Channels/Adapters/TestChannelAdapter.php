<?php

namespace App\Channels\Adapters;

use App\Channels\Contracts\ChannelAdapter;
use App\Channels\Data\ChannelCapabilities;
use App\Channels\Data\SendResult;
use App\Enums\Platform;
use App\Models\ChannelAccount;
use App\Models\CustomerIdentity;
use App\Models\MessageAttachment;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The adapter behind a `driver = test` channel account: the public team test
 * links (design 2026-09-21 §3).
 *
 * Nothing it is handed ever leaves the system. A send is saved as an ordinary
 * outbound message and immediately reported as delivered, so the whole real
 * pipeline (window policy, pacing, retries, the inbox, the agent's own replies)
 * behaves exactly as it does on Messenger while the tester reads the reply from
 * their own page instead of from Meta.
 *
 * It accepts no webhooks: a test session's inbound messages come from the
 * tester's page through App\TestLinks\TestLinkSessions, never from a platform.
 */
final class TestChannelAdapter implements ChannelAdapter
{
    /** The id prefix of every "sent" message, so a test send is recognisable in the data. */
    public const EXTERNAL_PREFIX = 'test_';

    public function platform(): Platform
    {
        return Platform::Facebook;
    }

    /** Messenger's own capabilities, so the window rules the team tests are the real ones. */
    public function capabilities(): ChannelCapabilities
    {
        return new ChannelCapabilities(
            privateReply: true,
            hideComment: true,
            windowHours: 24,
            humanAgentHours: 168,
            templatesOutsideWindow: false,
        );
    }

    public function handshake(Request $request): ?Response
    {
        return null;
    }

    /** No webhook may ever be accepted for a test account. */
    public function verifySignature(Request $request): bool
    {
        return false;
    }

    public function normalize(array $payload): array
    {
        return [];
    }

    public function sendText(ChannelAccount $account, CustomerIdentity $to, string $text, array $options = []): SendResult
    {
        return $this->swallow();
    }

    public function sendAttachment(ChannelAccount $account, CustomerIdentity $to, MessageAttachment $attachment, ?string $caption = null, array $options = []): SendResult
    {
        return $this->swallow();
    }

    public function replyToComment(ChannelAccount $account, string $commentExternalId, string $text): SendResult
    {
        return $this->swallow();
    }

    public function hideComment(ChannelAccount $account, string $commentExternalId): SendResult
    {
        return $this->swallow();
    }

    public function sendPrivateReply(ChannelAccount $account, string $commentExternalId, string $text): SendResult
    {
        return $this->swallow();
    }

    /** The tester's page draws its own typing bubble from the conversation's state. */
    public function typing(ChannelAccount $account, CustomerIdentity $to, bool $on): void {}

    private function swallow(): SendResult
    {
        return SendResult::ok(self::EXTERNAL_PREFIX.bin2hex(random_bytes(8)));
    }
}
