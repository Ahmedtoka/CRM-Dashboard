<?php

use Illuminate\Support\Facades\DB;

function seedLatencySample(string $kind, int $durationMs): void
{
    DB::table('latency_samples')->insert([
        'kind' => $kind,
        'ref' => (string) random_int(1, 1_000_000),
        'started_at' => now(),
        'ended_at' => now(),
        'duration_ms' => $durationMs,
        'meta' => json_encode([]),
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

it('writes a markdown report with PASS/FAIL lines vs the exact targets and fails when any target misses', function () {
    seedLatencySample('inbound', 500); // < 2000ms target -> PASS
    seedLatencySample('outbound', 3000); // > 1500ms target -> FAIL
    seedLatencySample('list', 100); // < 300ms target -> PASS

    $output = storage_path('app/test-latency-report-'.uniqid().'.md');

    $this->artisan('crm:latency-report', ['--output' => $output])->assertFailed();

    expect(file_exists($output))->toBeTrue();

    $content = file_get_contents($output);

    expect($content)->toContain('PASS')
        ->and($content)->toContain('FAIL')
        ->and($content)->toContain('2000')
        ->and($content)->toContain('1500')
        ->and($content)->toContain('300');

    @unlink($output);
});

it('passes when every kind is within its target', function () {
    seedLatencySample('inbound', 100);
    seedLatencySample('outbound', 100);
    seedLatencySample('list', 100);

    $output = storage_path('app/test-latency-report-'.uniqid().'.md');

    $this->artisan('crm:latency-report', ['--output' => $output])->assertSuccessful();

    $content = file_get_contents($output);
    expect($content)->toContain('PASS')->and($content)->not->toContain('FAIL');

    @unlink($output);
});

it('reads its PASS/FAIL targets from config(crm.latency.targets) instead of a hardcoded value', function () {
    // 800ms would PASS against the real default inbound target (2000ms) but FAIL
    // against a tightened one — proves the command has one source of truth with
    // the /reports/latency page (ReportController) rather than its own copy.
    seedLatencySample('inbound', 800);
    seedLatencySample('outbound', 100);
    seedLatencySample('list', 50);

    $output = storage_path('app/test-latency-report-'.uniqid().'.md');

    $this->artisan('crm:latency-report', ['--output' => $output])->assertSuccessful();
    $before = file_get_contents($output);
    expect($before)->not->toContain('FAIL');

    config(['crm.latency.targets.inbound' => 500]);

    $this->artisan('crm:latency-report', ['--output' => $output])->assertFailed();
    $after = file_get_contents($output);
    expect($after)->toContain('FAIL')
        ->and($after)->toContain('| 500 | FAIL |');

    @unlink($output);
});

it('defaults the output path to docs/perf/staging-report.md at the repo root', function () {
    seedLatencySample('list', 50);

    $expected = dirname(base_path()).DIRECTORY_SEPARATOR.'docs'.DIRECTORY_SEPARATOR.'perf'.DIRECTORY_SEPARATOR.'staging-report.md';
    @unlink($expected);

    $this->artisan('crm:latency-report')->assertSuccessful();

    expect(file_exists($expected))->toBeTrue();

    @unlink($expected);
});
