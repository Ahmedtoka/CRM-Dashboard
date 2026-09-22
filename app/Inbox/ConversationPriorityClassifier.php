<?php

namespace App\Inbox;

use App\Analytics\ActivityLogger;
use App\Bot\ArabicNormalizer;
use App\Bot\Flows\FlowEngine;
use App\Bot\Flows\FlowState;
use App\Enums\ActorType;
use App\Enums\ConversationPriority;
use App\Enums\MessageDirection;
use App\Inbox\Data\PriorityVerdict;
use App\Models\BotSetting;
use App\Models\Conversation;
use App\Models\CustomerIdentity;
use App\Models\Message;
use App\Models\User;
use App\Shopify\Connection\IntegrationRepository;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Rule-based spam/low-value classification for inbound messages (spec §11.1).
 * Runs synchronously on every inbound message, before the bot dispatches.
 */
final class ConversationPriorityClassifier
{
    private const REPEAT_WINDOW_MINUTES = 10;

    public function __construct(
        private readonly ArabicNormalizer $normalizer,
        private readonly ActivityLogger $logger,
        private readonly IntegrationRepository $integrations,
    ) {}

    public function classify(Message $inbound, CustomerIdentity $identity): PriorityVerdict
    {
        $settings = BotSetting::current();
        $body = (string) ($inbound->body ?? '');
        $trimmed = trim($body);
        $normalized = trim($this->normalizer->normalize($body));

        if (! $identity->spam_allowlisted) {
            $allowedDomains = $settings->allowed_link_domains ?: BotSetting::DEFAULT_ALLOWED_LINK_DOMAINS;
            if ($this->hasDisallowedLink($body, $allowedDomains)) {
                return new PriorityVerdict(ConversationPriority::Spam, 'link');
            }

            if ($this->matchesPhrase($normalized, $settings->spam_phrases ?: [])) {
                return new PriorityVerdict(ConversationPriority::Spam, 'phrase');
            }

            // The repeat rule is for the same pasted text over and over, not for answers to the bot
            // (2026-09-22: the owner's Messenger conversation was hidden as spam after «أيوه» three
            // times in a guided flow): a button tap, a message inside a guided flow and a short
            // answer («أيوه», «1047», «تمام») are never repeats.
            $threshold = (int) ($settings->spam_repeat_threshold ?: BotSetting::DEFAULT_SPAM_REPEAT_THRESHOLD);
            if ($normalized !== '' && ! $this->isAnswerToTheBot($inbound, $normalized) && $this->isRepeated($identity, $normalized, $threshold)) {
                return new PriorityVerdict(ConversationPriority::Spam, 'repeat');
            }
        }

        if ($this->isLowValue($inbound, $trimmed, $normalized, $settings->low_value_phrases ?: [])) {
            return new PriorityVerdict(ConversationPriority::Low, 'low_value');
        }

        return new PriorityVerdict(ConversationPriority::Normal, null);
    }

    /**
     * Sets the message flags and updates the conversation priority:
     * spam always wins; low only applies when the conversation is currently
     * normal/low (a manually- or automatically-spam conversation is left alone);
     * normal resets a low conversation but a manually-marked spam conversation
     * stays spam unless the sending identity has since been allowlisted.
     */
    public function apply(Message $inbound, Conversation $c, PriorityVerdict $v): void
    {
        $inbound->forceFill([
            'is_spam' => $v->priority === ConversationPriority::Spam,
            'is_low_value' => $v->priority === ConversationPriority::Low,
        ])->save();

        match ($v->priority) {
            ConversationPriority::Spam => $this->setPriority($c, ConversationPriority::Spam),
            ConversationPriority::Low => $c->priority === ConversationPriority::Spam
                ? null
                : $this->setPriority($c, ConversationPriority::Low),
            ConversationPriority::Normal => $this->applyNormal($inbound, $c),
        };
    }

    /**
     * Manual moderator override (spec §11.1: "not spam" / "important" actions,
     * and the settings screen). Marking a spam conversation back to normal
     * allowlists the sending identity so future automatic classification
     * of that identity never marks it spam again.
     */
    public function setManually(Conversation $c, ConversationPriority $p, User $by): void
    {
        DB::transaction(function () use ($c, $p, $by) {
            $from = $c->priority;

            if ($from === ConversationPriority::Spam && $p === ConversationPriority::Normal) {
                CustomerIdentity::query()
                    ->where('customer_id', $c->customer_id)
                    ->where('platform', $c->platform)
                    ->update(['spam_allowlisted' => true]);
            }

            $c->forceFill(['priority' => $p])->save();

            $this->logger->log(ActorType::User, $by, ActivityLogger::CONVERSATION_PRIORITY_CHANGED, $c, $c, [
                'from' => $from->value,
                'to' => $p->value,
            ]);
        });
    }

    private function applyNormal(Message $inbound, Conversation $c): void
    {
        if ($c->priority !== ConversationPriority::Spam) {
            $this->setPriority($c, ConversationPriority::Normal);

            return;
        }

        $allowlisted = (bool) CustomerIdentity::query()
            ->where('customer_id', $c->customer_id)
            ->where('platform', $inbound->platform)
            ->value('spam_allowlisted');

        if ($allowlisted) {
            $this->setPriority($c, ConversationPriority::Normal);
        }
    }

    private function setPriority(Conversation $c, ConversationPriority $p): void
    {
        if ($c->priority === $p) {
            return;
        }

        $c->forceFill(['priority' => $p])->save();
    }

    /**
     * @param  array<int, string>  $allowedDomains
     */
    private function hasDisallowedLink(string $body, array $allowedDomains): bool
    {
        if (! preg_match_all('/(https?:\/\/\S+|www\.\S+)/i', $body, $matches)) {
            return false;
        }

        // Links to the connected store are always allowed, whatever the owner's list says.
        $allowedDomains = array_merge($allowedDomains, $this->storeDomains());

        foreach ($matches[1] as $url) {
            if (! $this->isAllowedHost($url, $allowedDomains)) {
                return true;
            }
        }

        return false;
    }

    /**
     * The connected Shopify integration's shop domain (myshopify.com) and the store's public
     * domain from bot_settings.store_url.
     *
     * @return array<int, string>
     */
    private function storeDomains(): array
    {
        $integration = $this->integrations->current();
        $domains = $integration !== null && $integration->status === 'connected' && filled($integration->shop_domain)
            ? [(string) $integration->shop_domain]
            : [];

        // The store's own domain (bot_settings.store_url, 2026-09-22): a customer pasting the
        // product she wants in exchange from levoilestores.com is not spam.
        $host = strtolower((string) (parse_url(BotSetting::current()->storeUrl(), PHP_URL_HOST) ?? ''));

        if ($host !== '') {
            $domains[] = preg_replace('/^www\./', '', $host);
        }

        return $domains;
    }

    /**
     * @param  array<int, string>  $allowedDomains
     */
    private function isAllowedHost(string $url, array $allowedDomains): bool
    {
        $normalizedUrl = preg_match('/^https?:\/\//i', $url) ? $url : 'https://'.$url;
        $host = strtolower((string) (parse_url($normalizedUrl, PHP_URL_HOST) ?? ''));

        if ($host === '') {
            return false;
        }

        foreach ($allowedDomains as $domain) {
            $domain = strtolower(trim((string) $domain));
            if ($domain === '') {
                continue;
            }
            if ($host === $domain || str_ends_with($host, '.'.$domain)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<int, string>  $phrases
     */
    private function matchesPhrase(string $normalized, array $phrases): bool
    {
        if ($normalized === '') {
            return false;
        }

        foreach ($phrases as $phrase) {
            $normalizedPhrase = trim($this->normalizer->normalize((string) $phrase));
            if ($normalizedPhrase !== '' && str_contains($normalized, $normalizedPhrase)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Counts identical normalized inbound bodies across every conversation the
     * identity's customer has on this platform (not just the current one), since
     * a spammer's messages may land in more than one open conversation.
     */
    private function isAnswerToTheBot(Message $inbound, string $normalized): bool
    {
        if (filled($inbound->payload) || count(preg_split('/\s+/u', $normalized, -1, PREG_SPLIT_NO_EMPTY) ?: []) <= 2 || mb_strlen($normalized) <= 12) {
            return true;
        }

        // A real guided step (order number, piece, reason…), not a menu that is merely up.
        $conversation = $inbound->conversation;

        return $conversation !== null && FlowState::flow($conversation) !== null && ! app(FlowEngine::class)->atMenu($conversation);
    }

    private function isRepeated(CustomerIdentity $identity, string $normalized, int $threshold): bool
    {
        if ($threshold <= 0) {
            return false;
        }

        $since = CarbonImmutable::now()->subMinutes(self::REPEAT_WINDOW_MINUTES);

        $count = Message::query()
            ->join('conversations', 'conversations.id', '=', 'messages.conversation_id')
            ->where('conversations.customer_id', $identity->customer_id)
            ->where('conversations.platform', $identity->platform->value)
            ->where('messages.direction', MessageDirection::In->value)
            ->where('messages.created_at', '>=', $since)
            ->get(['messages.id as id', 'messages.body as body'])
            ->filter(fn ($m) => trim($this->normalizer->normalize((string) $m->body)) === $normalized)
            ->count();

        return $count >= $threshold;
    }

    /**
     * @param  array<int, string>  $phrases
     */
    private function isLowValue(Message $inbound, string $trimmed, string $normalized, array $phrases): bool
    {
        if ($trimmed === '') {
            $attachments = $inbound->attachments ?? [];

            return count($attachments) === 1 && $this->isSticker($attachments[0]);
        }

        // Emoji/symbols/punctuation only: no letters or digits in any script.
        if (! preg_match('/[\p{L}\p{N}]/u', $trimmed)) {
            return true;
        }

        if (preg_match('/[؟?]/u', $trimmed)) {
            return false;
        }

        $wordCount = count(preg_split('/\s+/u', $normalized, -1, PREG_SPLIT_NO_EMPTY) ?: []);
        if ($wordCount > 3) {
            return false;
        }

        foreach ($phrases as $phrase) {
            $normalizedPhrase = trim($this->normalizer->normalize((string) $phrase));
            if ($normalizedPhrase === '') {
                continue;
            }
            if ($normalized === $normalizedPhrase || str_starts_with($normalized, $normalizedPhrase)) {
                return true;
            }
        }

        return false;
    }

    /**
     * A sticker attachment, as delivered by any of our channels: an explicit
     * `type: sticker` (WhatsApp, once normalized), a Meta `payload.sticker_id`,
     * or a top-level `sticker_id`.
     *
     * @param  array<string, mixed>  $attachment
     */
    private function isSticker(array $attachment): bool
    {
        if (($attachment['type'] ?? null) === 'sticker') {
            return true;
        }

        if (! empty($attachment['payload']['sticker_id'] ?? null)) {
            return true;
        }

        return ! empty($attachment['sticker_id'] ?? null);
    }
}
