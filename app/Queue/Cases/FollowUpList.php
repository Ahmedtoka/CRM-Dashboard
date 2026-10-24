<?php

namespace App\Queue\Cases;

use App\Enums\MessageDirection;
use App\Enums\SenderType;
use App\Http\Support\ModeratorScope;
use App\Models\Conversation;
use App\Models\ConversationNote;
use App\Models\Message;
use App\Models\QueueEntry;
use App\Models\SupportCase;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * «المتابعة»: every open case (`new`, `in_progress`) the viewer may see, with what the leader needs to
 * chase it — who owns it, whether the customer is waiting for an answer, how the SLA stands, her last
 * words and the team's last note — and the one next action the rules below pick. Test chats and test
 * windows never appear. The whole open set is loaded once (a few hundred rows at most), so the tile
 * counts come from the full picture and the filters narrow the rows in memory: six queries whatever
 * the number of cases.
 */
class FollowUpList
{
    public const NEXT_ACTIONS = ['assign', 'reply', 'overdue', 'resolve_check'];

    /**
     * @param  array{type?: ?string, owner?: int|string|null, overdue?: mixed, mine?: mixed}  $filters
     * @return array{rows: list<array<string, mixed>>, counts: array{open: int, overdue: int, unassigned: int, mine: int}}
     */
    public function build(User $viewer, array $filters = []): array
    {
        $cases = $this->openCases($viewer);
        $conversationIds = $cases->pluck('conversation_id')->unique()->values();

        $lastInbound = $this->lastInboundByConversation($conversationIds);
        $lastStaffAt = $this->lastStaffMessageAtByConversation($conversationIds);
        $lastNotes = $this->lastNoteByConversation($conversationIds);

        // The owner is the window's moderator, else the opener (CaseResolution::payeeOf): her name comes in one query.
        $ownerIds = $cases->map(fn (SupportCase $c) => CaseResolution::payeeOf($c))->filter()->unique()->values();
        $owners = $ownerIds->isEmpty() ? collect() : User::query()->whereIn('id', $ownerIds)->get(['id', 'name'])->keyBy('id');

        $rows = $cases->map(fn (SupportCase $c) => $this->row($c, $owners, $lastInbound, $lastStaffAt, $lastNotes));

        $counts = [
            'open' => $rows->count(),
            'overdue' => $rows->where('sla.state', 'overdue')->count(),
            'unassigned' => $rows->filter(fn (array $r) => $r['owner'] === null && $r['assigned_to'] === null)->count(),
            'mine' => $rows->filter(fn (array $r) => $this->belongsTo($r, $viewer->id))->count(),
        ];

        return ['rows' => $this->sort($this->filter($rows, $filters, $viewer))->values()->all(), 'counts' => $counts];
    }

    /** @return Collection<int, SupportCase> */
    private function openCases(User $viewer): Collection
    {
        return SupportCase::query()
            ->tap(fn (Builder $q) => ModeratorScope::cases($q, $viewer))
            ->whereIn('status', SupportCase::OPEN_STATUSES)
            // Same exclusions as the SLA sweep: a test window's case and a test chat's case are nobody's work.
            ->where(fn (Builder $q) => $q->whereNull('queue_entry_id')->orWhereNotIn('queue_entry_id', QueueEntry::query()->where('is_test', true)->select('id')))
            ->whereNotIn('conversation_id', Conversation::query()->where('is_test', true)->select('id'))
            ->with(['customer:id,name,phone', 'assignedTo:id,name', 'queueEntry:id,assigned_user_id,is_test', 'conversation:id,last_customer_message_at'])
            ->orderBy('id')
            ->get();
    }

    /**
     * Her latest message per conversation (the newest inbound row), one query via a max(id) sub-select.
     *
     * @param  Collection<int, int>  $conversationIds
     * @return Collection<int, Message>
     */
    private function lastInboundByConversation(Collection $conversationIds): Collection
    {
        if ($conversationIds->isEmpty()) {
            return collect();
        }

        $latest = Message::query()->selectRaw('max(id)')
            ->whereIn('conversation_id', $conversationIds)->where('direction', MessageDirection::In->value)
            ->groupBy('conversation_id');

        return Message::query()->whereIn('id', $latest)->get(['id', 'conversation_id', 'body', 'created_at'])->keyBy('conversation_id');
    }

    /**
     * When staff last wrote to her in each conversation (the bot's replies do not count: a waiting
     * customer still needs a person).
     *
     * @param  Collection<int, int>  $conversationIds
     * @return Collection<int, string> conversation id => timestamp
     */
    private function lastStaffMessageAtByConversation(Collection $conversationIds): Collection
    {
        if ($conversationIds->isEmpty()) {
            return collect();
        }

        return Message::query()->selectRaw('conversation_id, max(created_at) as last_at')
            ->whereIn('conversation_id', $conversationIds)
            ->where('direction', MessageDirection::Out->value)->where('sender_type', SenderType::User->value)
            ->groupBy('conversation_id')->pluck('last_at', 'conversation_id');
    }

    /**
     * The newest team note per conversation, with its author.
     *
     * @param  Collection<int, int>  $conversationIds
     * @return Collection<int, ConversationNote>
     */
    private function lastNoteByConversation(Collection $conversationIds): Collection
    {
        if ($conversationIds->isEmpty()) {
            return collect();
        }

        $latest = ConversationNote::query()->selectRaw('max(id)')->whereIn('conversation_id', $conversationIds)->groupBy('conversation_id');

        return ConversationNote::query()->whereIn('id', $latest)->with('user:id,name')->get()->keyBy('conversation_id');
    }

    /**
     * @param  Collection<int, User>  $owners
     * @param  Collection<int, Message>  $lastInbound
     * @param  Collection<int, string>  $lastStaffAt
     * @param  Collection<int, ConversationNote>  $lastNotes
     * @return array<string, mixed>
     */
    private function row(SupportCase $case, Collection $owners, Collection $lastInbound, Collection $lastStaffAt, Collection $lastNotes): array
    {
        $ownerId = CaseResolution::payeeOf($case);
        $owner = $ownerId !== null ? $owners->get($ownerId) : null;
        $assignee = $case->assigned_to_id !== null ? $case->assignedTo : null;
        $customer = $case->customer_id !== null ? $case->customer : null;
        $inbound = $lastInbound->get($case->conversation_id);
        $note = $lastNotes->get($case->conversation_id);
        $sla = CaseSla::of($case);

        $customerAt = $case->conversation?->last_customer_message_at;
        $staffAt = collect([$lastStaffAt->get($case->conversation_id), $note?->created_at])->filter()
            ->map(fn ($t) => Carbon::parse($t))->max();

        return [
            'id' => $case->id,
            'type' => $case->type,
            'type_label' => $case->typeLabel(),
            'status' => $case->status,
            'order_id' => $case->order_id,
            'order_number' => $case->order_number,
            'conversation_id' => $case->conversation_id,
            'customer' => $customer ? ['id' => $customer->id, 'name' => $customer->name, 'phone' => $customer->phone] : null,
            'owner' => $owner ? ['id' => $owner->id, 'name' => $owner->name] : null,
            'assigned_to' => $assignee ? ['id' => $assignee->id, 'name' => $assignee->name] : null,
            'opened_at' => $case->created_at?->toIso8601String(),
            'sla' => $sla,
            'last_customer_message' => $inbound?->body !== null ? Str::limit(trim((string) $inbound->body), 120, '') : null,
            'last_customer_message_at' => $customerAt?->toIso8601String(),
            'last_note' => $note ? [
                'body' => $note->body,
                'author' => $note->user ? ['id' => $note->user->id, 'name' => $note->user->name] : null,
                'at' => $note->created_at?->toIso8601String(),
            ] : null,
            'next_action' => $this->nextAction($owner !== null || $assignee !== null, $customerAt, $staffAt, $sla['state'] === 'overdue'),
        ];
    }

    /**
     * The one thing to do next, first rule that fits: nobody has it → assign; she wrote after the last
     * staff word (message or note) → reply; the SLA passed → overdue; else wait for the solution.
     */
    private function nextAction(bool $owned, ?CarbonInterface $customerAt, ?CarbonInterface $staffAt, bool $overdue): string
    {
        return match (true) {
            ! $owned => 'assign',
            $customerAt !== null && ($staffAt === null || $customerAt->gt($staffAt)) => 'reply',
            $overdue => 'overdue',
            default => 'resolve_check',
        };
    }

    /** @param  array<string, mixed>  $row */
    private function belongsTo(array $row, int $userId): bool
    {
        return ($row['owner']['id'] ?? null) === $userId || ($row['assigned_to']['id'] ?? null) === $userId;
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $rows
     * @param  array{type?: ?string, owner?: int|string|null, overdue?: mixed, mine?: mixed}  $filters
     * @return Collection<int, array<string, mixed>>
     */
    private function filter(Collection $rows, array $filters, User $viewer): Collection
    {
        $owner = isset($filters['owner']) && $filters['owner'] !== '' ? (int) $filters['owner'] : null;

        return $rows
            ->when($filters['type'] ?? null, fn ($r, $type) => $r->where('type', $type))
            ->when($owner !== null, fn ($r) => $r->filter(fn (array $row) => $this->belongsTo($row, $owner)))
            ->when(! empty($filters['overdue']), fn ($r) => $r->where('sla.state', 'overdue'))
            ->when(! empty($filters['mine']), fn ($r) => $r->filter(fn (array $row) => $this->belongsTo($row, $viewer->id)));
    }

    /**
     * Overdue first, then the nearest due time (no SLA last), then the oldest case.
     *
     * @param  Collection<int, array<string, mixed>>  $rows
     * @return Collection<int, array<string, mixed>>
     */
    private function sort(Collection $rows): Collection
    {
        return $rows->sortBy([
            fn (array $a, array $b) => ($a['sla']['state'] === 'overdue' ? 0 : 1) <=> ($b['sla']['state'] === 'overdue' ? 0 : 1),
            fn (array $a, array $b) => ($a['sla']['due_at'] ?? '9999') <=> ($b['sla']['due_at'] ?? '9999'),
            fn (array $a, array $b) => ($a['opened_at'] ?? '') <=> ($b['opened_at'] ?? ''),
            fn (array $a, array $b) => $a['id'] <=> $b['id'],
        ]);
    }
}
