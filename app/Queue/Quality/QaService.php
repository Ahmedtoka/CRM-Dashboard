<?php

namespace App\Queue\Quality;

use App\Models\QaReview;
use App\Models\QueueEntry;
use App\Models\ScoreEvent;
use App\Models\User;
use App\Queue\QueueService;
use App\Queue\Scoring\ScoreKeeper;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * The leader's quality check of a window (queue Part 2, ruling N7): a 1–5 score, the five yes/no
 * items and a note. It pays `(score − 3) × qa_per_point` to the window's assignee on the window's
 * day. One review per window; a re-score with another score takes the old line back and writes
 * the new one, so the ledger shows both; saving the same score again changes no points. Refused (QaRefused) on a window that is not a hand-closed
 * one (inquiry / problem / case), on a test chat, on her own window, and older than yesterday.
 */
class QaService
{
    public const SCORED_REASONS = ['inquiry', 'problem', 'case'];

    public const DAYS_BACK = 1;

    public function __construct(private readonly ScoreKeeper $keeper, private readonly QueueService $queue) {}

    /** Why this reviewer may not score this window, or null when she may. */
    public function refusal(QueueEntry $e, User $reviewer): ?string
    {
        $earliest = CarbonImmutable::parse($this->queue->businessDate(), QueueService::TZ)->subDays(self::DAYS_BACK)->toDateString();

        return match (true) {
            $e->status !== 'closed' || ! in_array($e->close_reason, self::SCORED_REASONS, true) => 'qa_not_closed',
            $e->is_test => 'qa_test',
            $e->assigned_user_id === null || (int) $e->assigned_user_id === (int) $reviewer->id => 'qa_own',
            ($e->business_date?->toDateString() ?? '') < $earliest => 'qa_too_old',
            default => null,
        };
    }

    /**
     * A null checklist or note keeps what an earlier save of this review wrote.
     *
     * @param  array<string, bool>|null  $checklist
     */
    public function save(QueueEntry $e, User $reviewer, int $score, ?array $checklist = null, ?string $notes = null): QaReview
    {
        if (($why = $this->refusal($e, $reviewer)) !== null) {
            throw new QaRefused($why);
        }

        $items = null;
        if ($checklist !== null) {
            $items = [];
            foreach (QaReview::CHECKLIST as $item) {
                $items[$item] = (bool) ($checklist[$item] ?? false);
            }
        }

        $notes = $notes !== null ? (trim($notes) !== '' ? mb_substr(trim($notes), 0, 1000) : '') : null;
        $date = $e->business_date->toDateString();

        $write = fn () => DB::transaction(function () use ($e, $reviewer, $score, $items, $notes, $date) {
            $review = QaReview::query()->where('queue_entry_id', $e->id)->lockForUpdate()->first() ?? new QaReview(['queue_entry_id' => $e->id]);
            $review->forceFill([
                'user_id' => $e->assigned_user_id, 'reviewer_id' => $reviewer->id, 'conversation_id' => $e->conversation_id,
                'business_date' => $date, 'score' => $score,
                'checklist' => $items ?? $review->checklist ?? array_fill_keys(QaReview::CHECKLIST, false),
                'notes' => $notes === null ? $review->notes : ($notes === '' ? null : $notes),
            ])->save();

            return $review;
        }, attempts: 3);

        try {
            $review = $write();
        } catch (UniqueConstraintViolationException) {
            $review = $write(); // two first saves at once: the loser updates the winner's row
        }

        $this->settle($review->id, $e);

        return $review->fresh();
    }

    /**
     * Makes the ledger match the review's score, decided from what stands (not from what changed),
     * so a save whose award failed is paid by the next save, and nothing is paid twice. Under the
     * review's row lock: two saves at once never both write a line.
     */
    private function settle(int $reviewId, QueueEntry $e): void
    {
        DB::transaction(function () use ($reviewId, $e) {
            $review = QaReview::query()->whereKey($reviewId)->lockForUpdate()->firstOrFail();
            $wanted = ($review->score - 3) * $this->queue->settings()->point('qa_per_point');
            $standing = ScoreEvent::query()->where('qa_review_id', $review->id)->where('kind', 'qa')
                ->whereNull('reverses_id')->whereNull('reversed_by_id')->where('points', '!=', 0)->get();

            if (($standing->count() === 1 && $standing->first()->requested === $wanted) || ($standing->isEmpty() && $wanted === 0)) {
                return;
            }

            $ticket = $e->ticket_no % 100000;
            $standing->each(fn (ScoreEvent $l) => $this->keeper->reverse($l, 'qa_replaced', ['ticket' => $ticket]));

            if ($wanted === 0) {
                return;
            }

            $n = ScoreEvent::query()->where('qa_review_id', $review->id)->where('kind', 'qa')->whereNull('reverses_id')->count() + 1;
            $this->keeper->award((int) $review->user_id, 'qa', $wanted, 'qa', ['score' => $review->score, 'ticket' => $ticket], "qa:{$review->id}:{$n}", [
                'queue_entry_id' => $e->id, 'conversation_id' => $e->conversation_id, 'qa_review_id' => $review->id,
            ], $review->business_date);
        }, attempts: 3);
    }
}
