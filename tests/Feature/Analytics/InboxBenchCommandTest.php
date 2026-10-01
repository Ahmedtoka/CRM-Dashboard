<?php

use App\Models\Conversation;
use App\Models\Message;
use App\Models\User;

it('refuses in production', function () {
    app()->detectEnvironment(fn () => 'production');
    $this->artisan('crm:inbox-bench', ['--user' => 'x@y.z'])->assertFailed();
});

it('measures every scenario and writes json and markdown', function () {
    app()->detectEnvironment(fn () => 'local');
    $admin = User::factory()->create(['role' => 'admin', 'is_active' => true]);
    $c = Conversation::factory()->create();
    Message::factory()->count(3)->create(['conversation_id' => $c->id]);
    $dir = storage_path('framework/testing/bench');

    $this->artisan('crm:inbox-bench', ['--user' => $admin->email, '--label' => 't', '--runs' => 2, '--warmup' => 0, '--output-dir' => $dir])
        ->assertSuccessful();

    $json = json_decode(file_get_contents("{$dir}/inbox-t.json"), true);
    expect($json['label'])->toBe('t')
        ->and($json['scenarios'])->not->toBeEmpty()
        ->and(collect($json['scenarios'])->firstWhere('name', 'list.default')['status'])->toBe(200)
        ->and(collect($json['scenarios'])->firstWhere('name', 'detail.hot')['status'])->toBe(200)
        ->and(collect($json['scenarios'])->every(fn ($s) => array_key_exists('p95_ms', $s) && array_key_exists('queries_avg', $s)))->toBeTrue()
        ->and(file_get_contents("{$dir}/inbox-t.md"))->toContain('| list.default |');
});
