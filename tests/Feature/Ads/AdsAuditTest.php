<?php

use App\Ads\Audit\AdsAudit;
use App\Enums\UserRole;
use App\Models\AdAccount;
use App\Models\AdsAuditLog;
use App\Models\User;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Route;

it('records the acting user, role snapshot and ip from a request', function () {
    $admin = User::factory()->create(['role' => UserRole::Admin]);
    $account = AdAccount::factory()->create();
    Route::middleware('web')->get('/_audit-test', function () use ($account) {
        AdsAudit::record('test.action', $account, ['a' => 1], ['a' => 2], ['note' => 'x']);

        return 'ok';
    });

    $this->actingAs($admin)->get('/_audit-test', ['REMOTE_ADDR' => '203.0.113.9'])->assertOk();

    $row = AdsAuditLog::first();
    expect($row->actor_type)->toBe('user')
        ->and($row->actor_user_id)->toBe($admin->id)
        ->and($row->actor_role)->toBe(UserRole::Admin->value)
        ->and($row->action)->toBe('test.action')
        ->and($row->subject_type)->toBe('AdAccount')
        ->and($row->subject_id)->toBe($account->id)
        ->and($row->ad_account_id)->toBe($account->id)
        ->and($row->before)->toBe(['a' => 1])
        ->and($row->after)->toBe(['a' => 2])
        ->and($row->meta['ip'])->toBe('203.0.113.9')
        ->and($row->at)->not->toBeNull();
});

it('records cli as the actor inside an artisan command', function () {
    Artisan::command('ads-audit:probe', function () {
        AdsAudit::record('cli.action');
    })->purpose('probe');

    $this->artisan('ads-audit:probe')->assertSuccessful();

    $row = AdsAuditLog::first();
    expect($row->actor_type)->toBe('cli')->and($row->actor_user_id)->toBeNull();
});

it('stores scrubbed text and never a raw token', function () {
    $row = AdsAudit::record('x', null, null, ['note' => 'Bearer EAAB123456789'], ['error' => 'failed access_token=EAAB123456789 now']);

    $row = $row->fresh();
    expect(json_encode($row->meta))->not->toContain('EAAB123456789')
        ->and(json_encode($row->after))->not->toContain('EAAB123456789')
        ->and($row->meta['error'])->toContain('access_token=***');
});

it('is append-only', function () {
    $row = AdsAudit::record('x');

    expect(fn () => $row->update(['action' => 'y']))->toThrow(LogicException::class)
        ->and(fn () => $row->delete())->toThrow(LogicException::class);
});

it('fingerprints a secret as the first 8 hex of its sha256', function () {
    expect(AdsAudit::fingerprint('abc'))->toBe('ba7816bf');
});

it('masks values by key name', function () {
    $row = AdsAudit::record('x', null, null, ['access_token' => 'EAABbare123', 'nested' => ['api_key' => 'k1', 'ok' => 'fine']])->fresh();

    expect($row->after['access_token'])->toBe('***')
        ->and($row->after['nested']['api_key'])->toBe('***')
        ->and($row->after['nested']['ok'])->toBe('fine');
});
