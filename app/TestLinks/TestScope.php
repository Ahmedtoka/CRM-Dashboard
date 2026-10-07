<?php

namespace App\TestLinks;

use App\Models\ChannelAccount;
use App\Models\Conversation;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;

/**
 * The one place that answers "is this a team-test row?" (design 2026-09-21 §3/§4).
 *
 * A conversation is a test when its channel account's `driver` is `test`; the flag
 * is copied onto `conversations.is_test` and `messages.is_test` when the rows are
 * written (see Conversation::performInsert()), so the reports and the nightly rollup
 * exclude them with an indexed predicate instead of a join.
 *
 * Bound as a container singleton, i.e. per request and per queued job, so the two
 * lookups below are memoized for the life of that request without ever going stale
 * across deploys or between tests.
 */
class TestScope
{
    public const DRIVER = 'test';

    /** Keeps the memo bounded on a long-running worker. */
    private const MEMO_LIMIT = 2000;

    /** @var array<int, bool> */
    private array $accounts = [];

    /** @var array<int, bool> */
    private array $conversations = [];

    public function accountIsTest(?int $channelAccountId): bool
    {
        if ($channelAccountId === null) {
            return false;
        }

        if (! array_key_exists($channelAccountId, $this->accounts)) {
            // A production load-test channel (2026-10-07) counts as a test too: its chats leave every
            // report like a team test's. Unlike a team test it still gets the rating question and the
            // idle sweep (RatingService, CloseIdleEpisodes) and shows its real state (ConversationResource).
            $this->remember($this->accounts, $channelAccountId, ChannelAccount::query()
                ->whereKey($channelAccountId)
                ->where(fn ($q) => $q->where('driver', self::DRIVER)->orWhere('is_load_test', true))
                ->exists());
        }

        return $this->accounts[$channelAccountId];
    }

    public function conversationIsTest(?int $conversationId): bool
    {
        if ($conversationId === null) {
            return false;
        }

        if (! array_key_exists($conversationId, $this->conversations)) {
            $this->remember($this->conversations, $conversationId, (bool) Conversation::query()
                ->whereKey($conversationId)
                ->value('is_test'));
        }

        return $this->conversations[$conversationId];
    }

    /** Called right after a conversation row is created, so the next message skips the lookup. */
    public function noteConversation(int $conversationId, bool $isTest): void
    {
        $this->remember($this->conversations, $conversationId, $isTest);
    }

    /**
     * Real customer conversations only (alias = the conversations table or its alias): neither a team
     * test link nor a load-test chat. The one helper the reports, ads and Today counts use.
     *
     * @template TBuilder of Builder|QueryBuilder
     *
     * @param  TBuilder  $query
     * @return TBuilder
     */
    public static function realConversations(Builder|QueryBuilder $query, string $alias = 'conversations'): Builder|QueryBuilder
    {
        return $query->where($alias.'.is_test', false);
    }

    /** The load-test channels' ids, as a subquery. */
    public static function loadTestAccountIds(): QueryBuilder
    {
        return ChannelAccount::query()->where('is_load_test', true)->select('id')->toBase();
    }

    /** A subquery of the test conversations' ids, for the analytics exclusions. */
    public static function conversationIds(): Builder
    {
        return Conversation::query()->where('is_test', true)->select('id');
    }

    /**
     * Excludes rows belonging to a test conversation, keeping rows that belong to no
     * conversation at all (a comment activity log, a bot run on a comment). A plain
     * `whereNotIn` would drop those: `NULL NOT IN (…)` is NULL, never true.
     *
     * @template TBuilder of Builder|QueryBuilder
     *
     * @param  TBuilder  $query
     * @return TBuilder
     */
    public static function excludeConversations(Builder|QueryBuilder $query, string $column = 'conversation_id'): Builder|QueryBuilder
    {
        return $query->where(fn ($q) => $q->whereNull($column)->orWhereNotIn($column, self::conversationIds()));
    }

    /**
     * @param  array<int, bool>  $memo
     */
    private function remember(array &$memo, int $key, bool $value): void
    {
        if (count($memo) >= self::MEMO_LIMIT) {
            $memo = [];
        }

        $memo[$key] = $value;
    }
}
