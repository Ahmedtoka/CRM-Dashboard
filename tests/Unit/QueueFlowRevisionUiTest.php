<?php

/*
|--------------------------------------------------------------------------
| Flow revision (2026-09-29): the UI pieces are there, in both languages
|--------------------------------------------------------------------------
|
| There is no JS test runner in this project. These checks make sure the strings a moderator
| or the leader must read exist in both dictionaries and that the components use the new hooks;
| the behaviour itself is checked in the browser (plan Task 9).
|
*/

function revisionSource(string $path): string
{
    // Unit tests do not boot the framework: resolve against the project root.
    $source = file_get_contents(dirname(__DIR__, 2).'/'.$path);

    expect($source)->not->toBeFalse("could not read {$path}");

    return (string) $source;
}

it('has the «رد الموظفة» settings section in both languages', function () {
    expect(revisionSource('resources/js/i18n/ar.ts'))->toContain("reply_section: 'رد الموظفة'")->toContain("no_reply: 'ما ردّتش (بتتخصم)'")
        ->toContain("case_follow_owner: 'الكيس المفتوح يروح للموظفة اللي فتحته'")
        ->and(revisionSource('resources/js/i18n/en.ts'))->toContain("reply_section: 'Moderator reply'")->toContain("no_reply: 'No reply (deducted)'")
        ->and(revisionSource('resources/js/pages/settings/Queue.vue'))->toContain("'agent_reassign_first_seconds'")->toContain('form.case_follow_owner')->toContain("'no_reply'");
});

it('shows a desk whose moderator is not logged in and the overdue windows, in both languages', function () {
    expect(revisionSource('resources/js/i18n/ar.ts'))->toContain("not_online: 'مش فاتحة'")->toContain("handoff_left: 'تتحوّل لزميلة بعد'")
        ->toContain("stats_no_reply: '{stats} · ما ردّتش {n}'")->toContain("no_reply: 'ما ردّتش'")
        ->and(revisionSource('resources/js/i18n/en.ts'))->toContain("not_online: 'Not logged in'")->toContain("handoff_left: 'Handed to a colleague in'")
        ->and(revisionSource('resources/js/lib/board/state.ts'))->toContain('export function notOnline(')
        ->and(revisionSource('resources/js/components/board/RoomDesk.vue'))->toContain('notOnline(')
        ->and(revisionSource('resources/js/components/board/RoomWindow.vue'))->toContain('board.handoffLeft(')
        ->and(revisionSource('resources/js/components/crm/queue/MyWindowsStrip.vue'))->toContain("'overdue'")->toContain('handoffLeft(')
        ->and(revisionSource('resources/js/components/board/room.css'))->toContain('.cell.notonline')->toContain('.slot.overdue');
});

it('shows the open case and the new notifications, in both languages', function () {
    expect(revisionSource('resources/js/i18n/ar.ts'))->toContain("open_case: 'عندها كيس مفتوح #{id}'")
        ->toContain("queue_reply_overdue_item: '{name} مستنية ردك · دور #{ticket}'")
        ->toContain("queue_reply_overdue_leader_item: '{agent} ما ردّتش على {name} · دور #{ticket}'")
        ->toContain("queue_member_not_arrived_item: '{name} لسه ما فتحتش السيستم · شيفت {shift}'")
        ->and(revisionSource('resources/js/i18n/en.ts'))->toContain("open_case: 'Open case #{id}'")->toContain("queue_member_not_arrived: 'A moderator has not logged in'")
        ->and(revisionSource('resources/js/composables/useNotifications.ts'))->toContain("'queue.reply_overdue_leader': 'queue_reply_overdue_leader'")->toContain("'queue.member_not_arrived': 'queue_member_not_arrived'")
        ->and(revisionSource('resources/js/components/crm/NotificationBell.vue'))->toContain("'queue.reply_overdue'")->toContain("'queue.member_not_arrived'")
        ->and(revisionSource('resources/js/components/crm/queue/MyWindowsStrip.vue'))->toContain('open_case_id')
        ->and(revisionSource('resources/js/components/crm/queue/QueueBanner.vue'))->toContain('open_case_id')
        ->and(revisionSource('resources/js/components/board/RoomLounge.vue'))->toContain('open_case_id')
        ->and(revisionSource('resources/js/components/board/RoomWindow.vue'))->toContain('open_case_id')
        ->and(revisionSource('resources/js/components/board/BoardEntryPanel.vue'))->toContain('open_case_id');
});
