<?php

use App\Ads\Sync\AdsSyncService;
use App\Ads\Sync\QueueInspector;
use App\Ads\Sync\SyncAdAccount;
use App\Models\AdAccount;
use App\Models\AdsSyncRun;
use App\Models\User;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia as Assert;

it('records who and why on a manual account sync', function () {
    Queue::fake();
    $admin = User::factory()->create(['role' => 'admin']);
    $acc = AdAccount::factory()->meta()->create();
    $this->actingAs($admin)->post("/ads/accounts/{$acc->id}/sync")->assertRedirect();
    Queue::assertPushed(SyncAdAccount::class, fn ($j) => $j->trigger === 'manual' && $j->triggeredById === $admin->id);
});

it('stores trigger and user on the run', function () {
    $acc = AdAccount::factory()->meta()->create();
    $u = User::factory()->create();
    $run = app(AdsSyncService::class)->syncAccount($acc, now()->subDays(2)->toImmutable(), now()->toImmutable(), 'recent', true, 'manual', $u->id);
    expect($run->trigger)->toBe('manual')->and($run->triggered_by_id)->toBe($u->id);
});

it('shows running runs, the log with the user and the schedule', function () {
    $admin = User::factory()->create(['role' => 'admin', 'name' => 'Owner']);
    $acc = AdAccount::factory()->meta()->create(['name' => 'Cloting']);
    AdsSyncRun::create(['ad_account_id' => $acc->id, 'platform' => 'meta', 'kind' => 'backfill', 'status' => 'running', 'trigger' => 'manual', 'triggered_by_id' => $admin->id, 'started_at' => now()]);
    $this->actingAs($admin)->get('/ads/sync')->assertInertia(fn (Assert $p) => $p->component('Ads/Sync')
        ->has('now.running', 1)->where('now.running.0.account', 'Cloting')->where('now.running.0.user', 'Owner')
        ->has('runs', 1)->has('schedule'));
});

it('forbids the sync page to media buyers', function () {
    $this->actingAs(User::factory()->create(['role' => 'media_buyer']))->get('/ads/sync')->assertForbidden();
});

it('filters the log by status and trigger', function () {
    $admin = User::factory()->create(['role' => 'admin']);
    $acc = AdAccount::factory()->meta()->create();
    AdsSyncRun::create(['ad_account_id' => $acc->id, 'platform' => 'meta', 'kind' => 'recent', 'status' => 'ok', 'trigger' => 'schedule', 'started_at' => now()]);
    AdsSyncRun::create(['ad_account_id' => $acc->id, 'platform' => 'meta', 'kind' => 'recent', 'status' => 'error', 'trigger' => 'manual', 'started_at' => now()]);
    $this->actingAs($admin)->get('/ads/sync?status=error&trigger=manual')
        ->assertInertia(fn (Assert $p) => $p->has('runs', 1)->where('runs.0.status', 'error'));
});

it('reports the queue as unsupported when the queue is not redis', function () {
    $admin = User::factory()->create(['role' => 'admin']);
    $this->actingAs($admin)->get('/ads/sync')
        ->assertInertia(fn (Assert $p) => $p->where('now.supported', false)->where('now.waiting', []));
});

it('decodes waiting sync jobs from the raw queue payloads', function () {
    $payload = fn (int $account, int $attempts) => json_encode([
        'displayName' => SyncAdAccount::class, 'attempts' => $attempts,
        'data' => ['command' => serialize(new SyncAdAccount($account, 90, 'backfill', 'manual', 1))],
    ]);
    $other = json_encode(['displayName' => 'App\Jobs\SomethingElse', 'attempts' => 0, 'data' => ['command' => 'x']]);
    $acc = AdAccount::factory()->meta()->create(['name' => 'Cloting']);

    $rows = (new QueueInspector(fn () => [
        [$payload($acc->id, 1), $other],
        [[$payload(5, 2), 1893456000.0]],
    ]))->waiting();

    expect($rows)->toHaveCount(3)
        ->and($rows[0])->toMatchArray(['account_id' => $acc->id, 'account' => 'Cloting', 'kind' => 'backfill', 'days' => 90, 'trigger' => 'manual', 'attempts' => 1, 'available_at' => null])
        ->and($rows[1]['job'])->toBe('SomethingElse')
        ->and($rows[1]['account_id'])->toBeNull()
        ->and($rows[2])->toMatchArray(['account_id' => 5, 'attempts' => 2])
        ->and($rows[2]['available_at'])->not->toBeNull();
});

it('is unsupported without a raw source on a non-redis queue', function () {
    $inspector = new QueueInspector;
    expect($inspector->supported())->toBeFalse()->and($inspector->waiting())->toBe([]);
});

it('unserializes old queued sync jobs that lack the new properties', function () {
    $old = unserialize('O:'.strlen(SyncAdAccount::class).':"'.SyncAdAccount::class.'":2:{s:9:"accountId";i:7;s:4:"days";i:3;}');
    expect($old->trigger)->toBe('schedule')->and($old->triggeredById)->toBeNull();
});
