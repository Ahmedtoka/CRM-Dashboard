<?php

use App\Models\Conversation;
use App\Models\Message;
use App\Models\User;
use Illuminate\Support\Facades\File;

it('refuses in production even with a load-named database', function () {
    app()->detectEnvironment(fn () => 'production');
    config(['database.connections.sqlite.database' => 'social_crm_load']);
    $this->artisan('crm:inbox-bench', ['--user' => 'x@y.z'])->assertFailed();
});

it('refuses when the database name does not contain "load"', function () {
    app()->detectEnvironment(fn () => 'local');
    config(['database.connections.sqlite.database' => 'social_crm']);
    $admin = User::factory()->create(['role' => 'admin', 'is_active' => true]);
    $dir = storage_path('framework/testing/bench-refused');

    $this->artisan('crm:inbox-bench', ['--user' => $admin->email, '--label' => 'r', '--runs' => 1, '--warmup' => 0, '--output-dir' => $dir])
        ->assertFailed();

    expect(file_exists("{$dir}/inbox-r.json"))->toBeFalse();
});

it('measures every scenario, writes json and markdown, and answers 200 for the existing endpoints', function () {
    app()->detectEnvironment(fn () => 'local');
    config(['database.connections.sqlite.database' => 'social_crm_load']);
    $admin = User::factory()->create(['role' => 'admin', 'is_active' => true]);
    $c = Conversation::factory()->create();
    Message::factory()->count(3)->create(['conversation_id' => $c->id]);
    $dir = storage_path('framework/testing/bench');

    try {
        $this->artisan('crm:inbox-bench', ['--user' => $admin->email, '--label' => 't', '--runs' => 2, '--warmup' => 0, '--output-dir' => $dir])
            ->assertSuccessful();

        $json = json_decode(file_get_contents("{$dir}/inbox-t.json"), true);
        $byName = collect($json['scenarios'])->keyBy('name');
        expect($json['label'])->toBe('t')
            ->and($json['scenarios'])->not->toBeEmpty()
            ->and($byName['list.default']['status'])->toBe(200)
            ->and($byName['list.default']['queries_avg'])->toBeGreaterThan(0)
            ->and($byName['detail.hot']['status'])->toBe(200)
            ->and(collect($json['scenarios'])->every(fn ($s) => array_key_exists('p95_ms', $s) && array_key_exists('queries_avg', $s)))->toBeTrue()
            ->and(file_get_contents("{$dir}/inbox-t.md"))->toContain('| list.default |');

        // Scenarios for filters a later task adds may answer 422; every other one must answer 200.
        $later = ['list.state_bot', 'list.state_with_moderator', 'list.queue_waiting'];
        expect(collect($json['scenarios'])->reject(fn ($s) => in_array($s['name'], $later, true))->every(fn ($s) => $s['status'] === 200))->toBeTrue();
    } finally {
        File::deleteDirectory($dir);
    }
});
