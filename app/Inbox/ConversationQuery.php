<?php

namespace App\Inbox;

use App\Enums\ConversationPriority;
use App\Enums\ConversationSource;
use App\Enums\ConversationStatus;
use App\Enums\Handler;
use App\Enums\MessageDirection;
use App\Enums\Platform;
use App\Enums\SenderType;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\QueueSetting;
use App\Models\SupportCase;
use App\Models\User;
use Illuminate\Contracts\Pagination\CursorPaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Pagination\Cursor;
use Illuminate\Pagination\CursorPaginator as CursorPaginatorImpl;
use Illuminate\Support\Facades\DB;

/**
 * Inbox list query: platform scoping for moderators plus the list filters
 * (spec §7 Inbox). Shared by the web inbox and API v1.
 */
class ConversationQuery
{
    public const PER_PAGE = 30;

    public const FILTERS = [
        'waiting', 'needs_human', 'bot', 'mine', 'comment', 'ad', 'spam', 'low_priority',
        // Customer order-flag filters (spec §11.2, plan Task 9), joined off `customers`.
        'customer_new', 'customer_repeat', 'open_order', 'has_return', 'stuck_order',
        // Bot handover priority queues (spec §2.1/§2.3 Task 4). queue_senior is restricted to
        // supervisor+ in build(); a moderator asking for it gets no rows, not an error.
        'queue_all', 'queue_high', 'queue_senior',
        // Team test links (design 2026-09-21 §4): the channel filter for the runs.
        'test',
    ];

    /** `status=` values (spec §1.2). `closed` ≡ resolved; waiting/with_moderator/bot are derived states. */
    public const STATUSES = ['open', 'pending', 'resolved', 'waiting', 'with_moderator', 'bot', 'closed'];

    /** `queue=` values: the handover-queue ticket state (spec §1.2). */
    public const QUEUE_STATES = ['waiting', 'window', 'overdue', 'returning'];

    /** Status keys the counts endpoint returns, in display order. */
    public const COUNT_STATUSES = ['open', 'waiting', 'with_moderator', 'bot', 'closed'];

    /** Counts are taken over a LIMIT of this many rows: a value above COUNT_CAP shows as "999+". */
    public const COUNT_LIMIT = 1000;

    public const COUNT_CAP = 999;

    /** Flags that switch to the queue ordering (priority_rank, oldest customer message, id). */
    private const QUEUE_ORDER_FLAGS = ['needs_human', 'queue_all', 'queue_high', 'queue_senior'];

    /** Flags that also hide low-value conversations (spec §11.1). */
    private const LOW_EXCLUDING_FLAGS = ['waiting', 'needs_human', 'queue_all', 'queue_high', 'queue_senior'];

    /**
     * Conversations the user may see: all for supervisor+, own platforms for moderators.
     *
     * @return Builder<Conversation>
     */
    public static function visibleTo(User $u): Builder
    {
        return Conversation::query()->when(
            ! $u->isSupervisorOrAbove(),
            fn (Builder $q) => $q->whereIn('conversations.platform', array_map(fn (Platform $p) => $p->value, $u->platforms())),
        );
    }

    /**
     * @param  array{platform?: ?string, status?: ?string, queue?: ?string, assignee?: string|int|null, flags?: list<string>|string|null, filter?: ?string, q?: ?string, tag?: ?int}  $f
     */
    public function paginate(User $u, array $f): CursorPaginator
    {
        $assignee = $f['assignee'] ?? null;

        if ($assignee === null || $assignee === '' || $assignee === 'none') {
            $q = $this->build($u, $f);
        } else {
            $q = $this->build($u, array_merge($f, ['assignee' => null]));
            $this->assigneePage($q, $u, $f, $assignee === 'me' ? $u->id : (int) $assignee);
        }

        $page = $q->cursorPaginate(self::PER_PAGE)->withQueryString();
        static::completeListRows($page->getCollection());

        return $page;
    }

    /**
     * assignee=<id> for one list page (R3: assigned to her, or unassigned and she replied last).
     * The OR of the two leads MariaDB to an index_merge that fetches and sorts every matching row
     * (12.5k for one moderator on the load dataset, ~200 ms). Instead each side is its own ordered,
     * limited branch (all other filters, the page cursor, the list order, one page + 1) served by
     * conv_assignee_idx / conv_last_responder_idx; the page is then read from at most two pages of
     * ids. The union of each side's first N rows always holds the first N rows of the OR.
     */
    private function assigneePage(Builder $q, User $u, array $f, int $id): void
    {
        $orders = array_values(array_filter($q->getQuery()->orders ?? [], fn ($o) => isset($o['column'])));
        $cursor = CursorPaginatorImpl::resolveCurrentCursor('cursor');
        if ($cursor?->pointsToPreviousItems()) {
            $orders = array_map(fn ($o) => ['column' => $o['column'], 'direction' => $o['direction'] === 'asc' ? 'desc' : 'asc'], $orders);
        }

        $branch = function (callable $side) use ($u, $f, $orders, $cursor) {
            $b = $this->filtered($u, array_merge($f, ['assignee' => null]))->select('conversations.id');
            $side($b);
            if ($cursor !== null) {
                self::applyCursor($b, $orders, $cursor);
            }
            foreach ($orders as $o) {
                $b->orderBy($o['column'], $o['direction']);
            }

            return $b->limit(self::PER_PAGE + 1)->toBase();
        };

        $ids = $branch(fn (Builder $b) => $b->where('conversations.assignee_id', $id))
            ->unionAll($branch(fn (Builder $b) => $b->whereNull('conversations.assignee_id')->where('conversations.last_responder_id', $id)));

        $q->whereIn('conversations.id', DB::query()->fromSub($ids, 'assignee_page')->select('assignee_page.id'));
    }

    /**
     * The cursor condition Laravel's cursorPaginate() adds to the outer query, for a branch query:
     * (c1 > v1) OR (c1 = v1 AND ((c2 > v2) OR (c2 = v2 AND ...))), per the order directions.
     *
     * @param  list<array{column: string, direction: string}>  $orders
     */
    private static function applyCursor(Builder $b, array $orders, Cursor $cursor, int $i = 0): void
    {
        ['column' => $column, 'direction' => $direction] = $orders[$i];

        $b->where(function (Builder $w) use ($orders, $cursor, $i, $column, $direction) {
            $w->where($column, $direction === 'asc' ? '>' : '<', $cursor->parameter($column));
            if ($i < count($orders) - 1) {
                $w->orWhere(function (Builder $x) use ($orders, $cursor, $i, $column) {
                    $x->where($column, '=', $cursor->parameter($column));
                    self::applyCursor($x, $orders, $cursor, $i + 1);
                });
            }
        });
    }

    /**
     * The list query: filters, eager loads and the ordering the cursor pages on.
     *
     * @return Builder<Conversation>
     */
    public function build(User $u, array $f): Builder
    {
        // Plain columns only: paginate() fills the list sub-selects and the user relations for the
        // page afterwards (completeListRows), so a sorted filter never evaluates them per candidate.
        $q = $this->filtered($u, $f)->select('conversations.*')->with(['customer', 'tags', 'queueEntry']);
        $flags = self::flagsOf($f);

        if (($f['status'] ?? null) === 'waiting' || in_array('waiting', $flags, true)) {
            $q->orderBy('conversations.last_customer_message_at')->orderBy('conversations.id');
        } elseif (! empty($f['queue']) || array_intersect(self::QUEUE_ORDER_FLAGS, $flags) !== []) {
            // Priority (high, medium, low), then oldest customer message first (spec §2.3,
            // owner: "بالتوقيت حسب ميعاد الرساله"). priority_rank is a generated column (Task 4a),
            // so the cursor can page on it and conv_queue_rank_idx serves the order.
            $q->orderBy('conversations.priority_rank')
                ->orderBy('conversations.last_customer_message_at')
                ->orderBy('conversations.id');
        } else {
            $q->orderByDesc('conversations.last_message_at')->orderByDesc('conversations.id');
        }

        return $q;
    }

    /**
     * The visible-to query with every filter applied (AND between params), no columns or order:
     * shared by the list and the counts.
     *
     * @return Builder<Conversation>
     */
    public function filtered(User $u, array $f): Builder
    {
        $q = static::visibleTo($u);
        $flags = self::flagsOf($f);
        $status = (string) ($f['status'] ?? '');

        if ($platform = Platform::tryFrom((string) ($f['platform'] ?? ''))) {
            $q->where('conversations.platform', $platform->value);
        }

        match ($status) {
            'open', 'pending', 'resolved' => $q->where('conversations.status', $status),
            'closed' => $q->where('conversations.status', ConversationStatus::Resolved->value),
            'waiting' => $this->waiting($q),
            'bot' => $q->where('conversations.handler', Handler::Bot->value)
                ->where('conversations.status', '!=', ConversationStatus::Resolved->value),
            'with_moderator' => $q->where('conversations.handler', Handler::Human->value)
                ->where('conversations.status', '!=', ConversationStatus::Resolved->value)
                ->where(fn (Builder $w) => $w->whereNotNull('conversations.assignee_id')->orWhereNotNull('conversations.last_responder_id')),
            default => null,
        };

        if (! empty($f['queue'])) {
            $this->queueState($q, (string) $f['queue']);
        }

        if (($assignee = $f['assignee'] ?? null) !== null && $assignee !== '') {
            $this->assignee($q, $assignee === 'me' ? $u->id : $assignee);
        }

        if (! empty($f['tag'])) {
            $q->whereHas('tags', fn (Builder $t) => $t->where('tags.id', (int) $f['tag']));
        }

        if (($term = trim((string) ($f['q'] ?? ''))) !== '') {
            $this->search($q, $term);
        }

        foreach ($flags as $flag) {
            if ($flag === 'waiting' && $status === 'waiting') {
                continue;
            }
            $this->flag($q, $u, $flag);
        }

        // The default inbox (and every filter but "spam") hides spam conversations;
        // "waiting"/"needs_human" and the queue filters additionally exclude low-value ones (spec §11.1).
        if (! in_array('spam', $flags, true)) {
            $q->where('conversations.priority', '!=', ConversationPriority::Spam->value);
        }
        if ($status === 'waiting' || array_intersect(self::LOW_EXCLUDING_FLAGS, $flags) !== []) {
            $q->where('conversations.priority', '!=', ConversationPriority::Low->value);
        }

        return $q;
    }

    /**
     * Per-state counts for the filter bar, each over a LIMIT 1000 sub-select (a value above
     * COUNT_CAP shows as "999+"). `status` and `queue` in $f are ignored: every state is counted
     * under the other params. `queue` is null while the handover queue is off.
     *
     * @return array{status: array<string, int>, queue: ?array<string, int>, capped_at: int}
     */
    public function counts(User $u, array $f): array
    {
        unset($f['status'], $f['queue']);

        $count = fn (array $extra): int => DB::query()
            ->fromSub($this->filtered($u, $f + $extra)->select('conversations.id')->limit(self::COUNT_LIMIT), 'capped')
            ->count();

        $status = [];
        foreach (self::COUNT_STATUSES as $s) {
            $status[$s] = $count(['status' => $s]);
        }

        $queue = null;
        if (QueueSetting::current()->enabled) {
            $queue = [];
            foreach (self::QUEUE_STATES as $s) {
                $queue[$s] = $count(['queue' => $s]);
            }
        }

        return ['status' => $status, 'queue' => $queue, 'capped_at' => self::COUNT_CAP];
    }

    /**
     * `flags` (list or comma string) plus the legacy single `filter`, de-duplicated, in order.
     *
     * @return list<string>
     */
    public static function flagsOf(array $f): array
    {
        $flags = $f['flags'] ?? [];
        if (is_string($flags)) {
            $flags = explode(',', $flags);
        }
        if (! empty($f['filter'])) {
            $flags[] = (string) $f['filter'];
        }

        return array_values(array_unique(array_filter(array_map(fn ($v) => trim((string) $v), (array) $flags), fn ($v) => $v !== '')));
    }

    private function flag(Builder $q, User $u, string $flag): void
    {
        match ($flag) {
            'waiting' => $this->waiting($q),
            'needs_human' => $q->where('conversations.needs_human', true),
            'bot' => $q->where('conversations.handler', Handler::Bot->value),
            'mine' => $this->mine($q, $u),
            'comment' => $q->where('conversations.source', ConversationSource::Comment->value),
            'ad' => $q->where('conversations.source', ConversationSource::Ad->value),
            'spam' => $q->where('conversations.priority', ConversationPriority::Spam->value),
            'low_priority' => $q->where('conversations.priority', ConversationPriority::Low->value),
            'customer_new' => $q->whereHas('customer', fn (Builder $c) => $c
                ->where('is_repeat', false)
                ->where(fn (Builder $w) => $w->where('orders_count', '>', 0)->orWhere('shopify_orders_count', '>', 0))),
            'customer_repeat' => $q->whereHas('customer', fn (Builder $c) => $c->where('is_repeat', true)),
            'open_order' => $q->whereHas('customer', fn (Builder $c) => $c->where('has_open_order', true)),
            'has_return' => $q->whereHas('customer', fn (Builder $c) => $c->where('has_return', true)),
            'stuck_order' => $q->whereHas('customer', fn (Builder $c) => $c->where('has_stuck_order', true)),
            'test' => $q->where('conversations.is_test', true),
            'queue_all' => $q->where('conversations.needs_human', true),
            'queue_high' => $q->where('conversations.needs_human', true)->where('conversations.priority_level', 'high'),
            // Role-restricted (spec §2.1 HandoverRouter, ruling 1): a moderator asking for the
            // senior queue gets no rows rather than an error or the full queue.
            'queue_senior' => $u->isSupervisorOrAbove()
                ? $q->where('conversations.needs_human', true)->where('conversations.queue', 'senior')
                : $q->whereRaw('1 = 0'),
            default => null,
        };
    }

    /**
     * "Mine": first or last responder, or a participant. The ids come from one UNION of three
     * index lookups (first_responder_id, last_responder_id, participants.user_id) read as a
     * derived table, so MariaDB semi-joins it instead of probing an OR chain on every row.
     */
    private function mine(Builder $q, User $u): void
    {
        $ids = DB::query()->select('id')->from('conversations')->where('first_responder_id', $u->id)
            ->union(DB::query()->select('id')->from('conversations')->where('last_responder_id', $u->id))
            ->union(DB::query()->select('conversation_id')->from('conversation_participants')->where('user_id', $u->id));

        $q->whereIn('conversations.id', DB::query()->fromSub($ids, 'mine_ids')->select('mine_ids.id'));
    }

    /**
     * R3: an assigned conversation belongs to its assignee; an unassigned one to its last responder.
     * `none` = neither.
     */
    private function assignee(Builder $q, int|string $id): void
    {
        if ($id === 'none') {
            $q->whereNull('conversations.assignee_id')->whereNull('conversations.last_responder_id');

            return;
        }

        $id = (int) $id;
        $q->where(fn (Builder $w) => $w->where('conversations.assignee_id', $id)
            ->orWhere(fn (Builder $x) => $x->whereNull('conversations.assignee_id')->where('conversations.last_responder_id', $id)));
    }

    /**
     * Her queue ticket state, read off conversations.queue_entry_id. Written as an IN over the
     * (small) set of matching entries rather than a correlated EXISTS, so the planner can start
     * from queue_entries instead of probing every conversation.
     */
    private function queueState(Builder $q, string $state): void
    {
        $open = ['called', 'active'];

        $entries = DB::query()->select('queue_entries.id')->from('queue_entries');
        match ($state) {
            'waiting' => $entries->where('queue_entries.status', 'waiting'),
            'window' => $entries->whereIn('queue_entries.status', $open),
            // Same rule as QueueEntryResource::reply_overdue.
            'overdue' => $entries->whereIn('queue_entries.status', $open)
                ->whereNotNull('queue_entries.awaiting_reply_since')->whereNotNull('queue_entries.apology_sent_at'),
            'returning' => $entries->where('queue_entries.priority', 'returning')
                ->whereIn('queue_entries.status', ['waiting', 'called', 'active']),
            default => $entries->whereRaw('1 = 0'),
        };

        $q->whereIn('conversations.queue_entry_id', $entries);
    }

    /**
     * Customer search. sqlite keeps the substring LIKE. MariaDB/MySQL avoid scanning every
     * conversation's customer with a leading wildcard (spec §1.3 fallback): 4+ digits search the
     * phone by prefix or suffix; text searches the name by prefix or a FULLTEXT word-prefix match.
     * The matching customer ids are a derived-table UNION so the planner starts from customers.
     */
    private function search(Builder $q, string $term): void
    {
        if (! in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true)) {
            $q->whereHas('customer', fn (Builder $c) => $c
                ->where('name', 'like', "%{$term}%")
                ->orWhere('phone', 'like', "%{$term}%"));

            return;
        }

        $like = fn (string $s): string => str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $s);
        $digits = (string) preg_replace('/[\s\-+()]/', '', $term);

        if (preg_match('/^\d{4,}$/', $digits) === 1) {
            // Suffix ("last digits") as a prefix range on the indexed REVERSE(phone) column (Task 4a migration).
            $ids = DB::query()->select('id')->from('customers')->where('phone', 'like', $like($digits).'%')
                ->union(DB::query()->select('id')->from('customers')->where('phone_reversed', 'like', $like(strrev($digits)).'%'));
        } else {
            $ids = DB::query()->select('id')->from('customers')->where('name', 'like', $like($term).'%');
            if (($boolean = self::fulltextTerms($term)) !== null) {
                $ids->union(DB::query()->select('id')->from('customers')->whereFullText('name', $boolean, ['mode' => 'boolean']));
            }
        }

        $q->whereIn('conversations.customer_id', DB::query()->fromSub($ids, 'matched_customers')->select('matched_customers.id'));
    }

    /** "+word* +word*" for a FULLTEXT boolean search, or null when no word is long enough to be indexed. */
    private static function fulltextTerms(string $term): ?string
    {
        $words = preg_split('/[\s+\-<>()~*"@]+/u', $term, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $words = array_filter($words, fn (string $w): bool => mb_strlen($w) >= 3);

        return $words === [] ? null : implode(' ', array_map(fn (string $w): string => '+'.$w.'*', $words));
    }

    /**
     * Adds the eager loads and preview/direction sub-selects ConversationResource uses,
     * so listing does not issue per-row queries.
     *
     * @param  Builder<Conversation>  $q
     * @return Builder<Conversation>
     */
    public static function withListColumns(Builder $q): Builder
    {
        return $q->select('conversations.*')
            ->addSelect(self::listColumnSelects())
            ->with(['customer', 'lockedBy', 'firstResponder', 'lastResponder', 'tags', 'assignee', 'queueEntry']);
    }

    /**
     * Fills a list page after the page query: the preview/direction/handling/open-case values in one
     * query over the page's ids, and the four user relations (lock holder, responders, assignee) in
     * one users query. The page query itself carries no sub-selects: when MariaDB has to sort a
     * filtered set (assignee, search) through a temporary table it would otherwise evaluate every
     * sub-select for every candidate row, not just for the 30 it returns (Task 4a EXPLAIN notes).
     *
     * @param  EloquentCollection<int, Conversation>  $rows
     */
    public static function completeListRows(EloquentCollection $rows): void
    {
        if ($rows->isEmpty()) {
            return;
        }

        $values = Conversation::query()->toBase()
            ->select('conversations.id')
            ->addSelect(self::listColumnSelects())
            ->whereIn('conversations.id', $rows->modelKeys())
            ->get()->keyBy('id');

        $userKeys = ['lockedBy' => 'locked_by_id', 'firstResponder' => 'first_responder_id', 'lastResponder' => 'last_responder_id', 'assignee' => 'assignee_id'];
        $userIds = $rows->flatMap(fn (Conversation $c) => array_map(fn (string $col) => $c->getAttribute($col), $userKeys))
            ->filter()->map(fn ($id) => (int) $id)->unique()->values();
        $users = $userIds->isEmpty() ? collect() : User::query()->whereIn('id', $userIds)->get()->keyBy('id');

        foreach ($rows as $c) {
            $extra = (array) ($values->get($c->getKey()) ?? []);
            unset($extra['id']);
            foreach (['last_message_body', 'last_message_direction', 'last_human_reply_at', 'open_case_id'] as $key) {
                $extra[$key] ??= null;
            }
            $c->setRawAttributes(array_merge($c->getAttributes(), $extra), true);

            foreach ($userKeys as $relation => $col) {
                $id = $c->getAttribute($col);
                $c->setRelation($relation, $id !== null ? $users->get((int) $id) : null);
            }
        }
    }

    /** The list sub-selects ConversationResource reads instead of querying per row. @return array<string, mixed> */
    private static function listColumnSelects(): array
    {
        return [
            'last_message_body' => Message::query()->select('body')
                ->whereColumn('messages.conversation_id', 'conversations.id')
                ->orderByDesc('id')->limit(1),
            'last_message_direction' => Message::query()->select('direction')
                ->whereColumn('messages.conversation_id', 'conversations.id')
                ->where('sender_type', '!=', SenderType::System->value)
                ->orderByDesc('id')->limit(1),
            // Handling indicator fallback (spec §5.4, Task 15): last human reply,
            // so ConversationResource::handling() never issues a per-row query.
            'last_human_reply_at' => Message::query()->select('created_at')
                ->whereColumn('messages.conversation_id', 'conversations.id')
                ->where('sender_type', SenderType::User->value)
                ->orderByDesc('id')->limit(1),
            // Her open support case, for «عندها كيس مفتوح #N» (flow revision §6), no query per row.
            'open_case_id' => SupportCase::openIdSubquery(),
        ];
    }

    /**
     * Open conversations whose latest customer message has no human/bot reply after it.
     */
    private function waiting(Builder $q): void
    {
        $q->where('conversations.status', '!=', ConversationStatus::Resolved->value)
            ->whereNotNull('conversations.last_customer_message_at')
            ->whereNotExists(fn ($s) => $s->selectRaw('1')
                ->from('messages')
                ->whereColumn('messages.conversation_id', 'conversations.id')
                ->where('messages.direction', MessageDirection::Out->value)
                ->whereIn('messages.sender_type', [SenderType::User->value, SenderType::Bot->value])
                ->whereColumn('messages.created_at', '>', 'conversations.last_customer_message_at'));
    }
}
