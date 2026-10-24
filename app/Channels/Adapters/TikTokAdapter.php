<?php

namespace App\Channels\Adapters;

use App\Ads\Platforms\AdsApiException;
use App\Ads\Platforms\MissingPermission;
use App\Ads\Platforms\RateLimited;
use App\Ads\Platforms\SecretScrubber;
use App\Channels\Adapters\TikTok\TikTokApiException;
use App\Channels\Adapters\TikTok\TikTokMessagingApi;
use App\Channels\Adapters\TikTok\TikTokWindow;
use App\Channels\Cards\OutboundCards;
use App\Channels\Contracts\ChannelAdapter;
use App\Channels\Contracts\LimitsOutbound;
use App\Channels\Contracts\UnhidesComments;
use App\Channels\Data\AdReferralData;
use App\Channels\Data\ChannelCapabilities;
use App\Channels\Data\InboundMessageData;
use App\Channels\Data\OutboundEchoData;
use App\Channels\Data\SendResult;
use App\Comments\TikTok\TikTokAdCommentsApi;
use App\Enums\AttachmentType;
use App\Enums\Platform;
use App\Models\AdAccount;
use App\Models\ChannelAccount;
use App\Models\Comment;
use App\Models\Conversation;
use App\Models\CustomerIdentity;
use App\Models\MessageAttachment;
use App\Models\TikTokConversation;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * TikTok: ad comments (E1, 2026-10-17) through the Marketing API on the ad account's token, and direct
 * messages (x6, 2026-10-24) through the Business Messaging API on the messaging account's own OAuth token.
 *
 * DMs (docs read 2026-10-24, links in docs/round-2026-10-24/build-x6.md):
 *  - webhook `Tiktok-Signature: t=<unix>,s=<hex>` = HMAC-SHA256(t + '.' + raw body, app secret); the events
 *    `im_receive_msg` (a customer wrote), `im_send_msg` (an echo of anything the account sent), `im_referral_msg`
 *    (she came from a Click-to-Message ad or a tiktok.me link); the `content` field is a JSON string;
 *  - a reply goes to `conversation_id` (`/business/message/send/`): TEXT ≤ 6000 chars, IMAGE by media_id
 *    (JPG/PNG ≤ 3 MB uploaded first), TEMPLATE QA_BUTTON_CARD (a ≤ 40-char question with 1–3 ≤ 20-char reply
 *    buttons; a tap comes back as a text message carrying our button id in reply_source_unique_id);
 *  - the business can never write first; 10 messages in the first 48 h until she replies, unlimited for 48 h
 *    after each of her replies, 3 more after 48 h of silence (TikTokWindow, through LimitsOutbound).
 * Comment-to-Message is not offered in Egypt, so a comment never gets a private reply.
 */
final class TikTokAdapter implements ChannelAdapter, LimitsOutbound, UnhidesComments
{
    public const PRIVATE_REPLY_REFUSED = 'TikTok does not allow a private message from a comment in Egypt: reply publicly; the customer can message the account themselves.';

    public const DMS_NOT_CONNECTED = 'TikTok messages are not connected: connect the TikTok account in Settings → Channels.';

    public const NO_CONVERSATION = 'TikTok has no conversation with this customer yet: a business cannot write first, she has to message the account.';

    public const IMAGE_ONLY = 'TikTok messages accept one JPG/PNG image up to 3 MB; nothing else can be sent.';

    public const BLOCKED = 'TikTok refused the message (direct-message rules: the 48-hour window or the message cap). Wait for the customer to write again.';

    public const TOP_LEVEL_ONLY = 'TikTok allows replies to first-level comments only; reply to the original comment instead.';

    public const SIGNATURE_HEADER = 'Tiktok-Signature';

    public const TEXT_MAX = 6000;

    public const CARD_TITLE_MAX = 40;

    public const BUTTON_TITLE_MAX = 20;

    public const MAX_BUTTONS = 3;

    public const IMAGE_MAX_BYTES = 3 * 1024 * 1024;

    public const IMAGE_MIMES = ['image/jpeg', 'image/png'];

    public function __construct(private readonly TikTokAdCommentsApi $api, private readonly TikTokMessagingApi $dm) {}

    public function platform(): Platform
    {
        return Platform::TikTok;
    }

    public function capabilities(): ChannelCapabilities
    {
        // humanAgentHours: after the 48 h window staff may still send the 3 messages TikTok allows (TikTokWindow
        // closes the window once they are spent); the bot never writes outside the window.
        return new ChannelCapabilities(privateReply: false, hideComment: true, windowHours: TikTokWindow::WINDOW_HOURS, humanAgentHours: 24 * 30, templatesOutsideWindow: false);
    }

    public function outboundAllowance(Conversation $conversation): ?int
    {
        return TikTokWindow::remaining($conversation);
    }

    /** TikTok has no GET verification challenge: a GET is refused. */
    public function handshake(Request $request): ?Response
    {
        return null;
    }

    /**
     * `Tiktok-Signature: t=<seconds>,s=<hex>`; signed payload = t + '.' + the raw body, HMAC-SHA256 with the app
     * secret (Webhook verification). A timestamp older than services.tiktok.signature_tolerance_seconds is a replay.
     */
    public function verifySignature(Request $request): bool
    {
        $secret = (string) config('services.tiktok.app_secret');
        $header = (string) $request->header(self::SIGNATURE_HEADER, '');

        if ($secret === '' || $header === '') {
            return false;
        }

        $parts = [];

        foreach (explode(',', $header) as $piece) {
            [$k, $v] = array_pad(explode('=', trim($piece), 2), 2, '');
            $parts[trim($k)] = trim($v);
        }

        $t = $parts['t'] ?? '';
        $s = strtolower($parts['s'] ?? '');

        if ($t === '' || $s === '' || preg_match('/^\d{1,12}$/', $t) !== 1) {
            return false;
        }

        $expected = hash_hmac('sha256', $t.'.'.$request->getContent(), $secret);

        if (! hash_equals($expected, $s)) {
            return false;
        }

        $tolerance = (int) config('services.tiktok.signature_tolerance_seconds', 300);

        return $tolerance <= 0 || abs(time() - (int) $t) <= $tolerance;
    }

    /**
     * One webhook delivery: {client_key, event, create_time, user_openid, content: "<json>"}. The business account
     * is `user_openid` (ChannelAccount.external_id); the customer is `unique_identifier` (CustomerIdentity.external_id,
     * the same id the comment APIs use). The conversation id is remembered for the reply.
     */
    public function normalize(array $payload): array
    {
        $event = (string) ($payload['event'] ?? '');
        $content = $payload['content'] ?? [];

        if (is_string($content)) {
            $content = json_decode($content, true);
        }

        if (! is_array($content) || $event === '') {
            return [];
        }

        $businessId = (string) ($payload['user_openid'] ?? '');
        $fromRole = (string) ($content['from_user']['role'] ?? '');
        $toRole = (string) ($content['to_user']['role'] ?? '');

        if ($businessId === '') {
            $businessId = (string) ($toRole === 'business_account' ? ($content['to_user']['id'] ?? '') : ($fromRole === 'business_account' ? ($content['from_user']['id'] ?? '') : ''));
        }

        $occurredAt = $this->fromMs($content['timestamp'] ?? (((int) ($payload['create_time'] ?? 0)) * 1000));
        $conversationId = (string) ($content['conversation_id'] ?? '');

        return match ($event) {
            'im_receive_msg' => $this->inbound($content, $businessId, $conversationId, $occurredAt),
            'im_send_msg' => $this->echo($content, $businessId, $occurredAt),
            'im_referral_msg' => $this->referral($content, $businessId, $conversationId, $occurredAt),
            // im_mark_read_msg carries only a read timestamp (no message id) — nothing to update;
            // im_receive_msg_eu is a notice without content; the auto-message events are settings changes.
            default => [],
        };
    }

    /** @return list<InboundMessageData> */
    private function inbound(array $c, string $businessId, string $conversationId, CarbonImmutable $at): array
    {
        $type = (string) ($c['type'] ?? 'text');

        // A reaction edits an earlier message; a template is a card the account sent: never a customer message.
        if (in_array($type, ['reaction', 'template'], true)) {
            return [];
        }

        $customerId = (string) ($c['unique_identifier'] ?? ($c['from_user']['id'] ?? ''));
        $username = (string) ($c['from'] ?? '');

        if ($customerId === '' || $businessId === '') {
            return [];
        }

        if ($conversationId !== '') {
            TikTokConversation::remember($businessId, $customerId, $conversationId, $username, $at);
        }

        $body = '';
        $attachments = [];

        switch ($type) {
            case 'text':
                $body = (string) ($c['text']['body'] ?? '');
                break;
            case 'image':
                $attachments[] = ['type' => 'image', 'id' => (string) ($c['image']['media_id'] ?? ''), 'mime_type' => 'image/jpeg'];
                break;
            case 'video':
                $attachments[] = ['type' => 'video', 'id' => (string) ($c['video']['media_id'] ?? ''), 'mime_type' => 'video/mp4'];
                break;
            case 'sticker':
                $attachments[] = array_filter(['type' => 'sticker', 'url' => $c['sticker']['url'] ?? null, 'id' => filled($c['sticker']['url'] ?? null) ? null : 'sticker']);
                break;
            case 'emoji':
                // A TikTok emoji picture (not a text emoji): shown as a sticker when it has a url, else a placeholder.
                if (filled($c['emoji']['url'] ?? null)) {
                    $attachments[] = ['type' => 'sticker', 'url' => (string) $c['emoji']['url']];
                } else {
                    $body = '[إيموجي]';
                }
                break;
            case 'share_post':
                $post = (array) ($c['share_post'] ?? []);
                $body = trim('شاركت فيديو تيك توك: '.(string) ($post['embed_url'] ?? ('video '.($post['video_id'] ?? ''))));
                break;
            default:
                $body = '[رسالة من نوع '.$type.' مش مدعومة]';
        }

        // A tap on one of our Q&A card buttons: the title arrives as her text, our button id as the payload.
        $payload = $c['reply_source_payload']['reply_source_unique_id'] ?? null;

        return [new InboundMessageData(
            platform: Platform::TikTok,
            channelExternalId: $businessId,
            customerExternalId: $customerId,
            customerName: $username,
            externalMessageId: (string) ($c['message_id'] ?? ''),
            body: $body,
            occurredAt: $at,
            attachments: array_values(array_filter($attachments)),
            customerUsername: $username !== '' ? $username : null,
            payload: filled($payload) ? (string) $payload : null,
        )];
    }

    /** @return list<OutboundEchoData> */
    private function echo(array $c, string $businessId, CarbonImmutable $at): array
    {
        // Our own sends come back tagged API and are already in the thread by the id TikTok returned.
        if (strtoupper((string) ($c['message_tag']['source'] ?? '')) === 'API') {
            return [];
        }

        $customerId = (string) ($c['to_user']['id'] ?? '');
        $type = (string) ($c['type'] ?? 'text');

        if ($customerId === '' || $businessId === '' || in_array($type, ['reaction'], true)) {
            return [];
        }

        $body = match ($type) {
            'text' => (string) ($c['text']['body'] ?? ''),
            'share_post' => (string) ($c['share_post']['embed_url'] ?? ''),
            'template' => (string) ($c['template']['elements'][0]['title'] ?? ''),
            default => '',
        };
        $attachments = match ($type) {
            'image' => [['type' => 'image', 'id' => (string) ($c['image']['media_id'] ?? ''), 'mime_type' => 'image/jpeg']],
            'video' => [['type' => 'video', 'id' => (string) ($c['video']['media_id'] ?? ''), 'mime_type' => 'video/mp4']],
            'sticker' => filled($c['sticker']['url'] ?? null) ? [['type' => 'sticker', 'url' => (string) $c['sticker']['url']]] : [],
            default => [],
        };

        return [new OutboundEchoData(
            platform: Platform::TikTok,
            channelExternalId: $businessId,
            customerExternalId: $customerId,
            externalMessageId: (string) ($c['message_id'] ?? ''),
            body: $body,
            occurredAt: $at,
            attachments: $attachments,
        )];
    }

    /** @return list<InboundMessageData> an empty message carrying the ad / link she came through */
    private function referral(array $c, string $businessId, string $conversationId, CarbonImmutable $at): array
    {
        $customerId = (string) ($c['unique_identifier'] ?? ($c['from_user']['id'] ?? ''));
        $username = (string) ($c['from'] ?? '');
        $referral = (array) ($c['referral'] ?? []);
        $source = strtolower((string) ($referral['source'] ?? ''));

        if ($customerId === '' || $businessId === '') {
            return [];
        }

        if ($conversationId !== '') {
            TikTokConversation::remember($businessId, $customerId, $conversationId, $username, $at);
        }

        $data = match ($source) {
            'ad' => new AdReferralData(
                source: 'ADS',
                type: 'tiktok_ad',
                adId: filled($referral['ad']['ad_id'] ?? null) ? (string) $referral['ad']['ad_id'] : null,
                adTitle: filled($referral['ad']['ad_name'] ?? null) ? mb_substr((string) $referral['ad']['ad_name'], 0, 190) : null,
                photoUrl: filled($referral['ad']['embed_url'] ?? null) ? mb_substr((string) $referral['ad']['embed_url'], 0, 500) : null,
            ),
            'short_link' => new AdReferralData(
                source: 'SHORTLINK',
                type: 'tiktok_me',
                ref: filled($referral['short_link']['ref'] ?? null) ? mb_substr((string) $referral['short_link']['ref'], 0, 190) : null,
            ),
            default => null,
        };

        if ($data === null) {
            return [];
        }

        return [new InboundMessageData(
            platform: Platform::TikTok,
            channelExternalId: $businessId,
            customerExternalId: $customerId,
            customerName: $username,
            externalMessageId: 'referral:'.$customerId.':'.$at->getTimestampMs(),
            body: '',
            occurredAt: $at,
            customerUsername: $username !== '' ? $username : null,
            referral: $data,
        )];
    }

    private function fromMs(mixed $ms): CarbonImmutable
    {
        $ms = (int) $ms;

        return $ms > 0 ? CarbonImmutable::createFromTimestampMs($ms) : CarbonImmutable::now();
    }

    // ---- outbound DMs -------------------------------------------------------------------------------------------

    /**
     * @param  array{quick_replies?: list<array{title: string, payload: string}>, cards?: array}  $options
     */
    public function sendText(ChannelAccount $account, CustomerIdentity $to, string $text, array $options = []): SendResult
    {
        if (($refused = $this->refuseDm($account)) !== null) {
            return $refused;
        }

        $conversationId = TikTokConversation::idFor((string) $account->external_id, (string) $to->external_id);

        if ($conversationId === null) {
            return SendResult::fail(self::NO_CONVERSATION);
        }

        // Rich cards: TikTok has no carousel and pictures would each cost one of the capped messages, so a card
        // becomes its text and link lines in the same message (OutboundCards::cardText / withLinkLines).
        if (($cards = OutboundCards::valid($options['cards'] ?? null)) !== null) {
            $text = $cards['type'] === 'button'
                ? OutboundCards::withLinkLines($text, $cards)
                : rtrim($text)."\n\n".implode("\n\n", array_map(fn (array $card) => OutboundCards::cardText($card), $cards['cards']));
        }

        $buttons = array_values(array_filter((array) ($options['quick_replies'] ?? []), fn ($b) => is_array($b) && trim((string) ($b['title'] ?? '')) !== ''));
        $card = $this->buttonCard($buttons);

        if ($buttons !== [] && $card === null) {
            // The numbered fallback (ButtonMatcher reads the number she types), one message.
            $lines = array_map(fn (int $i, array $b) => ($i + 1).'- '.$b['title'], array_keys($buttons), $buttons);
            $text = rtrim($text)."\n\n".implode("\n", $lines);
        }

        $result = $this->dmCall($account, fn () => $this->dm->send($account, $this->textBody($conversationId, $text)));

        if (! $result->success || $card === null) {
            return $result;
        }

        $cardResult = $this->dmCall($account, fn () => $this->dm->send($account, [
            'recipient_type' => 'CONVERSATION', 'recipient' => $conversationId, 'message_type' => 'TEMPLATE', 'template' => $card,
        ]));

        if ($cardResult->success || $cardResult->authError) {
            return $result;
        }

        // The card was refused (image/template not offered in this market): the options go as numbered text instead.
        $lines = array_map(fn (int $i, array $b) => ($i + 1).'- '.$b['title'], array_keys($buttons), $buttons);
        $this->dmCall($account, fn () => $this->dm->send($account, $this->textBody($conversationId, implode("\n", $lines))));

        return $result;
    }

    /**
     * 1–3 buttons with ≤ 20-char titles as a Q&A button card (services.tiktok.menu_style = card), else null:
     * the caller appends them as a numbered list. The card's question is the configured short prompt.
     *
     * @param  list<array{title: string, payload: string}>  $buttons
     * @return array<string, mixed>|null
     */
    private function buttonCard(array $buttons): ?array
    {
        if (! TikTokWindow::sentAsCard($buttons)) {
            return null;
        }

        return [
            'type' => 'QA_BUTTON_CARD',
            'title' => mb_substr((string) config('services.tiktok.menu_prompt', 'اختاري من هنا'), 0, self::CARD_TITLE_MAX),
            'buttons' => array_map(fn (array $b) => [
                'type' => 'REPLY',
                'title' => (string) $b['title'],
                'id' => mb_substr((string) ($b['payload'] ?? $b['title']), 0, 40),
            ], $buttons),
        ];
    }

    /** @return array<string, mixed> */
    private function textBody(string $conversationId, string $text): array
    {
        return [
            'recipient_type' => 'CONVERSATION',
            'recipient' => $conversationId,
            'message_type' => 'TEXT',
            'text' => ['body' => mb_substr(trim($text) === '' ? '…' : $text, 0, self::TEXT_MAX)],
        ];
    }

    public function sendAttachment(ChannelAccount $account, CustomerIdentity $to, MessageAttachment $attachment, ?string $caption = null, array $options = []): SendResult
    {
        if (($refused = $this->refuseDm($account)) !== null) {
            return $refused;
        }

        $conversationId = TikTokConversation::idFor((string) $account->external_id, (string) $to->external_id);

        if ($conversationId === null) {
            return SendResult::fail(self::NO_CONVERSATION);
        }

        if ($attachment->type !== AttachmentType::Image || ! in_array((string) $attachment->mime, self::IMAGE_MIMES, true) || (int) $attachment->size_bytes > self::IMAGE_MAX_BYTES) {
            return SendResult::fail(self::IMAGE_ONLY);
        }

        $result = $this->dmCall($account, function () use ($account, $attachment, $conversationId) {
            $mediaId = $this->dm->uploadImage($account, $attachment->absolutePath(), $attachment->original_name ?: basename($attachment->absolutePath()));

            return $this->dm->send($account, ['recipient_type' => 'CONVERSATION', 'recipient' => $conversationId, 'message_type' => 'IMAGE', 'image' => ['media_id' => $mediaId]]);
        });

        // A message cannot carry text and an image together: the caption follows as its own text (best effort).
        if ($result->success && $caption !== null && trim($caption) !== '') {
            $this->dmCall($account, fn () => $this->dm->send($account, $this->textBody($conversationId, $caption)));
        }

        return $result;
    }

    /** SENDER_ACTION TYPING shows for five seconds; TikTok has no "typing off". Never throws. */
    public function typing(ChannelAccount $account, CustomerIdentity $to, bool $on): void
    {
        if (! $on || $this->refuseDm($account) !== null) {
            return;
        }

        $conversationId = TikTokConversation::idFor((string) $account->external_id, (string) $to->external_id);

        if ($conversationId === null) {
            return;
        }

        rescue(fn () => $this->dm->send($account, ['recipient_type' => 'CONVERSATION', 'recipient' => $conversationId, 'message_type' => 'SENDER_ACTION', 'sender_action' => 'TYPING']), report: false);
    }

    /** A DM needs a connected live messaging account with a token; an ad-comments-only channel has neither. */
    private function refuseDm(ChannelAccount $account): ?SendResult
    {
        if ($account->status === 'disconnected') {
            return SendResult::fail('This TikTok channel is disconnected in Settings; nothing was sent to TikTok.');
        }

        if (blank($account->credentials['access_token'] ?? null)) {
            return SendResult::fail(self::DMS_NOT_CONNECTED, authError: true);
        }

        return null;
    }

    /**
     * @param  callable(): array{message_id?: string}  $fn
     */
    private function dmCall(ChannelAccount $account, callable $fn): SendResult
    {
        try {
            $data = $fn();

            return SendResult::ok((string) ($data['message_id'] ?? ''));
        } catch (TikTokApiException $e) {
            if ($e->isAuth()) {
                return SendResult::fail('TikTok refused our token: '.$e->getMessage(), authError: true);
            }

            if ($e->isBlockedByRules()) {
                return SendResult::fail(self::BLOCKED);
            }

            return SendResult::fail('TikTok: '.$e->getMessage(), retryable: $e->isRetryable());
        } catch (ConnectionException $e) {
            return SendResult::fail('TikTok is unreachable; retrying.', retryable: true);
        }
    }

    // ---- ad comments (E1) ----------------------------------------------------------------------------------------

    public function replyToComment(ChannelAccount $account, string $commentExternalId, string $text): SendResult
    {
        return $this->call($commentExternalId, function (Comment $c, AdAccount $a, array $meta) use ($text) {
            if ($c->parent_external_id !== null) {
                return SendResult::fail(self::TOP_LEVEL_ONLY);
            }

            return SendResult::ok($this->api->reply($a, $meta, (string) $c->external_id, $text));
        });
    }

    public function hideComment(ChannelAccount $account, string $commentExternalId): SendResult
    {
        return $this->visibility($commentExternalId, true);
    }

    public function unhideComment(ChannelAccount $account, string $commentExternalId): SendResult
    {
        return $this->visibility($commentExternalId, false);
    }

    public function sendPrivateReply(ChannelAccount $account, string $commentExternalId, string $text): SendResult
    {
        return SendResult::fail(self::PRIVATE_REPLY_REFUSED);
    }

    private function visibility(string $commentExternalId, bool $hidden): SendResult
    {
        return $this->call($commentExternalId, function (Comment $c, AdAccount $a) use ($hidden) {
            $this->api->setHidden($a, (string) $c->external_id, $hidden);

            return SendResult::ok((string) $c->external_id);
        });
    }

    /**
     * The comment, its ad account and the post's platform_meta for one call; platform errors become a failed result
     * with TikTok's own text (scrubbed). A throttle is retryable.
     *
     * @param  callable(Comment, AdAccount, array<string, mixed>): SendResult  $fn
     */
    private function call(string $commentExternalId, callable $fn): SendResult
    {
        $comment = Comment::with('post.channelAccount')->where('external_id', $commentExternalId)
            ->whereHas('post', fn ($q) => $q->where('platform', Platform::TikTok))->first();
        $meta = $comment?->post?->platform_meta ?? [];
        $account = null;
        if (! empty($meta['ad_account_id'])) {
            $account = AdAccount::with('connection')->where('platform', 'tiktok')->find((int) $meta['ad_account_id']);
        }
        if ($account === null && ! empty($meta['advertiser_id'])) {
            $account = AdAccount::with('connection')->where('platform', 'tiktok')->where('external_id', (string) $meta['advertiser_id'])->first();
        }
        if ($comment === null || $account === null) {
            return SendResult::fail('This TikTok comment is not linked to a TikTok ad account in the CRM.');
        }
        if ($comment->post?->channelAccount?->status === 'disconnected') {
            return SendResult::fail('This TikTok channel is disconnected in Settings; nothing was sent to TikTok.');
        }
        if (config('crm.ads.drivers.tiktok') !== 'live') {
            return SendResult::fail('TikTok is not live on this server.');
        }

        try {
            return $fn($comment, $account, $meta);
        } catch (RateLimited $e) {
            return SendResult::fail('TikTok asked to slow down; try again in a few minutes.', retryable: true);
        } catch (MissingPermission $e) {
            return SendResult::fail('TikTok refused (missing permission for ad comments): '.SecretScrubber::scrub($e->getMessage()));
        } catch (AdsApiException $e) {
            return SendResult::fail(SecretScrubber::scrub($e->getMessage()));
        }
    }
}
