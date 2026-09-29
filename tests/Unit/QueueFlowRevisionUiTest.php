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
