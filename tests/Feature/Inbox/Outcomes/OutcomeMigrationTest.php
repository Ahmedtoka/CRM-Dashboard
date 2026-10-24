<?php

use App\Models\Conversation;
use App\Models\SupportCase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// 2026_10_24_100000: old outcome keys map to the new ones; the old value stays readable in `outcome_v1`.

function omRow(Conversation $c, string $outcome, array $attrs = []): int
{
    return DB::table('conversation_outcomes')->insertGetId([
        'conversation_id' => $c->id, 'episode_key' => 'k'.uniqid(), 'outcome' => $outcome, 'source' => 'agent', 'set_at' => now(),
        'started_at' => now()->subHour(), 'ended_at' => now(), 'created_at' => now(), 'updated_at' => now(),
    ] + $attrs);
}

function omRerun(): void
{
    (require database_path('migrations/2026_10_24_100000_add_outcome_suggestion_columns.php'))->up();
}

beforeEach(fn () => Carbon::setTestNow(Carbon::parse('2026-10-24 12:00', 'Africa/Cairo')));

it('adds the suggestion and case columns', function () {
    expect(Schema::hasColumns('conversation_outcomes', ['suggested_outcome', 'suggestion_followed', 'support_case_id', 'outcome_v1']))->toBeTrue();
});

it('maps the old service to the outcome of the case opened in its episode, else an order inquiry, and keeps every other key', function () {
    $withCase = Conversation::factory()->create();
    $case = SupportCase::factory()->create(['conversation_id' => $withCase->id, 'type' => 'exchange', 'created_at' => now()->subMinutes(30)]);
    $plain = Conversation::factory()->create();
    $elsewhere = Conversation::factory()->create();
    SupportCase::factory()->create(['conversation_id' => $elsewhere->id, 'type' => 'return', 'created_at' => now()->subDays(3)]); // before the episode

    $a = omRow($withCase, 'service');
    $b = omRow($plain, 'service');
    $c = omRow($elsewhere, 'service');
    $d = omRow($plain, 'price');
    $e = omRow($plain, 'no_answer');
    $f = omRow($plain, 'unknown');

    omRerun();

    $row = fn (int $id) => DB::table('conversation_outcomes')->find($id);
    expect($row($a)->outcome)->toBe('exchange_case')->and($row($a)->support_case_id)->toBe($case->id)->and($row($a)->outcome_v1)->toBe('service')
        ->and($row($b)->outcome)->toBe('order_inquiry')->and($row($b)->support_case_id)->toBeNull()
        ->and($row($c)->outcome)->toBe('order_inquiry')
        ->and($row($d)->outcome)->toBe('price')->and($row($d)->outcome_v1)->toBe('price')
        ->and($row($e)->outcome)->toBe('no_answer')->and($row($f)->outcome)->toBe('unknown');
});

it('is safe to run twice: converted rows are not converted again', function () {
    $c = Conversation::factory()->create();
    $id = omRow($c, 'service');

    omRerun();
    omRerun();

    expect(DB::table('conversation_outcomes')->find($id)->outcome)->toBe('order_inquiry')->and(DB::table('conversation_outcomes')->find($id)->outcome_v1)->toBe('service');
});
