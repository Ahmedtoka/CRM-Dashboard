<?php

use App\Ads\Reports\Objective;

it('maps platform objectives to families', function (?string $objective, int $chats, string $family) {
    expect(Objective::family($objective, $chats))->toBe($family);
})->with([
    ['MESSAGES', 0, 'messages'],
    ['OUTCOME_ENGAGEMENT', 0, 'messages'],
    ['OUTCOME_SALES', 0, 'sales'],
    ['CONVERSIONS', 0, 'sales'],
    ['OUTCOME_SALES', 4, 'messages'],   // Sales objective with a Messenger destination
    ['OUTCOME_TRAFFIC', 0, 'traffic'],
    ['LINK_CLICKS', 0, 'traffic'],
    [null, 0, 'other'],
    ['OUTCOME_AWARENESS', 0, 'other'],
]);
