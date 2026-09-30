<?php

/** Source of a frontend file, for the attendance UI checks (there is no JS test runner). */
function attendanceSource(string $path): string
{
    return (string) file_get_contents(dirname(__DIR__, 2).'/'.$path);
}

it('gives the moderator her attendance buttons in the inbox strip, in both languages', function () {
    expect(attendanceSource('resources/js/i18n/ar.ts'))->toContain("check_in: 'بدأت شغل'")->toContain("starts_at: 'الشيفت بيبدأ {time}'")
        ->toContain("check_out: 'خروج'")->toContain("hand_back: 'رجّعي شبابيكي للصالة'")->toContain("checking_out: 'بتقفلي'")
        ->and(attendanceSource('resources/js/i18n/en.ts'))->toContain("check_in: 'Start work'")->toContain("hand_back: 'Send my windows back to the lounge'")
        ->and(attendanceSource('resources/js/composables/useMyQueue.ts'))->toContain("'/queue/me/check-in'")->toContain("'/queue/me/check-out'")
        ->toContain("'/queue/me/hand-back'")->toContain('breakOver')->not->toContain('breakLeft')
        ->and(attendanceSource('resources/js/components/crm/queue/MyWindowsStrip.vue'))->toContain('q.checkIn()')->toContain('queue?.handBack()')
        ->toContain("t('queue.attendance.starts_at'")->toContain('queue?.shown.value')
        ->and(attendanceSource('resources/js/types/crm.ts'))->toContain("'checking_out'")->toContain('export interface MyAttendance');
});

it('draws the board from check-ins: no start of the day, no roster form, the break clock, «بتقفل» and the overrun alert', function () {
    expect(file_exists(dirname(__DIR__, 2).'/resources/js/components/board/BoardStartPanel.vue'))->toBeFalse()
        ->and(attendanceSource('resources/js/pages/Board.vue'))->not->toContain('BoardStartPanel')->toContain("'closed'")
        ->and(attendanceSource('resources/js/composables/useBoard.ts'))->not->toContain('/board/start')->not->toContain('/board/shifts/')
        ->not->toContain('breakLeft')->toContain('/check-out')->toContain('/hand-back')->toContain('/cap')->toContain('breakOver')
        ->and(attendanceSource('resources/js/components/board/BoardRosterPanel.vue'))->not->toContain('addMember')
        ->and(attendanceSource('resources/js/components/board/BoardMemberPanel.vue'))->toContain('confirmingBack')->toContain('hand_back_confirm_hint')->toContain('@click="confirmingBack = true"')
        ->and(attendanceSource('resources/js/components/board/RoomDesk.vue'))->toContain("'overrun'")->toContain("'closing'")
        ->and(attendanceSource('resources/js/components/board/room.css'))->toContain('.cell.overrun')->toContain('.cell.closing')
        ->and(attendanceSource('resources/js/i18n/ar.ts'))->toContain("checking_out: 'بتقفل'")->toContain("break_since: 'استراحة · {time}'")
        ->toContain("queue_break_overrun: 'استراحة طوّلت'")->not->toContain("title: 'ابدأ اليوم'")
        ->and(attendanceSource('resources/js/i18n/en.ts'))->toContain("checking_out: 'Checking out'")->not->toContain("title: 'Start the day'")
        ->and(attendanceSource('resources/js/components/crm/NotificationBell.vue'))->toContain("'queue.break_overrun'")
        ->and(attendanceSource('resources/js/pages/settings/Queue.vue'))->not->toContain("t('settings.queue.break_after_minutes')");
});

it('shows her attendance of the day in the member panel, in both languages', function () {
    expect(attendanceSource('resources/js/i18n/ar.ts'))->toContain("title: 'حضورها النهارده'")->toContain("overruns: 'استراحات طوّلت'")
        ->and(attendanceSource('resources/js/i18n/en.ts'))->toContain("title: 'Her attendance today'")
        ->and(attendanceSource('resources/js/types/board.ts'))->toContain('export interface AttendanceFigures')
        ->and(attendanceSource('resources/js/lib/board/state.ts'))->toContain('attendance: m.attendance')
        ->and(attendanceSource('resources/js/components/board/BoardMemberPanel.vue'))->toContain("t('board.member.attendance.title')");
});

it('guards her strip against mis-clicks: a confirm before the hand-back, a short hold after each press, and «رجعت» on her way out', function () {
    $strip = attendanceSource('resources/js/components/crm/queue/MyWindowsStrip.vue');

    expect($strip)->toContain('role="alertdialog"')->toContain('confirmingHandBack')->toContain('q.holdAttendance()')
        ->toContain('@click="press(action.run)"')->toContain("t('queue.attendance.hand_back_confirm_hint'")
        ->toContain("back(t('queue.back_to_work'), t('queue.attendance.cancel_check_out_hint'))")
        ->and(attendanceSource('resources/js/composables/useMyQueue.ts'))->toContain('ATTENDANCE_HOLD_MS = 700')->toContain('attendanceHeld()')
        ->and(attendanceSource('resources/js/components/board/BoardMemberPanel.vue'))->toContain('cancelCheckOut')->toContain("t('board.member.cancel_check_out_hint')")
        ->and(attendanceSource('resources/js/i18n/ar.ts'))->toContain("hand_back_confirm_hint: 'شبابيكك المفتوحة ({n})")->toContain("hand_back_cancel: 'رجوع'")
        ->and(attendanceSource('resources/js/i18n/en.ts'))->toContain("hand_back_confirm_hint: 'Your open chats ({n})")->toContain("hand_back_cancel: 'Back'");
});
