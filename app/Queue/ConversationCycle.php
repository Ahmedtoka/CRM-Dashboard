<?php

namespace App\Queue;

use App\Analytics\ActivityLogger;
use App\Bot\BotEngine;
use App\Bot\Learning\Jobs\ReviewConversation;
use App\Enums\ActorType;
use App\Enums\ConversationStatus;
use App\Enums\Handler;
use App\Enums\MessageDirection;
use App\Enums\SenderType;
use App\Events\ConversationUpdated;
use App\Inbox\Outcomes\EpisodeEnd;
use App\Inbox\Outcomes\Outcome;
use App\Inbox\Outcomes\OutcomeRecorder;
use App\Models\Conversation;
use App\Models\ConversationOutcome;
use App\Models\Message;
use App\Models\QueueEntry;
use App\Models\QueueSetting;
use App\Models\SupportCase;
use App\Models\User;
use App\Queue\Events\QueueEntryUpdated;
use App\Queue\Events\QueueMemberUpdated;
use App\Queue\Jobs\SendClosingMessage;
use App\Queue\Jobs\SendQueueMessage;
use App\Queue\Scoring\ScoreKeeper;
use App\Support\SafeBroadcast;
use Illuminate\Database\DetectsConcurrencyErrors;
use Illuminate\Database\DetectsLostConnections;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * The one place a person ends a conversation: «خلصت» (docs/round-2026-10-17/close-cycle-FINAL.md
 * §4). The web, the API, the mobile app's old endpoints (wrappers) all come here; the queue entry
 * itself is still closed by `WindowLifecycle::closeLocked()`.
 *
 * Paths, decided under the conversation lock (then the entry, the queue's lock order):
 *  - an open window: a final close (`close_reason` inquiry / problem / case, `end_mode` thanks or
 *    bot), the one closing message (thanks + rating) when asked for, back to the bot with the
 *    return window of Q8 (thanks) or none (bot);
 *  - a customer still waiting in the lounge: `cancelled`, back to the bot, the short Q15 message;
 *  - a person handles the chat with no window (the queue off, or a claim off shift): the episode
 *    ends, back to the bot, the thanks alone (Q14);
 *  - the tick auto-closed her window seconds ago: her outcome is written on that episode (H-09).
 *
 * `undo()` («تراجع») and `reviveLocked()` (H-08) put a just-closed window back as it was.
 */
class ConversationCycle
{
    use DetectsConcurrencyErrors;
    use DetectsLostConnections;

    /** «تراجع» is taken this long after «خلصت» (the closing message waits longer: SendClosingMessage::DELAY_SECONDS). */
    public const UNDO_SECONDS = 15;

    /** «خلصت» this soon after the tick's auto-close writes her outcome on that episode (H-09). */
    public const AMEND_SECONDS = 60;

    /** The assignee's reply this soon after the tick's auto-close takes the same window back (H-08). */
    public const REVIVE_SECONDS = 120;

    public function __construct(
        private readonly WindowLifecycle $windows,
        private readonly OutcomeRecorder $outcomes,
        private readonly ActivityLogger $logger,
    ) {}

    /**
     * `$r`: outcome (?Outcome), outcome_note (?string), after ('thanks' | 'bot'), send_closing (?bool:
     * null = yes unless she waits for a reply), confirm_waiting (bool), open_case (?string, a case
     * type), resolve_case_id (?int); for the wrappers: reason (forced close_reason), case_type,
     * link_case (an open case is linked instead of `case_exists`), default_outcome (?Outcome, when
     * nothing was picked and nothing is automatic), lounge_message (bool, Q15), closing_without_window
     * (bool, Q14), no_window_to_bot (bool).
     *
     * @param  array<string, mixed>  $r
     * @return array{path: string, entry: ?QueueEntry, end_mode: ?string, closing: string, skipped_reason: ?string, support_case_id: ?int, undo_until: ?string}
     *
     * @throws FinishRefused
     */
    public function finish(Conversation $c, User $by, array $r): array
    {
        $result = DB::transaction(function () use ($c, $by, $r) {
            $row = Conversation::query()->lockForUpdate()->find($c->id);

            if ($row === null) {
                throw new FinishRefused('already_closed', 409);
            }

            $open = QueueEntry::query()->where('conversation_id', $row->id)->whereIn('status', ['waiting', 'called', 'active'])
                ->latest('id')->lockForUpdate()->first();

            // RC-1: the entry the moderator was looking at (`entry_id`, null = no window) must still be
            // the conversation's — never a stale «خلصت» on the request she made since.
            $checked = array_key_exists('entry_id', $r);
            $expected = $checked && $r['entry_id'] !== null ? (int) $r['entry_id'] : null;

            if ($checked && $open !== null && (int) $open->id !== $expected) {
                throw new FinishRefused('stale_state', 409, ['entry_id' => $open->id]);
            }

            if ($open !== null && $open->isOpen()) {
                $open->setRelation('conversation', $row);

                return $this->finishWindow($row, $open, $by, $r);
            }

            if ($open !== null) {
                $open->setRelation('conversation', $row);

                return $this->finishLounge($row, $open, $by, $r);
            }

            if (! ($r['no_amend'] ?? false) && ($amended = $this->amendAfterAuto($row, $by, $r, $expected))) {
                return $amended;
            }

            if ($checked && $expected !== null) {
                $last = QueueEntry::query()->where('conversation_id', $row->id)->latest('id')->first();

                throw new FinishRefused('already_closed', 409, ['entry_id' => $last?->id]);
            }

            if ($row->handler === Handler::Human) {
                return $this->finishNoWindow($row, $by, $r);
            }

            $last = QueueEntry::query()->where('conversation_id', $row->id)->latest('id')->first();

            throw new FinishRefused('already_closed', 409, ['entry_id' => $last?->id]);
        }, attempts: 3);

        $c->refresh();
        rescue(fn () => ReviewConversation::dispatchFor($c), null, true);

        return $result;
    }

    /** @return array<string, mixed> */
    private function finishWindow(Conversation $c, QueueEntry $e, User $by, array $r): array
    {
        if ((int) $e->assigned_user_id !== (int) $by->id && ! $by->isSupervisorOrAbove()) {
            throw new FinishRefused('not_your_window', 403);
        }

        $outcome = $this->outcomeOf($c, $e, $r);
        $waiting = $e->awaiting_reply_since !== null;

        // RC-17: «البوت يكمّل» says nothing to her, so her waiting is the bot's to answer.
        if ($waiting && ($r['after'] ?? 'thanks') !== 'bot' && ! ($r['confirm_waiting'] ?? false)) {
            throw new FinishRefused('customer_waiting', 409, ['last_message' => $this->lastWords($c)]);
        }

        $after = ($r['after'] ?? 'thanks') === 'bot' ? 'bot' : 'thanks';
        $openCase = isset($r['open_case']) && in_array($r['open_case'], SupportCase::TYPES, true) ? $r['open_case'] : null;
        $existing = app(QueueService::class)->openCaseFor($c);

        if ($openCase !== null && $existing !== null && ! ($r['link_case'] ?? false)) {
            throw new FinishRefused('case_exists', 422, ['case_id' => $existing->id]);
        }

        $reason = $r['reason'] ?? null;
        if (! in_array($reason, ['inquiry', 'problem', 'case'], true)) {
            $reason = $openCase !== null ? 'case' : $this->kindOf($e, $c, $outcome);
        }

        // Q1 / R2: the closing message by default, but not to a customer still waiting for a reply
        // unless the moderator chose «ابعتي الشكر برضه».
        $send = $after === 'thanks' && ($r['send_closing'] ?? ! $waiting) === true;

        $this->windows->closeLocked($e, $reason, $by, [
            'case_type' => $openCase ?? ($r['case_type'] ?? null), 'outcome' => $outcome, 'outcome_note' => $r['outcome_note'] ?? null,
            'to_bot' => $after === 'bot', 'end_mode' => $after, 'closing' => $send,
            'skip_reason' => $send || $after === 'bot' ? null : ($waiting ? 'waiting' : 'not_asked'),
        ]);

        $this->markResolved($c, $by);

        if (isset($r['resolve_case_id'])) {
            $this->resolveCase((int) $r['resolve_case_id'], $c, $by);
        }

        $this->logger->log(ActorType::User, $by, ActivityLogger::CONVERSATION_RESOLVED, null, $c, ['finish' => $after, 'entry_id' => $e->id]);
        $e->refresh();

        return [
            'path' => 'window', 'entry' => $e, 'end_mode' => $e->end_mode, 'closing' => $send ? 'queued' : 'skipped',
            'skipped_reason' => $send ? null : $e->closing_skipped_reason, 'support_case_id' => $e->support_case_id,
            'undo_until' => $e->closed_at?->copy()->addSeconds(self::UNDO_SECONDS)->toIso8601String(),
        ];
    }

    /** @return array<string, mixed> */
    private function finishLounge(Conversation $c, QueueEntry $e, User $by, array $r): array
    {
        $outcome = $r['outcome'] ?? null;
        $closed = $this->windows->close($e, 'cancelled', $by);
        $c->refresh();
        $this->endEpisode($c, $closed, $outcome instanceof Outcome ? $outcome : null, $r['outcome_note'] ?? null, $by, EpisodeEnd::Resolve);

        $c->return_priority_until = null;
        app(BotEngine::class)->resetToBot($c);
        $this->markResolved($c, $by);

        $this->logger->log(ActorType::User, $by, ActivityLogger::CONVERSATION_RESOLVED, null, $c, ['finish' => 'lounge', 'entry_id' => $closed->id]);
        $tell = (bool) ($r['lounge_message'] ?? false) && $closed->status === 'cancelled';
        if ($tell) {
            SendQueueMessage::dispatch($closed->id, 'queue_ticket_cancelled', []);
        }

        return ['path' => 'lounge', 'entry' => $closed, 'end_mode' => null, 'closing' => $tell ? 'queued' : 'skipped', 'skipped_reason' => $tell ? null : 'lounge', 'support_case_id' => null, 'undo_until' => null];
    }

    /** @return array<string, mixed> */
    private function finishNoWindow(Conversation $c, User $by, array $r): array
    {
        $outcome = $this->outcomeOf($c, null, $r);
        $waiting = $this->customerWaiting($c);

        if ($waiting && ! ($r['confirm_waiting'] ?? false)) {
            throw new FinishRefused('customer_waiting', 409, ['last_message' => $this->lastWords($c)]);
        }

        $this->endEpisode($c, null, $outcome, $r['outcome_note'] ?? null, $by, EpisodeEnd::Close);

        $after = ($r['after'] ?? 'thanks') === 'bot' ? 'bot' : 'thanks';
        $toBot = (bool) ($r['no_window_to_bot'] ?? true);
        $c->forceFill(['assignee_id' => null, 'assigned_at' => null, 'return_priority_until' => null])->save();

        if ($toBot) {
            app(BotEngine::class)->resetToBot($c);
        }

        $this->markResolved($c, $by);

        $send = $after === 'thanks' && (bool) ($r['closing_without_window'] ?? false) && ($r['send_closing'] ?? ! $waiting) === true;

        if ($send) {
            SendClosingMessage::dispatch(null, $c->id, now()->toIso8601String(), false)->delay(now()->addSeconds(SendClosingMessage::DELAY_SECONDS));
        }

        $this->logger->log(ActorType::User, $by, ActivityLogger::CONVERSATION_RESOLVED, null, $c, ['finish' => $after]);
        DB::afterCommit(fn () => SafeBroadcast::send(new ConversationUpdated($c->fresh())));

        return ['path' => 'none', 'entry' => null, 'end_mode' => $after, 'closing' => $send ? 'queued' : 'skipped', 'skipped_reason' => $send ? null : ($waiting ? 'waiting' : 'not_asked'), 'support_case_id' => null, 'undo_until' => null];
    }

    /**
     * H-09: the tick auto-closed her window (nobody pressed anything) less than AMEND_SECONDS ago and
     * her «خلصت» arrives now with an outcome: written on that episode, no closing message. Null when
     * this is not that case.
     *
     * @return array<string, mixed>|null
     */
    private function amendAfterAuto(Conversation $c, User $by, array $r, ?int $expected): ?array
    {
        $last = QueueEntry::query()->where('conversation_id', $c->id)->latest('id')->lockForUpdate()->first();
        $outcome = $r['outcome'] ?? null;

        if (! $outcome instanceof Outcome || ! self::amendable($last, $by, self::AMEND_SECONDS) || ($expected !== null && (int) $last->id !== $expected)) {
            return null;
        }

        $this->outcomes->amend($last, $outcome, $r['outcome_note'] ?? null, $by);
        DB::afterCommit(fn () => SafeBroadcast::send(new ConversationUpdated($c->fresh())));

        return ['path' => 'amend', 'entry' => $last, 'end_mode' => $last->end_mode, 'closing' => 'skipped', 'skipped_reason' => 'auto_closed', 'support_case_id' => null, 'undo_until' => null];
    }

    /** Her auto-closed window (the tick's, nobody's hand) within `$seconds`, hers or a supervisor's to fix. */
    public static function amendable(?QueueEntry $e, User $by, int $seconds): bool
    {
        return $e !== null && $e->status === 'closed' && $e->close_reason === 'auto' && $e->closed_by_id === null
            && $e->closed_at !== null && $e->closed_at->greaterThan(now()->subSeconds($seconds))
            && ((int) $e->assigned_user_id === (int) $by->id || $by->isSupervisorOrAbove());
    }

    /**
     * Q6: the outcome of her silently auto-closed window, set later by its moderator (or a supervisor)
     * within a day while it is still `unknown`.
     *
     * @throws FinishRefused
     */
    public function amendOutcome(Conversation $c, User $by, Outcome $outcome, ?string $note): QueueEntry
    {
        return DB::transaction(function () use ($c, $by, $outcome, $note) {
            Conversation::query()->whereKey($c->id)->lockForUpdate()->first(['id']);
            $last = QueueEntry::query()->where('conversation_id', $c->id)->where('close_reason', 'auto')->latest('id')->lockForUpdate()->first();

            if (! self::amendable($last, $by, 86400) || $this->outcomes->rowOf($last)?->outcome !== Outcome::Unknown->value) {
                throw new FinishRefused('not_amendable', 409);
            }

            $this->outcomes->amend($last, $outcome, $note, $by);

            return $last;
        }, attempts: 3);
    }

    /**
     * «تراجع»: the window she closed seconds ago is open again as it was — before the closing message
     * went out and before the customer wrote. Refused (`undo_expired`) otherwise.
     *
     * @throws FinishRefused
     */
    public function undo(Conversation $c, User $by): QueueEntry
    {
        return DB::transaction(function () use ($c, $by) {
            $row = Conversation::query()->lockForUpdate()->find($c->id);
            $e = $row ? QueueEntry::query()->where('conversation_id', $row->id)->latest('id')->lockForUpdate()->first() : null;

            if ($row === null || $e === null || ! $this->undoable($e, $by)) {
                throw new FinishRefused('undo_expired', 409);
            }

            $e->setRelation('conversation', $row);
            $this->reviveLocked($e, 'undone');

            return $e->fresh();
        }, attempts: 3);
    }

    /** RC-2: her desk still serves (not on a break, not gone); a manual window with no desk is fine. */
    private static function deskServing(QueueEntry $e): bool
    {
        $desk = $e->member;

        return $desk === null || in_array($desk->status, ['available', 'busy'], true);
    }

    private function undoable(QueueEntry $e, User $by): bool
    {
        return $e->status === 'closed' && $e->end_mode !== null && $e->end_mode !== 'silent'
            && $e->closed_at !== null && $e->closed_at->greaterThan(now()->subSeconds(self::UNDO_SECONDS))
            && ((int) $e->closed_by_id === (int) $by->id || $by->isSupervisorOrAbove())
            && $e->closing_claimed_at === null && $e->review_requested_at === null
            && ! AfterCloseGuard::customerWroteSince($e)
            && self::deskServing($e);
    }

    /**
     * Puts a just-closed window back (undo, or the assignee's reply seconds after the tick's
     * auto-close, H-08): the entry is active again on her desk with its ticket and window number,
     * the chat is hers (human), its ended episode is open again (an order) or gone, the window's
     * base and speed points are taken back, a case the close opened is removed. A queued closing
     * message finds the entry open and says nothing. Call with both locks held.
     */
    public function reviveLocked(QueueEntry $e, string $why): void
    {
        $c = $e->conversation;
        $closedAt = $e->closed_at;
        $case = $e->support_case_id !== null ? SupportCase::query()->find($e->support_case_id) : null;
        $newCase = $case !== null && (int) $case->queue_entry_id === (int) $e->id && $closedAt !== null && $case->created_at?->greaterThanOrEqualTo($closedAt->copy()->subSecond());

        // RC-2: the router may have given her freed window number to the next customer meanwhile.
        $used = app(QueueRouter::class)->openForUser((int) $e->assigned_user_id)->whereKeyNot($e->id)->whereNotNull('window_no')->pluck('window_no')->map(fn ($w) => (int) $w)->all();
        $window = (int) ($e->window_no ?? 1);
        if (in_array($window, $used, true)) {
            $window = 1;
            while (in_array($window, $used, true)) {
                $window++;
            }
        }

        $e->forceFill([
            'window_no' => $window,
            'status' => 'active', 'close_reason' => null, 'end_mode' => null, 'closed_at' => null, 'closed_by_id' => null, 'handle_seconds' => null,
            'return_priority_until' => null, 'closing_skipped_reason' => null, 'confirmed_at' => null,
            'support_case_id' => $newCase ? null : $e->support_case_id,
        ])->save();

        if ($newCase) {
            $case->delete();
        }

        $c?->forceFill([
            'handler' => Handler::Human, 'needs_human' => false, 'assignee_id' => $e->assigned_user_id, 'assigned_at' => now(), 'queue_entry_id' => $e->id,
            'status' => ConversationStatus::Open, 'resolved_at' => null, 'resolved_by_id' => null, 'return_priority_until' => null,
        ])->save();

        if ($c !== null && $closedAt !== null) {
            $this->outcomes->reopenEpisode($c, $e, $closedAt);
        }

        $m = $e->member;
        if ($m !== null && $m->status === 'available') {
            $m->update(['status' => 'busy']);
        }

        $id = (int) $e->id;
        DB::afterCommit(fn () => rescue(fn () => app(ScoreKeeper::class)->reverseWhere('queue_entry_id', $id, ['base', 'speed'], $why), null, true));
        $this->logger->log(ActorType::System, null, ActivityLogger::QUEUE_CLOSE, null, $c, ['ticket' => $e->ticket_no, 'reason' => $why, 'revived' => true]);

        DB::afterCommit(function () use ($e, $c, $m) {
            SafeBroadcast::send(new QueueEntryUpdated($e->fresh()));
            if ($c !== null) {
                SafeBroadcast::send(new ConversationUpdated($c->fresh()));
            }
            if ($m !== null) {
                SafeBroadcast::send(new QueueMemberUpdated($m->fresh()));
            }
        });
    }

    /**
     * H-08, from a staff reply (inside the send's transaction, the conversation locked): the
     * assignee answers seconds after the tick auto-closed her window → the same window again.
     * True when it revived.
     */
    public function reviveOnReply(Conversation $c, User $u): bool
    {
        $last = QueueEntry::query()->where('conversation_id', $c->id)->latest('id')->lockForUpdate()->first();

        if ($last === null || $last->status !== 'closed' || $last->close_reason !== 'auto' || $last->closed_by_id !== null
            || (int) $last->assigned_user_id !== (int) $u->id || $last->closed_at === null
            || $last->closed_at->lessThanOrEqualTo(now()->subSeconds(self::REVIVE_SECONDS)) || ! self::deskServing($last)) {
            return false;
        }

        $last->setRelation('conversation', $c);
        $this->reviveLocked($last, 'revived');

        return true;
    }

    /** The outcome to record: hers, else the automatic one, else the wrapper's default; refused when none. */
    private function outcomeOf(Conversation $c, ?QueueEntry $e, array $r): ?Outcome
    {
        $picked = $r['outcome'] ?? null;

        if ($picked instanceof Outcome) {
            return $picked;
        }

        if ($this->outcomes->autoOutcome($c, $e) !== null) {
            return null;
        }

        $default = $r['default_outcome'] ?? null;

        if ($default instanceof Outcome) {
            return $default;
        }

        throw new FinishRefused('outcome_required', 422);
    }

    /** D-15: the kind is the outcome's — a service chat is a problem, anything else an inquiry. */
    private function kindOf(QueueEntry $e, Conversation $c, ?Outcome $outcome): string
    {
        if ($outcome === Outcome::Service) {
            return 'problem';
        }

        if ($outcome === null && $this->outcomes->autoOutcome($c, $e) === Outcome::Service) {
            return 'problem';
        }

        $category = is_array($e->bot_summary) ? ($e->bot_summary['category'] ?? null) : null;

        return $outcome === null && is_string($category) && in_array($category, OutcomeRecorder::SERVICE_CATEGORIES, true) ? 'problem' : 'inquiry';
    }

    /** Her last real message is the last word and nobody answered it (no window: H-15's warning). */
    private function customerWaiting(Conversation $c): bool
    {
        $last = Message::query()->where('conversation_id', $c->id)->where('sender_type', '!=', SenderType::System->value)
            ->where('is_spam', false)->latest('id')->first(['id', 'direction', 'body', 'attachments', 'payload']);

        return $last !== null && $last->direction === MessageDirection::In && ! app(Acknowledgement::class)->matches($last);
    }

    /** Her last message for the warning, 120 characters at most. */
    public function lastWords(Conversation $c): ?string
    {
        $body = Message::query()->where('conversation_id', $c->id)->where('direction', MessageDirection::In->value)->where('is_spam', false)
            ->latest('id')->value('body');

        return is_string($body) && trim($body) !== '' ? mb_substr(trim($body), 0, 120) : null;
    }

    private function markResolved(Conversation $c, User $by): void
    {
        $c->forceFill(['status' => ConversationStatus::Resolved, 'resolved_at' => now(), 'resolved_by_id' => $by->id, 'locked_by_id' => null, 'locked_until' => null])->save();
    }

    private function resolveCase(int $caseId, Conversation $c, User $by): void
    {
        $case = SupportCase::query()->whereKey($caseId)->where(fn ($q) => $q->where('conversation_id', $c->id)->orWhere('customer_id', $c->customer_id))->first();

        if ($case !== null && $case->status !== 'resolved') {
            app(Cases\CaseResolution::class)->setStatus($case, 'resolved', $by, notify: false);
        }
    }

    /** The episode end, in a savepoint (an outcomes failure never fails the close; a deadlock propagates). */
    private function endEpisode(Conversation $c, ?QueueEntry $e, ?Outcome $outcome, ?string $note, User $by, EpisodeEnd $how): void
    {
        try {
            DB::transaction(fn () => $this->outcomes->endEpisode($c, $e, $outcome, $note, $by, $how));
        } catch (Throwable $x) {
            if ($this->causedByConcurrencyError($x) || $this->causedByLostConnection($x)) {
                throw $x;
            }
            report($x);
        }
    }

    /** The reopen window after «خلصت» with thanks (Q8); null when it is off. */
    public static function reopenUntil(QueueSetting $s): ?\Carbon\CarbonInterface
    {
        $minutes = (int) ($s->reopen_after_done_minutes ?? 30);

        return $minutes > 0 ? now()->addMinutes($minutes) : null;
    }

    /** The row of the episode a close ended (for the API's `closing` block). */
    public static function endedRow(QueueEntry $e): ?ConversationOutcome
    {
        return ConversationOutcome::query()->where('queue_entry_id', $e->id)->whereNotNull('ended_at')->latest('id')->first();
    }
}
