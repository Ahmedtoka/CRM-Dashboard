<?php

use Illuminate\Support\Facades\DB;

function insertLatencySampleAt(string $kind, \Carbon\CarbonInterface $createdAt): void
{
    DB::table('latency_samples')->insert([
        'kind' => $kind,
        'ref' => (string) random_int(1, 1_000_000),
        'started_at' => $createdAt,
        'ended_at' => $createdAt,
        'duration_ms' => 10,
        'meta' => json_encode([]),
        'created_at' => $createdAt,
        'updated_at' => $createdAt,
    ]);
}

it('deletes samples older than --days and keeps the rest', function () {
    insertLatencySampleAt('list', now()->subDays(10));
    insertLatencySampleAt('list', now()->subDays(9));
    insertLatencySampleAt('list', now()->subDays(1));
    insertLatencySampleAt('list', now());

    $this->artisan('crm:prune-latency', ['--days' => 7])
        ->assertSuccessful()
        ->expectsOutputToContain('deleted=2');

    expect(DB::table('latency_samples')->count())->toBe(2);
});

it('deletes in chunks larger than one page', function () {
    foreach (range(1, 1500) as $_) {
        insertLatencySampleAt('list', now()->subDays(30));
    }

    $this->artisan('crm:prune-latency', ['--days' => 7])
        ->assertSuccessful()
        ->expectsOutputToContain('deleted=1500');

    expect(DB::table('latency_samples')->count())->toBe(0);
});
