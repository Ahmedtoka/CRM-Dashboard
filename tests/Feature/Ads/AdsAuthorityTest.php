<?php

use App\Ads\Control\AdWriteService;
use App\Enums\UserRole;
use App\Models\AdsAuditLog;
use App\Models\User;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;

beforeEach(function () {
    Http::preventStrayRequests();
});

function aaMigration(): object
{
    return require database_path('migrations/2026_10_07_100040_add_ads_authority_to_users.php');
}

it('seeds the flag for admins only when the column is added, with one audit row', function () {
    $admin = User::factory()->create(['role' => UserRole::Admin]);
    $offAdmin = User::factory()->create(['role' => UserRole::Admin, 'is_active' => false]);
    $sup = User::factory()->create(['role' => UserRole::Supervisor]);
    $buyer = User::factory()->create(['role' => UserRole::MediaBuyer]);
    $m = aaMigration();
    $m->down();
    expect(Schema::hasColumn('users', 'ads_authority'))->toBeFalse();

    $m->up();
    $m->up(); // guarded

    expect($admin->fresh()->ads_authority)->toBeTrue()
        ->and($offAdmin->fresh()->ads_authority)->toBeTrue()
        ->and($offAdmin->fresh()->hasAdsAuthority())->toBeFalse() // a deactivated admin holds nothing
        ->and($sup->fresh()->ads_authority)->toBeFalse()
        ->and($buyer->fresh()->ads_authority)->toBeFalse();
    $audit = AdsAuditLog::where('action', 'users.ads_authority_seeded')->sole();
    expect($audit->after)->toBe(['user_ids' => [$admin->id, $offAdmin->id]]);
});

it('reads allowed levels from the flag, not the role', function () {
    $svc = app(AdWriteService::class);

    expect($svc->allowedLevels(User::factory()->adsAuthority()->create(['role' => UserRole::Admin])))->toBe(['campaign', 'adset', 'ad'])
        ->and($svc->allowedLevels(User::factory()->create(['role' => UserRole::Admin])))->toBe(['ad'])
        ->and($svc->allowedLevels(User::factory()->adsAuthority()->create(['role' => UserRole::Supervisor])))->toBe(['campaign', 'adset', 'ad'])
        ->and($svc->allowedLevels(User::factory()->create(['role' => UserRole::Supervisor])))->toBe(['ad'])
        ->and($svc->allowedLevels(User::factory()->adsAuthority()->create(['role' => UserRole::Admin, 'is_active' => false])))->toBe(['ad']);
});

it('grants the flag to a supervisor by email, audited', function () {
    $sup = User::factory()->create(['role' => UserRole::Supervisor, 'email' => 'sup@example.test']);

    expect(Artisan::call('ads:authority', ['--grant' => 'sup@example.test']))->toBe(0);

    expect($sup->fresh()->hasAdsAuthority())->toBeTrue();
    $audit = AdsAuditLog::where('action', 'user.ads_authority_changed')->sole();
    expect($audit->subject_id)->toBe($sup->id)
        ->and($audit->before)->toBe(['ads_authority' => false])->and($audit->after)->toBe(['ads_authority' => true]);

    expect(Artisan::call('ads:authority', ['--grant' => (string) $sup->id]))->toBe(0); // already a holder: no new row
    expect(AdsAuditLog::where('action', 'user.ads_authority_changed')->count())->toBe(1);
});

it('refuses to grant the flag to a media buyer or a moderator', function () {
    $buyer = User::factory()->create(['role' => UserRole::MediaBuyer]);
    $mod = User::factory()->create(['role' => UserRole::Moderator]);

    expect(Artisan::call('ads:authority', ['--grant' => $buyer->email]))->toBe(1)
        ->and(Artisan::call('ads:authority', ['--grant' => (string) $mod->id]))->toBe(1)
        ->and($buyer->fresh()->ads_authority)->toBeFalse()->and($mod->fresh()->ads_authority)->toBeFalse()
        ->and(AdsAuditLog::count())->toBe(0);
});

it('refuses an unknown user', function () {
    expect(Artisan::call('ads:authority', ['--grant' => 'nobody@example.test']))->toBe(1);
});

it('refuses to revoke the last active holder and revokes when another holder remains', function () {
    $a = User::factory()->adsAuthority()->create(['role' => UserRole::Admin]);
    User::factory()->adsAuthority()->create(['role' => UserRole::Admin, 'is_active' => false]); // does not count

    expect(Artisan::call('ads:authority', ['--revoke' => $a->email]))->toBe(1)
        ->and($a->fresh()->ads_authority)->toBeTrue();

    $b = User::factory()->adsAuthority()->create(['role' => UserRole::Supervisor]);
    expect(Artisan::call('ads:authority', ['--revoke' => $a->email]))->toBe(0)
        ->and($a->fresh()->ads_authority)->toBeFalse()->and($b->fresh()->ads_authority)->toBeTrue()
        ->and(AdsAuditLog::where('action', 'user.ads_authority_changed')->count())->toBe(1);
});

it('lists the holders', function () {
    User::factory()->adsAuthority()->create(['role' => UserRole::Admin, 'name' => 'Holder One']);
    User::factory()->create(['role' => UserRole::Admin, 'name' => 'Not Holder']);

    expect(Artisan::call('ads:authority', ['--list' => true]))->toBe(0);
    expect(Artisan::output())->toContain('Holder One')->not->toContain('Not Holder');
});

it('never sets the flag through mass assignment', function () {
    $u = User::create(['name' => 'X', 'email' => 'x@example.test', 'password' => 'secret-pass', 'role' => UserRole::Admin, 'ads_authority' => true]);

    expect($u->fresh()->ads_authority)->toBeFalse();
    $u->fill(['ads_authority' => true])->save();
    expect($u->fresh()->ads_authority)->toBeFalse();
});
