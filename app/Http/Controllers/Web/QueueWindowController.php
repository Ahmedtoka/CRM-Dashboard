<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Http\Resources\QueueEntryResource;
use App\Http\Resources\ShiftMemberResource;
use App\Inbox\Outcomes\Outcome;
use App\Inbox\Outcomes\OutcomeRecorder;
use App\Models\QueueEntry;
use App\Models\QueueSetting;
use App\Models\ShiftMember;
use App\Models\SupportCase;
use App\Models\User;
use App\Queue\AttendanceRefused;
use App\Queue\QueueService;
use App\Queue\ShiftService;
use App\Queue\WindowLifecycle;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * The moderator's side of the handover queue inside the inbox: her desk and open windows
 * (`me`), closing a window with a reason, handing it to the shift leader, and her own
 * attendance (attendance design 2026-09-29): «بدأت شغل» (`checkIn`), «استراحة» / «رجعت»
 * (`status`), «خروج» (`checkOut`) and «رجّعي شبابيكي للصالة» (`handBack`). A moderator acts
 * on her own windows only; a supervisor or an admin on any window.
 */
class QueueWindowController extends Controller
{
    /** The reasons a person may close a window with; every other close reason is the system's. */
    public const MANUAL_REASONS = ['inquiry', 'problem', 'case'];

    /**
     * Cheap on purpose: with the queue off it reads the settings row and nothing else, and the
     * inbox then behaves exactly as it does without the queue.
     */
    public function me(Request $request, QueueService $queue, ShiftService $shifts): JsonResponse
    {
        $s = QueueSetting::current();

        if (! $s->enabled) {
            return response()->json(['data' => [
                'enabled' => false, 'member' => null, 'entries' => [], 'settings' => null, 'leader_user_id' => null, 'attendance' => null,
                'server_time' => now()->toIso8601String(),
            ]]);
        }

        return response()->json(['data' => $this->payload($request, $s, $queue, $shifts)]);
    }

    /** «بدأت شغل» (attendance §3). Answers the same payload as `me`. */
    public function checkIn(Request $request, QueueService $queue, ShiftService $shifts): JsonResponse
    {
        $this->abortIfDisabled();

        try {
            $shifts->checkIn($request->user());
        } catch (AttendanceRefused $e) {
            return response()->json(['message' => __('errors.queue.'.$e->key, $e->replace)], $e->status);
        }

        return response()->json(['data' => $this->payload($request, QueueSetting::current(), $queue, $shifts)]);
    }

    /** «خروج»: she leaves at once without windows, else she is `checking_out` until her last one closes. */
    public function checkOut(Request $request, QueueService $queue, ShiftService $shifts): JsonResponse
    {
        $this->abortIfDisabled();
        $member = $this->myMember($request->user());

        if ($member === null) {
            return response()->json(['message' => __('errors.queue.not_on_shift')], 404);
        }

        $shifts->checkOut($member, $request->user());

        return response()->json(['data' => $this->payload($request, QueueSetting::current(), $queue, $shifts)]);
    }

    /** «رجّعي شبابيكي للصالة»: only while she is checking out; then she leaves. */
    public function handBack(Request $request, QueueService $queue, ShiftService $shifts): JsonResponse
    {
        $this->abortIfDisabled();
        $member = $this->myMember($request->user());

        if ($member === null || $member->status !== 'checking_out') {
            return response()->json(['message' => __('errors.queue.not_checking_out')], 409);
        }

        $shifts->handBack($member, $request->user());

        return response()->json(['data' => $this->payload($request, QueueSetting::current(), $queue, $shifts)]);
    }

    /**
     * Her desk, her open windows, the timers and her attendance buttons (the queue is on).
     * Reads the shift templates from the settings it is given; creates no shift row.
     *
     * @return array<string, mixed>
     */
    private function payload(Request $request, QueueSetting $s, QueueService $queue, ShiftService $shifts): array
    {
        $user = $request->user();
        $member = $this->myMember($user);
        // Per user, not per shift-member row: a window she got in the morning shift is still hers in the evening.
        $entries = QueueEntry::query()->with('conversation.customer')
            ->where('assigned_user_id', $user->id)->whereIn('status', QueueEntry::OPEN_STATUSES)
            ->orderBy('window_no')->orderBy('id')->get();
        $open = $queue->openShift();
        // Its time came but the tick has not opened it yet: «بدأت شغل» opens it.
        $covering = $open === null ? $shifts->coveringTemplate(null, $s) : null;
        $next = $open === null && $covering === null ? $shifts->nextStart(null, $s) : null;

        return [
            'enabled' => true,
            'member' => $member ? (new ShiftMemberResource($member))->resolve($request) : null,
            // The settings read above, not once more per window.
            'entries' => $entries->map(fn (QueueEntry $e) => QueueEntryResource::data($e, $s))->values()->all(),
            'settings' => [
                'silence_warn_seconds' => (int) $s->silence_warn_seconds,
                'silence_close_seconds' => (int) $s->silence_close_seconds,
                'windows_per_moderator' => (int) $s->windows_per_moderator,
                'break_minutes' => (int) $s->break_minutes,
            ],
            // The leader of the open shift: nobody above her to escalate to (the menu hides it).
            'leader_user_id' => $open?->leader_user_id,
            // «بدأت شغل» (attendance §3): whether she may check in at all, and whether a shift runs now.
            'attendance' => [
                'eligible' => ShiftService::mayCheckIn($user),
                'shift_open' => $open !== null || $covering !== null,
                'shift_name' => $open?->name ?? $covering['name'] ?? null,
                'next_starts_at' => $next?->toIso8601String(),
            ],
            'server_time' => now()->toIso8601String(),
        ];
    }

    public function close(Request $request, QueueEntry $entry, WindowLifecycle $windows, OutcomeRecorder $outcomes): JsonResponse
    {
        $this->guard($request->user(), $entry);

        $data = $request->validate([
            'reason' => ['required', 'string', Rule::in(self::MANUAL_REASONS)],
            'case_type' => ['nullable', 'required_if:reason,case', 'string', Rule::in(SupportCase::TYPES)],
            'outcome' => ['nullable', 'string', Rule::in(Outcome::agentValues())],
            'outcome_note' => ['nullable', 'required_if:outcome,other', 'string', 'max:120'],
        ], [
            'reason.required' => __('errors.queue.reason_required'),
            'reason.in' => __('errors.queue.reason_required'),
            'case_type.required_if' => __('errors.queue.case_type_required'),
            'case_type.in' => __('errors.queue.case_type_required'),
            'outcome.in' => __('errors.outcome.required'),
            'outcome_note.required_if' => __('errors.outcome.note_required'),
        ]);

        if (! $entry->isOpen()) {
            return $this->notOpen();
        }

        // D13: «خلصت» needs the chat's outcome unless it is automatic (an order in this episode, a service handover).
        $outcome = isset($data['outcome']) ? Outcome::from($data['outcome']) : null;
        if ($outcome === null && ($entry->conversation === null || $outcomes->autoOutcome($entry->conversation, $entry) === null)) {
            throw ValidationException::withMessages(['outcome' => __('errors.outcome.required')]);
        }

        $closed = $windows->close($entry, $data['reason'], $request->user(), [
            'case_type' => $data['case_type'] ?? null, 'outcome' => $outcome, 'outcome_note' => $data['outcome_note'] ?? null,
        ]);

        // Somebody (or the silence timer) closed it between our read and the lock: nothing was done here.
        if ($closed->status !== 'closed' || $closed->close_reason !== $data['reason'] || (int) $closed->closed_by_id !== (int) $request->user()->id) {
            return $this->notOpen();
        }

        return response()->json(['data' => (new QueueEntryResource($closed->load('conversation.customer')))->resolve($request) + [
            'close_reason' => $closed->close_reason,
            'support_case_id' => $closed->support_case_id,
        ]]);
    }

    public function escalate(Request $request, QueueEntry $entry, WindowLifecycle $windows, QueueService $queue): JsonResponse
    {
        $this->guard($request->user(), $entry);

        if (! $entry->isOpen()) {
            return $this->notOpen();
        }

        // The window is already at the shift leader: there is nobody above her in the queue.
        $leaderId = $queue->openShift()?->leader_user_id;

        if ($leaderId !== null && (int) $entry->assigned_user_id === (int) $leaderId) {
            return response()->json(['message' => __('errors.queue.already_with_leader')], 422);
        }

        $new = $windows->escalate($entry, $request->user());

        if ($new === null) {
            return $this->notOpen();
        }

        return response()->json(['data' => (new QueueEntryResource($new->load('conversation.customer')))->resolve($request)]);
    }

    public function status(Request $request, ShiftService $shifts): JsonResponse
    {
        $this->abortIfDisabled();

        $data = $request->validate(['status' => ['required', 'string', Rule::in(['available', 'break'])]]);
        $member = $this->myMember($request->user());

        if ($member === null) {
            return response()->json(['message' => __('errors.queue.not_on_shift')], 404);
        }

        $shifts->setStatus($member, $data['status'], $request->user());

        return response()->json(['data' => (new ShiftMemberResource($member->fresh('user')))->resolve($request)]);
    }

    /** Her desk in the shift that is open now; null when she is not on it (or left it). */
    private function myMember(User $user): ?ShiftMember
    {
        return ShiftMember::query()->with('user')->where('user_id', $user->id)->where('status', '!=', 'left')
            ->whereHas('shift', fn ($q) => $q->where('status', 'open'))->latest('id')->first();
    }

    private function guard(User $user, QueueEntry $entry): void
    {
        $this->abortIfDisabled();

        abort_unless((int) $entry->assigned_user_id === (int) $user->id || $user->isSupervisorOrAbove(), 403, __('errors.queue.not_your_window'));
    }

    private function abortIfDisabled(): void
    {
        if (! QueueSetting::current()->enabled) {
            abort(response()->json(['message' => __('errors.queue.disabled')], 409));
        }
    }

    private function notOpen(): JsonResponse
    {
        return response()->json(['message' => __('errors.queue.window_not_open')], 409);
    }
}
