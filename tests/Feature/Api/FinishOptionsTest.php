<?php

use App\Http\Controllers\Api\V1\FinishOptionsController;
use App\Inbox\Outcomes\Outcome;
use App\Models\SupportCase;
use App\Models\User;
use Laravel\Sanctum\Sanctum;

/*
 * Mobile r2 (docs/round-2026-10-24/finish-api.md §1): the «خلصت» sheet reads its outcome list from
 * GET /api/v1/finish/options, so the app follows whatever the deployed Outcome enum allows.
 */

function foModerator(): User
{
    $u = User::factory()->create(['role' => 'moderator', 'last_seen_at' => now()]);
    $u->userPlatforms()->create(['platform' => 'facebook']);

    return $u;
}

it('needs a token', function () {
    $this->getJson('/api/v1/finish/options')->assertUnauthorized();
});

it('lists exactly the outcomes finish accepts, with Arabic labels and a group each', function () {
    Sanctum::actingAs(foModerator());

    $res = $this->getJson('/api/v1/finish/options')->assertOk();
    $rows = collect($res->json('data.outcomes'));

    expect($rows->pluck('key')->all())->toEqualCanonicalizing(Outcome::agentValues());

    foreach ($rows as $row) {
        expect($row['label'])->toBe(Outcome::from($row['key'])->label())->not->toBe('labels.outcomes.'.$row['key'])
            ->and($row['group'])->toBeIn(FinishOptionsController::GROUP_ORDER)
            ->and($row)->toHaveKeys(['key', 'label', 'group', 'lost_sale', 'note_required', 'silent']);
    }

    expect($rows->firstWhere('key', 'other')['note_required'])->toBeTrue()
        ->and($rows->firstWhere('key', 'price')['note_required'])->toBeFalse()
        ->and($rows->firstWhere('key', 'no_answer')['silent'])->toBeTrue()
        ->and($rows->firstWhere('key', 'price')['lost_sale'])->toBeTrue();
});

it('orders the list by section: sale, then service, then other', function () {
    Sanctum::actingAs(foModerator());

    $groups = collect($this->getJson('/api/v1/finish/options')->json('data.outcomes'))->pluck('group')->unique()->values()->all();

    expect($groups)->toBe(array_values(array_filter(FinishOptionsController::GROUP_ORDER, fn ($g) => in_array($g, $groups, true))));
});

it('carries the sheet constants: groups, after, case types, note max, undo seconds', function () {
    Sanctum::actingAs(foModerator());

    $data = $this->getJson('/api/v1/finish/options')->json('data');

    expect(collect($data['groups'])->pluck('key')->all())->toBe(['sale', 'service', 'other'])
        ->and(collect($data['after'])->pluck('key')->all())->toBe(['thanks', 'bot'])
        ->and(collect($data['case_types'])->pluck('key')->all())->toBe(array_keys(SupportCase::TYPE_LABELS))
        ->and($data['note_max'])->toBe(120)
        ->and($data['undo_seconds'])->toBe(15);
});
