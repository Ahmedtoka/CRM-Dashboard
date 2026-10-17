<?php

use App\Models\QueueSetting;
use App\Models\ScoreEvent;
use App\Models\User;
use App\Queue\Scoring\ScoreKeeper;
use Illuminate\Support\Carbon;

beforeEach(function () {
    Carbon::setTestNow(Carbon::parse('2026-10-05 12:00', 'Africa/Cairo'));
    QueueSetting::factory()->create(['id' => 1, 'enabled' => true]);
});

it('writes a line with its reason and is idempotent on the key', function () {
    $u = User::factory()->create();
    $k = app(ScoreKeeper::class);

    $a = $k->award($u->id, 'base', 8, 'base_inquiry', ['ticket' => 7], 'base:1');
    $again = $k->award($u->id, 'base', 8, 'base_inquiry', ['ticket' => 7], 'base:1');

    expect($again->id)->toBe($a->id)
        ->and(ScoreEvent::count())->toBe(1)
        ->and($a->business_date)->toBe('2026-10-05')
        ->and($a->points)->toBe(8)
        ->and(ScoreKeeper::reasonText($a, 'ar'))->toContain('7');
});

it('uses the Cairo business day, not the UTC one', function () {
    Carbon::setTestNow(Carbon::parse('2026-10-06 01:30', 'Africa/Cairo')); // 2026-10-05 22:30 UTC
    $u = User::factory()->create();

    expect(app(ScoreKeeper::class)->award($u->id, 'base', 8, 'base_inquiry', [], 'k')->business_date)->toBe('2026-10-06');
});

it('caps positive awards per day, keeps what fits and never caps negatives or reversals', function () {
    QueueSetting::current()->update(['points' => array_merge(QueueSetting::DEFAULT_POINTS, ['daily_cap' => 20])]);
    $u = User::factory()->create();
    $k = app(ScoreKeeper::class);

    $k->award($u->id, 'base', 12, 'base_problem', ['ticket' => 1], 'a');
    $b = $k->award($u->id, 'base', 12, 'base_problem', ['ticket' => 2], 'b');
    expect($b->points)->toBe(8)->and($b->requested)->toBe(12);

    $c = $k->award($u->id, 'base', 12, 'base_problem', ['ticket' => 3], 'c');
    expect($c->points)->toBe(0);

    $k->award($u->id, 'no_reply', -1, 'no_reply', ['ticket' => 4], 'd');
    $k->reverse($b, 'reversed');

    $s = $k->summary($u->id, '2026-10-05');
    expect($s['total'])->toBe(11)
        ->and($s['cap'])->toBe(20)
        ->and($s['capped'])->toBe(16)
        ->and($s['by_kind'])->toMatchArray(['base' => 12, 'no_reply' => -1]);
});

it('reverses a line once and never reverses a reversal', function () {
    $u = User::factory()->create();
    $k = app(ScoreKeeper::class);
    $line = $k->award($u->id, 'case', 14, 'case_in_time', ['case' => 3], 'case:3:1');

    $rev = $k->reverse($line, 'case_reopened', ['case' => 3]);
    expect($rev->points)->toBe(-14)
        ->and($rev->reverses_id)->toBe($line->id)
        ->and($line->fresh()->reversed_by_id)->toBe($rev->id)
        ->and($k->reverse($line->fresh(), 'case_reopened'))->toBeNull()
        ->and($k->reverse($rev, 'case_reopened'))->toBeNull()
        ->and($k->dayTotal($u->id, '2026-10-05'))->toBe(0);
});

it('lists the ledger of a day newest first', function () {
    $u = User::factory()->create();
    $k = app(ScoreKeeper::class);
    $k->award($u->id, 'base', 8, 'base_inquiry', ['ticket' => 1], 'x1');
    $k->award($u->id, 'speed', 6, 'speed_fast', ['ticket' => 1, 'time' => '0:40'], 'x2');
    $k->award(User::factory()->create()->id, 'base', 8, 'base_inquiry', [], 'other');

    $ledger = $k->ledger($u->id, '2026-10-05');
    expect(array_column($ledger, 'kind'))->toBe(['speed', 'base'])
        ->and($ledger[0]['reason'])->toBeString()->not->toBe('');
});
