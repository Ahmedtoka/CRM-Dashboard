<?php

namespace App\Queue\Jobs;

use App\Inbox\OutboundService;
use App\Inbox\SendClaimRefused;
use App\Inbox\WindowClosedException;
use App\Models\Conversation;
use App\Models\QueueEntry;
use App\Models\QueueMessageSend;
use App\Queue\AfterCloseGuard;
use App\Queue\QueueScripts;
use App\Queue\QueueService;
use App\Queue\RatingService;
use App\Queue\WindowLifecycle;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * The ONE closing message of «خلصت» (close-cycle-FINAL §6, Q1/Q2; RC-5, RC-13): the case number of a
 * case this close opened, the thanks (`queue_closed_thanks`) and — when she may be asked — the
 * rating question (`queue_review_ask`) with its buttons «1» … «5», all in one message.
 *
 * Runs DELAY_SECONDS after the close (so «تراجع» and a message of hers already on its way come
 * first). Inside the transaction that creates the Message (`sendBot(claim:)`), under the
 * conversation + entry locks, it checks again that the close still stands — the same close
 * (`closedAt` token), still closed (no undo), no newer entry, no real message of hers and no staff
 * reply since — then claims it once (`closing_claimed_at`, the rating's `review_requested_at`, this
 * job's send-once key). The platform call re-checks once more (`afterCloseOf`). A close with no queue
 * entry (§4.4: a person handled the chat with no window) sends the thanks alone, judged from the
 * close's moment, with the send-once key only.
 */
class SendClosingMessage implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    /** After the close: «تراجع» (ConversationCycle::UNDO_SECONDS) and a webhook already on its way come first. */
    public const DELAY_SECONDS = 20;

    public int $tries = 2;

    public string $sendKey;

    public function __construct(
        public readonly ?int $entryId,
        public readonly int $conversationId,
        public readonly string $closedAt,
        public readonly bool $caseOpened = false,
        public readonly bool $withThanks = true,
        /** Review #7: the close this job belongs to (`close_seq`); null for a close with no entry. */
        public readonly ?int $closeSeq = null,
    ) {
        $this->sendKey = (string) Str::uuid();
        $this->onQueue('outbound');
        $this->afterCommit();
    }

    public function handle(OutboundService $outbound, QueueScripts $scripts, RatingService $ratings): void
    {
        $c = Conversation::query()->find($this->conversationId);
        $e = $this->entryId !== null ? QueueEntry::query()->with('conversation')->find($this->entryId) : null;

        if ($c === null || ($this->entryId !== null && $e === null)) {
            return;
        }

        if (($why = $this->staleReason($c, $e)) !== null) {
            $this->skipped($e, $why);

            return;
        }

        $rate = $e !== null && $this->withThanks && ! $this->caseOpened && $ratings->askableAtClose($e)
            && $scripts->text('queue_review_ask', ['name' => '']) !== null;
        $text = $this->compose($scripts, $e, $rate);

        if ($text === null) {
            $this->skipped($e, 'script_off');

            return;
        }

        $buttons = $rate ? RatingService::buttons($e) : [];

        try {
            $message = $outbound->sendBot($c, $text, 0, false, $buttons, claim: fn () => $this->claim($c, $e, $rate), afterCloseOf: $e?->id);
        } catch (SendClaimRefused) {
            Log::info('queue.closing_skipped', ['entry' => $this->entryId, 'conversation' => $this->conversationId]);

            return;
        } catch (WindowClosedException) {
            $this->skipped($e, 'window_closed');

            return;
        }

        if ($rate && $e !== null) {
            QueueEntry::query()->whereKey($e->id)->update(['review_message_id' => $message->id]);
        }
    }

    /** Why the closing message must not go out; null while the close still stands. */
    private function staleReason(Conversation $c, ?QueueEntry $e): ?string
    {
        if ($e === null) {
            $at = Carbon::parse($this->closedAt);

            return match (true) {
                app(QueueService::class)->activeEntry($c) !== null => 'newer_entry',
                AfterCloseGuard::customerWroteAfter($c->id, $at) => 'customer_back',
                AfterCloseGuard::staffRepliedAfter($c->id, $at) => 'staff_replied',
                (bool) $c->is_test && ! $c->isLoadTest() => 'test',
                default => null,
            };
        }

        if ($e->status !== 'closed' || $e->closed_at === null) {
            return 'undone';
        }

        if ($e->closed_at->toIso8601String() !== $this->closedAt || ($this->closeSeq !== null && (int) $e->close_seq !== $this->closeSeq)) {
            return 'superseded';
        }

        if (($guard = AfterCloseGuard::reason($e)) !== null) {
            return $guard;
        }

        return ((bool) $c->is_test || $e->is_test) && ! $c->isLoadTest() ? 'test' : null;
    }

    /** The parts in order: the new case's number, the thanks, the rating question. Null when nothing is left to say. */
    private function compose(QueueScripts $scripts, ?QueueEntry $e, bool $rate): ?string
    {
        $parts = [];

        if ($this->caseOpened && $e?->support_case_id !== null) {
            $parts[] = $scripts->text('queue_case_opened', ['case_id' => $e->support_case_id]);
        }

        if ($this->withThanks) {
            $parts[] = $scripts->text('queue_closed_thanks', []);
        }

        if ($rate) {
            $first = trim(explode(' ', trim((string) $e?->assignee?->name))[0] ?? '');
            $parts[] = $scripts->text('queue_review_ask', ['name' => $first]);
        }

        $parts = array_values(array_filter($parts, fn ($p) => is_string($p) && trim($p) !== ''));

        return $parts === [] ? null : implode("\n\n", $parts);
    }

    /** Inside the Message's transaction: the stand-check under the locks, then every claim. False: nothing is sent. */
    private function claim(Conversation $c, ?QueueEntry $e, bool $rate): bool
    {
        if ($e !== null) {
            $locked = WindowLifecycle::lockBoth($e);

            if ($locked === null || $this->staleReason($c, $locked) !== null
                || QueueEntry::query()->whereKey($e->id)->whereNull('closing_claimed_at')->update(['closing_claimed_at' => now()]) !== 1) {
                return false;
            }

            if ($rate && QueueEntry::query()->whereKey($e->id)->whereNull('review_requested_at')->update(['review_requested_at' => now()]) !== 1) {
                return false;
            }
        } else {
            Conversation::query()->whereKey($c->id)->lockForUpdate()->first(['id']);

            if ($this->staleReason($c, null) !== null) {
                return false;
            }
        }

        if (QueueMessageSend::query()->where('send_key', $this->sendKey)->exists()) {
            return false;
        }

        QueueMessageSend::query()->create(['send_key' => $this->sendKey, 'queue_entry_id' => $e?->id ?? 0, 'script_key' => 'queue_closing', 'created_at' => now()]);

        return true;
    }

    private function skipped(?QueueEntry $e, string $why): void
    {
        if ($e !== null && $why !== 'undone' && $why !== 'superseded') {
            QueueEntry::query()->whereKey($e->id)->whereNull('closing_claimed_at')->update(['closing_skipped_reason' => mb_substr($why, 0, 30)]);
        }

        Log::info('queue.closing_skipped', ['entry' => $this->entryId, 'conversation' => $this->conversationId, 'reason' => $why]);
    }
}
