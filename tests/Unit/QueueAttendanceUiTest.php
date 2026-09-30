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
        ->and(attendanceSource('resources/js/components/crm/queue/MyWindowsStrip.vue'))->toContain('q.checkIn()')->toContain('q.handBack()')
        ->toContain("t('queue.attendance.starts_at'")->toContain('queue?.shown.value')
        ->and(attendanceSource('resources/js/types/crm.ts'))->toContain("'checking_out'")->toContain('export interface MyAttendance');
});
