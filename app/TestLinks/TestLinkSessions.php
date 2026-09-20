<?php

namespace App\TestLinks;

use App\Channels\Data\InboundMessageData;
use App\Enums\ConversationStatus;
use App\Enums\Platform;
use App\Inbox\CustomerResolver;
use App\Inbox\InboxIngestor;
use App\Models\BotTestLink;
use App\Models\BotTestSession;
use App\Models\ChannelAccount;
use App\Models\Conversation;
use App\Models\CustomerIdentity;
use App\Models\Message;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Everything a public test link needs to behave like a real customer
 * (design 2026-09-21 §3).
 *
 * Each link gets one dedicated channel account (platform `facebook`, driver
 * `test`), created the first time somebody opens the link. Each tester's run is
 * a Customer, a CustomerIdentity `test:{session}` and a Conversation on that
 * account. Inbound messages go through the real InboxIngestor — the same
 * ingestion, the same bot pipeline, the same published flows and Shopify data
 * as Messenger — and outbound messages are saved but swallowed by
 * App\Channels\Adapters\TestChannelAdapter.
 */
class TestLinkSessions
{
    /** The platform a test session pretends to be, so the flows behave as on Messenger. */
    public const PLATFORM = Platform::Facebook;

    public const IDENTITY_PREFIX = 'test:';

    public const NAME_MIN = 2;

    public const NAME_MAX = 40;

    public function __construct(private readonly InboxIngestor $ingestor) {}

    /** The link's own channel account, created on first use. */
    public function channelAccount(BotTestLink $link): ChannelAccount
    {
        if ($link->channel_account_id !== null && ($account = $link->channelAccount) !== null) {
            return $account;
        }

        $account = ChannelAccount::create([
            'platform' => self::PLATFORM,
            'name' => $link->channelAccountName(),
            'external_id' => 'test-link-'.$link->id,
            'driver' => TestScope::DRIVER,
            'status' => 'connected',
            'connected_at' => now(),
        ]);

        $link->forceFill(['channel_account_id' => $account->id])->save();
        $link->setRelation('channelAccount', $account);

        return $account;
    }

    /** Counts one page view of the link (design addition: «اتفتح 24 مرة»). */
    public function recordView(BotTestLink $link): void
    {
        BotTestLink::query()->whereKey($link->id)->update([
            'views_count' => DB::raw('views_count + 1'),
            'last_opened_at' => now(),
        ]);

        $link->views_count = (int) $link->views_count + 1;
        $link->last_opened_at = now();
    }

    /**
     * Opens a tester's run: their customer, identity and conversation, plus the
     * session row the report reads. `$previous` carries a "Start over" forward, so
     * the new run is numbered after the one it replaces.
     */
    public function start(BotTestLink $link, string $name, Request $request, ?BotTestSession $previous = null): BotTestSession
    {
        $name = self::cleanName($name);
        $account = $this->channelAccount($link);
        $token = BotTestSession::newToken();

        $runNo = 1 + (int) BotTestSession::query()
            ->where('bot_test_link_id', $link->id)
            ->where('tester_name', $name)
            ->max('run_no');

        $session = BotTestSession::create([
            'bot_test_link_id' => $link->id,
            'session_token' => $token,
            'tester_name' => $name,
            'run_no' => $runNo,
            'device_family' => DeviceFamily::of($request->userAgent()),
            'user_agent' => Str::limit((string) $request->userAgent(), 250, ''),
            'ip_hash' => self::ipHash($request->ip()),
            'started_at' => now(),
            'last_seen_at' => now(),
        ]);

        $identity = app(CustomerResolver::class)->resolve(
            self::PLATFORM,
            self::IDENTITY_PREFIX.$token,
            $name,
        );

        $conversation = DB::transaction(fn () => $this->ingestor->openConversationFor($identity, $account));

        $session->forceFill([
            'customer_id' => $identity->customer_id,
            'conversation_id' => $conversation->id,
        ])->save();

        BotTestLink::query()->whereKey($link->id)->update(['sessions_count' => DB::raw('sessions_count + 1')]);

        // A "Start over" keeps the old run in the report, marked as its own session.
        if ($previous !== null && ! $previous->isEnded()) {
            $this->end($previous, BotTestSession::ENDED_RESET);
        }

        return $session;
    }

    /**
     * The tester wrote something: ingested exactly like a Messenger webhook, so the
     * burst policy, the flows, the cases and the learning all see a real turn.
     */
    public function inbound(BotTestSession $session, ?string $text, ?string $payload = null, array $attachments = []): ?Message
    {
        $link = $session->link;
        $account = $this->channelAccount($link);

        $data = new InboundMessageData(
            platform: self::PLATFORM,
            channelExternalId: (string) $account->external_id,
            customerExternalId: self::IDENTITY_PREFIX.$session->session_token,
            customerName: $session->tester_name,
            externalMessageId: 'test:'.$session->session_token.':'.Str::lower(Str::random(16)),
            body: (string) $text,
            occurredAt: CarbonImmutable::now(),
            attachments: $attachments,
            payload: $payload,
        );

        $message = $this->ingestor->ingestMessage($data);

        BotTestSession::query()->whereKey($session->id)->update([
            'messages_count' => DB::raw('messages_count + 1'),
            'last_seen_at' => now(),
        ]);

        $session->messages_count = (int) $session->messages_count + 1;

        return $message;
    }

    /** Marks a run finished; the conversation and its transcript stay for the report. */
    public function end(BotTestSession $session, string $reason): void
    {
        if ($session->isEnded()) {
            return;
        }

        $session->forceFill(['ended_at' => now(), 'ended_reason' => $reason])->save();

        // Closing the run closes its conversation too, so the inbox does not keep a
        // finished test at the top of the queue and the learning review can run on it.
        if ($session->conversation_id !== null) {
            Conversation::query()
                ->whereKey($session->conversation_id)
                ->where('status', '!=', ConversationStatus::Resolved->value)
                ->update(['status' => ConversationStatus::Resolved->value, 'resolved_at' => now()]);
        }
    }

    /** Stopping a link ends its sessions immediately (design §6). */
    public function endAllFor(BotTestLink $link, string $reason = BotTestSession::ENDED_LINK_STOPPED): int
    {
        $ended = 0;

        foreach ($link->sessions()->whereNull('ended_at')->get() as $session) {
            $this->end($session, $reason);
            $ended++;
        }

        return $ended;
    }

    /** The tester's page: the messages of their own conversation, nothing else. */
    public function identity(BotTestSession $session): ?CustomerIdentity
    {
        return CustomerIdentity::query()
            ->where('platform', self::PLATFORM->value)
            ->where('external_id', self::IDENTITY_PREFIX.$session->session_token)
            ->first();
    }

    public static function cleanName(string $name): string
    {
        $clean = trim(preg_replace('/\s+/u', ' ', strip_tags($name)) ?? '');

        return Str::limit($clean, self::NAME_MAX, '');
    }

    /** Never the address itself: a salted hash, enough for the per-IP limit and counts. */
    public static function ipHash(?string $ip): ?string
    {
        return $ip === null || $ip === '' ? null : hash('sha256', config('app.key').'|try|'.$ip);
    }
}
