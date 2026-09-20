<?php

namespace App\Bot\Learning;

use App\Enums\SenderType;
use App\Models\BotLearningNote;
use App\Models\Conversation;
use App\Models\Message;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Log;

/**
 * Reviews one finished conversation and stores its notes (learning v2 §2).
 * Shared by the `ReviewConversation` job and the nightly backfill, so the
 * same rules hold for both:
 *
 * - real conversations only (`LearningScope`);
 * - at least 2 customer messages since the previous review;
 * - one review per episode: nothing new since the last review → skipped;
 * - at most `crm.learning.max_reviews_per_day` reviews per Cairo day.
 *
 * A row is written even when the review found nothing (`notes: []`): it marks
 * the messages as reviewed and carries the call's cost.
 */
class ConversationReview
{
    public const MIN_CUSTOMER_MESSAGES = 2;

    public const MAX_NOTES = 5;

    public const MAX_SUMMARY = 200;

    public const MAX_QUOTE = 120;

    public const MAX_AGENT_ANSWER = 300;

    public function __construct(private readonly TranscriptBuilder $transcripts) {}

    /** Why a conversation would not be reviewed now, or null when it would be. */
    public function skipReason(Conversation $conversation): ?string
    {
        if (! LearningScope::includes($conversation)) {
            return 'not a real conversation';
        }

        $lastMessageId = $this->lastMessageId($conversation);

        if ($lastMessageId === null) {
            return 'no messages';
        }

        $previous = $this->previousReviewedId($conversation);

        if ($previous !== null && $previous >= $lastMessageId) {
            return 'already reviewed';
        }

        $customerMessages = Message::query()
            ->where('conversation_id', $conversation->id)
            ->where('sender_type', SenderType::Customer->value)
            ->when($previous !== null, fn ($q) => $q->where('id', '>', $previous))
            ->count();

        if ($customerMessages < self::MIN_CUSTOMER_MESSAGES) {
            return 'fewer than 2 customer messages';
        }

        return null;
    }

    /**
     * Reviews the conversation when it is eligible and the daily cap allows.
     * Reviewer errors (API down, bad JSON) propagate: the job retries once and
     * the nightly backfill picks the conversation up if it still has no notes.
     */
    public function review(Conversation $conversation, ConversationReviewer $reviewer): ?BotLearningNote
    {
        if ($this->skipReason($conversation) !== null) {
            return null;
        }

        if ($this->reviewedToday() >= $this->dailyCap()) {
            Log::info("learning: daily review cap reached, skipping conversation {$conversation->id}", ['cap' => $this->dailyCap()]);

            return null;
        }

        $lastMessageId = (int) $this->lastMessageId($conversation);
        $transcript = $this->transcripts->forConversation($conversation, $this->previousReviewedId($conversation));

        if ($transcript['lines'] === []) {
            return null;
        }

        $result = $reviewer->review($transcript);

        $model = (string) ($result['model'] ?? '');
        $input = (int) ($result['input_tokens'] ?? 0);
        $output = (int) ($result['output_tokens'] ?? 0);

        return BotLearningNote::create([
            'conversation_id' => $conversation->id,
            'channel_account_id' => $conversation->channel_account_id,
            // Where this episode came from (design 2026-09-21 §5): a real customer
            // or one of the team's own runs of a public test link.
            'source' => $conversation->is_test ? BotLearningNote::SOURCE_TEST : BotLearningNote::SOURCE_LIVE,
            'last_message_id' => $lastMessageId,
            'notes' => self::normalize((array) ($result['notes'] ?? [])),
            'model' => $model,
            'input_tokens' => $input,
            'output_tokens' => $output,
            'cost_usd' => self::cost($model, $input, $output),
        ]);
    }

    /** Reviews written today (Cairo) — what the daily cap and the page's chip count. */
    public function reviewedToday(): int
    {
        [$from, $to] = $this->transcripts->window(CarbonImmutable::now(TranscriptBuilder::TZ));

        return BotLearningNote::query()->where('created_at', '>=', $from)->where('created_at', '<', $to)->count();
    }

    public function dailyCap(): int
    {
        return (int) config('crm.learning.max_reviews_per_day', 200);
    }

    /**
     * Keeps only well-formed notes of a known kind, at most 5, with the
     * Arabic texts cut to their limits.
     *
     * @return list<array{kind:string, summary:string, quote:string, agent_answer?:string}>
     */
    public static function normalize(array $notes): array
    {
        $clean = [];

        foreach ($notes as $note) {
            if (count($clean) >= self::MAX_NOTES) {
                break;
            }

            if (! is_array($note) || ! in_array($note['kind'] ?? null, BotLearningNote::KINDS, true)) {
                continue;
            }

            $summary = self::cut($note['summary'] ?? '', self::MAX_SUMMARY);

            if ($summary === '') {
                continue;
            }

            $row = [
                'kind' => (string) $note['kind'],
                'summary' => $summary,
                'quote' => self::cut($note['quote'] ?? '', self::MAX_QUOTE),
            ];

            $answer = self::cut($note['agent_answer'] ?? '', self::MAX_AGENT_ANSWER);

            if ($answer !== '') {
                $row['agent_answer'] = $answer;
            }

            $clean[] = $row;
        }

        return $clean;
    }

    /** USD for one call from `crm.anthropic.prices` (per million tokens); 0 for an unpriced model. */
    public static function cost(string $model, int $inputTokens, int $outputTokens): float
    {
        $price = $model !== '' ? (config('crm.anthropic.prices', [])[$model] ?? null) : null;

        if (! is_array($price)) {
            return 0.0;
        }

        return round(
            ($inputTokens / 1_000_000) * (float) ($price['input'] ?? 0)
            + ($outputTokens / 1_000_000) * (float) ($price['output'] ?? 0),
            4,
        );
    }

    private static function cut(mixed $text, int $max): string
    {
        $text = is_string($text) ? trim($text) : '';

        return mb_strlen($text) > $max ? mb_substr($text, 0, $max) : $text;
    }

    private function lastMessageId(Conversation $conversation): ?int
    {
        $id = Message::query()->where('conversation_id', $conversation->id)->max('id');

        return $id === null ? null : (int) $id;
    }

    private function previousReviewedId(Conversation $conversation): ?int
    {
        $id = BotLearningNote::query()->where('conversation_id', $conversation->id)->max('last_message_id');

        return $id === null ? null : (int) $id;
    }
}
