<?php

namespace App\Queue\Reports;

use App\Models\QaReview;
use App\Models\QueueEntry;
use App\Models\User;
use App\Queue\Quality\QaService;
use App\Queue\Scoring\ScoreKeeper;

/**
 * One moderator's business day (queue Part 2): her points (total, per kind, the cap and the
 * ledger) and the windows she closed that day (reason, times, rating, QA). Feeds the board's desk
 * drill-in and her own «نقطي». Test and load-test windows are left out.
 */
class QueueDayView
{
    public const WINDOWS_LIMIT = 200;

    public function __construct(private readonly ScoreKeeper $keeper, private readonly QaService $qa) {}

    /** @return array<string, mixed> */
    public function for(User $user, string $date, ?User $viewer = null): array
    {
        $entries = QueueEntry::query()->with(['conversation.customer:id,name', 'qaReview.reviewer:id,name'])
            ->where('assigned_user_id', $user->id)->where('status', 'closed')->where('is_test', false)
            ->whereDate('business_date', $date)
            ->orderByDesc('closed_at')->orderByDesc('id')->limit(self::WINDOWS_LIMIT)->get();

        return [
            'user' => ['id' => $user->id, 'name' => $user->name, 'color' => $user->color],
            'date' => $date,
            'points' => $this->keeper->summary($user->id, $date) + ['ledger' => $this->keeper->ledger($user->id, $date)],
            'windows' => $entries->map(fn (QueueEntry $e) => $this->window($e, $viewer))->values()->all(),
        ];
    }

    /** @return array<string, mixed> */
    private function window(QueueEntry $e, ?User $viewer): array
    {
        $qa = $e->qaReview;

        return [
            'id' => $e->id,
            'ticket' => $e->ticket_no % 100000,
            'conversation_id' => $e->conversation_id,
            'customer' => $e->conversation?->customer?->name,
            'close_reason' => $e->close_reason,
            'closed_at' => $e->closed_at?->toIso8601String(),
            'handle_seconds' => $e->handle_seconds !== null ? (int) $e->handle_seconds : null,
            'first_reply_seconds' => $e->delivered_at !== null && $e->first_reply_at !== null ? max(0, (int) $e->delivered_at->diffInSeconds($e->first_reply_at)) : null,
            'review_stars' => $e->review_stars,
            'qa' => $qa instanceof QaReview ? [
                'score' => $qa->score, 'checklist' => $qa->checklist ?? [], 'notes' => $qa->notes,
                'reviewer' => $qa->reviewer ? ['id' => $qa->reviewer->id, 'name' => $qa->reviewer->name] : null,
            ] : null,
            'can_qa' => $viewer !== null && $this->qa->refusal($e, $viewer) === null,
        ];
    }
}
