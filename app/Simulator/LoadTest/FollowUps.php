<?php

namespace App\Simulator\LoadTest;

use App\Enums\ConversationStatus;
use App\Models\Conversation;
use App\Models\LoadTestRun;
use App\Models\QueueEntry;
use App\Simulator\LoadTest\Jobs\SendLoadTestFollowUp;
use App\Simulator\Simulator;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * The simulated customer's side of a load-test chat (2026-10-07): after an AGENT reply (only
 * OutboundService::dispatchHuman() calls in; a bot or system message never does) her next
 * scenario line arrives 60-120 s later through the inbound pipeline — at most
 * Scenarios::MAX_FOLLOWUPS, the last one the thanks.
 *
 * State lives on `conversations.meta.load_test`: `step` (lines sent) and `pending` (the step
 * already queued). Reserved and consumed under the conversation's row lock, so two quick agent
 * replies queue one line, and a line whose chat closed, or whose run was stopped, in the
 * meantime is never sent.
 */
class FollowUps
{
    public const MIN_DELAY = 60;

    public const MAX_DELAY = 120;

    /** A reservation older than this (its job was lost) no longer blocks the next one. */
    private const STALE_MINUTES = 10;

    public function __construct(private readonly Simulator $simulator) {}

    public function agentReplied(Conversation $c): void
    {
        // The common case, real chats: no query at all.
        if (! $c->isLoadTest() || ! LoadTest::enabled()) {
            return;
        }

        $step = DB::transaction(function () use ($c) {
            $locked = Conversation::query()->whereKey($c->id)->lockForUpdate()->first();
            $lt = $locked?->meta['load_test'] ?? null;

            if (! is_array($lt) || $locked->status === ConversationStatus::Resolved || ! LoadTest::runIsLive($lt['run'] ?? null)) {
                return null;
            }

            $pendingAt = isset($lt['pending_at']) ? Carbon::parse($lt['pending_at']) : null;

            if (($lt['pending'] ?? null) !== null && $pendingAt !== null && $pendingAt->gt(now()->subMinutes(self::STALE_MINUTES))) {
                return null; // a line is already on its way
            }

            $step = (int) ($lt['step'] ?? 0) + 1;

            if (Scenarios::followUp((string) $lt['scenario'], $step) === null) {
                return null; // she already said thanks
            }

            $this->writeMeta($locked, array_merge($lt, [
                'pending' => $step,
                'pending_at' => now()->toIso8601String(),
                // The ticket open now: once it is closed, the line is not sent.
                'entry' => $this->openEntryId($locked),
            ]));

            return $step;
        });

        if ($step !== null) {
            SendLoadTestFollowUp::dispatch($c->id, $step)->delay(now()->addSeconds(random_int(self::MIN_DELAY, self::MAX_DELAY)));
        }
    }

    /** Runs the reserved line: true when it was sent. */
    public function send(int $conversationId, int $step): bool
    {
        $line = DB::transaction(function () use ($conversationId, $step) {
            $c = Conversation::query()->whereKey($conversationId)->lockForUpdate()->first();
            $lt = $c?->meta['load_test'] ?? null;

            if (! is_array($lt) || ($lt['pending'] ?? null) !== $step) {
                return null; // not ours (already sent, or replaced)
            }

            $closed = $c->status === ConversationStatus::Resolved
                || (($lt['entry'] ?? null) !== null && ! QueueEntry::query()->whereKey($lt['entry'])->whereIn('status', ['waiting', ...QueueEntry::OPEN_STATUSES])->exists());
            $line = Scenarios::followUp((string) $lt['scenario'], $step);
            $send = ! $closed && $line !== null && LoadTest::runIsLive($lt['run'] ?? null);

            $this->writeMeta($c, array_merge($lt, ['pending' => null, 'pending_at' => null], $send ? ['step' => $step] : []));

            if ($send) {
                LoadTestRun::query()->whereKey((int) $lt['run'])->increment('followups_sent');
            }

            return $send ? [$c, $lt, $line] : null;
        });

        if ($line === null) {
            return false;
        }

        [$c, $lt, $text] = $line;

        $this->simulator->customerMessage($c->platform, (string) $lt['customer_key'], (string) ($lt['name'] ?? ''), $text);

        return true;
    }

    private function openEntryId(Conversation $c): ?int
    {
        return QueueEntry::query()->where('conversation_id', $c->id)->whereIn('status', ['waiting', ...QueueEntry::OPEN_STATUSES])
            ->latest('id')->value('id');
    }

    /** @param array<string, mixed> $lt */
    private function writeMeta(Conversation $c, array $lt): void
    {
        $c->forceFill(['meta' => array_merge($c->meta ?? [], ['load_test' => $lt])])->save();
    }
}
