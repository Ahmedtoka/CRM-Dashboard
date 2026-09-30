<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\QueueDecision;
use App\Models\QueueEntry;
use App\Models\QueueSetting;
use App\Models\Shift;
use App\Models\ShiftMember;
use App\Queue\BoardAccess;
use App\Queue\BoardState;
use App\Queue\Events\QueueMemberUpdated;
use App\Queue\Events\RouterDecided;
use App\Queue\QueueRouter;
use App\Queue\QueueService;
use App\Queue\ShiftService;
use App\Queue\WindowLifecycle;
use App\Support\SafeBroadcast;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The manager's live board: the room (state), a moderator's break and number of windows,
 * checking her out or sending her windows back on her behalf, handing a waiting customer to a moderator by hand and taking one
 * out of the lounge. Nobody is seated from here: moderators check themselves in from the inbox
 * (attendance design 2026-09-29). Supervisors, admins and the leader of the open shift only
 * (BoardAccess). Every action answers with the fresh state, so the screen that acted never
 * waits for the websocket.
 */
class BoardController extends Controller
{
    public function index(Request $request): Response
    {
        $this->authorizeBoard($request);

        return Inertia::render('Board', [
            'enabled' => (bool) QueueSetting::current()->enabled,
            'canEditSettings' => $request->user()->isSupervisorOrAbove(),
        ]);
    }

    public function state(Request $request, BoardState $board): JsonResponse
    {
        $this->authorizeBoard($request);

        return $this->fresh($board);
    }

    /** Her number of windows (null = the settings' default). She keeps her status and her windows. */
    public function cap(Request $request, ShiftMember $member, BoardState $board): JsonResponse
    {
        $this->authorizeBoard($request);
        $this->enabledSettings();

        $data = $request->validate(['windows_cap' => ['present', 'nullable', 'integer', 'min:1', 'max:10']]);

        if ($member->status === 'left' || $member->shift?->status !== 'open') {
            return $this->refuse('member_gone', 409);
        }

        $member->update(['windows_cap' => $data['windows_cap']]);
        SafeBroadcast::send(new QueueMemberUpdated($member->fresh()));
        app(QueueRouter::class)->runAfterCommit('شبابيك '.$member->user?->name);

        return $this->fresh($board);
    }

    /** «خروج» on her behalf (she left without pressing it): the same rules as her own button. */
    public function checkOut(Request $request, ShiftMember $member, ShiftService $shifts, BoardState $board): JsonResponse
    {
        $this->authorizeBoard($request);
        $this->enabledSettings();

        if ($member->status === 'left' || $member->shift?->status !== 'open') {
            return $this->refuse('member_gone', 409);
        }

        $shifts->checkOut($member, $request->user());

        return $this->fresh($board);
    }

    /** «رجّعي شبابيكها للصالة» on her behalf, while she is checking out: her customers go back to the top of the lounge. */
    public function handBack(Request $request, ShiftMember $member, ShiftService $shifts, BoardState $board): JsonResponse
    {
        $this->authorizeBoard($request);
        $this->enabledSettings();

        if ($member->status !== 'checking_out' || $member->shift?->status !== 'open') {
            return $this->refuse('not_checking_out', 409);
        }

        $shifts->handBack($member, $request->user());

        return $this->fresh($board);
    }

    public function memberStatus(Request $request, ShiftMember $member, ShiftService $shifts, BoardState $board): JsonResponse
    {
        $this->authorizeBoard($request);
        $this->enabledSettings();

        $data = $request->validate(['status' => ['required', 'string', Rule::in(['available', 'break'])]]);

        if ($member->status === 'left' || $member->shift?->status !== 'open') {
            return $this->refuse('member_gone', 409);
        }

        $shifts->setStatus($member, $data['status'], $request->user());

        return $this->fresh($board);
    }

    public function assign(Request $request, QueueEntry $entry, QueueRouter $router, QueueService $queue, BoardState $board): JsonResponse
    {
        $this->authorizeBoard($request);
        $settings = $this->enabledSettings();

        $data = $request->validate(['user_id' => ['required', 'integer']]);

        if ($entry->status !== 'waiting') {
            return $this->refuse('not_waiting', 409);
        }

        $shift = $queue->openShift();
        $desk = $shift === null ? null : ShiftMember::query()->with('user.userPlatforms')
            ->where('shift_id', $shift->id)->where('user_id', $data['user_id'])->where('status', '!=', 'left')->first();

        if ($desk === null || $desk->user === null) {
            return $this->refuse('member_not_on_shift', 422);
        }

        if (! in_array($desk->status, ['available', 'busy'], true) || ! $desk->user->is_active) {
            return $this->refuse('member_unavailable', 422);
        }

        $platform = $entry->conversation?->platform;

        if ($platform === null || ! $desk->user->canAccessPlatform($platform)) {
            return $this->refuse('member_platform', 422);
        }

        if ($router->openForUser((int) $desk->user_id)->count() >= (int) ($desk->windows_cap ?? $settings->windows_per_moderator)) {
            return $this->refuse('member_full', 422);
        }

        $by = $request->user();

        // The router decides again under its locks: she may have filled up, or the customer left, meanwhile.
        if (! $router->assign($entry, $desk, 'يدوي من '.$by->name, 'manual', $settings)) {
            return $this->refuse($entry->fresh()?->status === 'waiting' ? 'member_unavailable' : 'not_waiting', 409);
        }

        $this->decide($shift, 'تعيين يدوي من '.$by->name, [
            '<b>#'.($entry->ticket_no % 100000).'</b> <span class="hi">يدوي من '.e($by->name).'</span> ← <span class="ok">'.e($desk->user->name).'</span>',
        ]);

        return $this->fresh($board);
    }

    public function cancel(Request $request, QueueEntry $entry, WindowLifecycle $windows, QueueService $queue, BoardState $board): JsonResponse
    {
        $this->authorizeBoard($request);
        $this->enabledSettings();

        $data = $request->validate(
            ['reason' => ['required', 'string', 'min:2', 'max:200']],
            ['reason.required' => __('errors.queue.cancel_reason_required'), 'reason.min' => __('errors.queue.cancel_reason_required')],
        );

        if ($entry->status !== 'waiting') {
            return $this->refuse('not_waiting', 409);
        }

        $by = $request->user();
        $closed = $windows->close($entry, 'cancelled', $by, ['note' => $data['reason']]);

        // The router called her between our read and the lock: she is in a window, not cancelled.
        if ($closed->status !== 'cancelled' || (int) $closed->closed_by_id !== (int) $by->id) {
            return $this->refuse('not_waiting', 409);
        }

        $this->decide($queue->openShift(), 'إلغاء من '.$by->name, [
            '<span class="no">#'.($closed->ticket_no % 100000).' خرجت من الصالة: '.e($closed->close_note).'</span>',
        ]);

        return $this->fresh($board);
    }

    private function authorizeBoard(Request $request): void
    {
        abort_unless(BoardAccess::allows($request->user()), 403, __('errors.queue.board_forbidden'));
    }

    private function enabledSettings(): QueueSetting
    {
        $settings = QueueSetting::current();

        if (! $settings->enabled) {
            abort(response()->json(['message' => __('errors.queue.disabled')], 409));
        }

        return $settings;
    }

    /** A line on the wall screen for what a person decided, next to the router's own. */
    private function decide(?Shift $shift, string $trigger, array $lines): void
    {
        $decision = QueueDecision::create([
            'shift_id' => $shift?->id, 'trigger' => mb_substr($trigger, 0, 120),
            'lines' => ['<b>المحفّز:</b> '.e($trigger), ...$lines], 'created_at' => now(),
        ]);

        SafeBroadcast::send(new RouterDecided($decision));
    }

    private function refuse(string $key, int $status): JsonResponse
    {
        return response()->json(['message' => __('errors.queue.'.$key)], $status);
    }

    private function fresh(BoardState $board): JsonResponse
    {
        return response()->json(['data' => $board->snapshot()]);
    }
}
