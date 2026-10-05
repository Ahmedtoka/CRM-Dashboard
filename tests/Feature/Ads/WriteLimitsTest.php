<?php

use App\Ads\AdsSettings;
use App\Ads\Control\Write\WriteLimits;
use App\Enums\UserRole;
use App\Models\AdAccount;
use App\Models\AdsAuditLog;
use App\Models\User;
use Illuminate\Support\Facades\Artisan;

function wlLimits(?User $u = null, ?AdAccount $a = null): array
{
    return app(WriteLimits::class)->for($u, $a);
}

it('defaults to the config values (EGP 20,000, 20 / 30 activations, 7-day lock)', function () {
    expect(wlLimits())->toBe([
        'max_daily_budget_minor' => 2000000,
        'cap_currency' => 'EGP',
        'activations_per_user_day' => 20,
        'activations_per_account_day' => 30,
        'restart_lock_days' => 7,
        'learning_note_days' => 7,
    ]);

    config(['crm.ads.write.limits.max_daily_budget_minor' => 123]);
    expect(wlLimits()['max_daily_budget_minor'])->toBe(123);
});

it('resolves user over account over global over config', function () {
    $acc = AdAccount::factory()->meta()->create();
    $other = AdAccount::factory()->meta()->create();
    $u = User::factory()->create(['role' => UserRole::MediaBuyer]);
    $v = User::factory()->create(['role' => UserRole::MediaBuyer]);

    Artisan::call('ads:write-limits', ['--set' => ['max_daily_budget_minor=3000000', 'activations_per_user_day=10']]);
    expect(wlLimits($u, $acc)['max_daily_budget_minor'])->toBe(3000000)
        ->and(wlLimits($u, $acc)['activations_per_user_day'])->toBe(10);

    Artisan::call('ads:write-limits', ['--account' => (string) $acc->id, '--set' => ['max_daily_budget_minor=4000000', 'activations_per_account_day=5']]);
    expect(wlLimits($u, $acc)['max_daily_budget_minor'])->toBe(4000000)
        ->and(wlLimits($u, $other)['max_daily_budget_minor'])->toBe(3000000)
        ->and(wlLimits($u, $acc)['activations_per_account_day'])->toBe(5)
        // the per-user cap is never taken from the account level
        ->and(wlLimits($u, $acc)['activations_per_user_day'])->toBe(10);

    Artisan::call('ads:write-limits', ['--user' => $u->email, '--set' => ['max_daily_budget_minor=5000000', 'activations_per_user_day=2']]);
    expect(wlLimits($u, $acc)['max_daily_budget_minor'])->toBe(5000000)
        ->and(wlLimits($v, $acc)['max_daily_budget_minor'])->toBe(4000000)
        ->and(wlLimits($u, $acc)['activations_per_user_day'])->toBe(2)
        // the per-account cap is never taken from the user level
        ->and(wlLimits($u, $acc)['activations_per_account_day'])->toBe(5);
});

it('refuses the per-user cap on an account and the per-account cap on a user; resolution ignores them if stored', function () {
    $acc = AdAccount::factory()->meta()->create();
    $u = User::factory()->create();

    expect(Artisan::call('ads:write-limits', ['--account' => (string) $acc->id, '--set' => ['activations_per_user_day=1']]))->toBe(1)
        ->and(Artisan::call('ads:write-limits', ['--user' => (string) $u->id, '--set' => ['activations_per_account_day=1']]))->toBe(1);

    app(AdsSettings::class)->set(WriteLimits::SETTING, [
        'global' => [], 'accounts' => [(string) $acc->id => ['activations_per_user_day' => 1]], 'users' => [(string) $u->id => ['activations_per_account_day' => 1]],
    ]);
    expect(wlLimits($u, $acc)['activations_per_user_day'])->toBe(20)
        ->and(wlLimits($u, $acc)['activations_per_account_day'])->toBe(30);
});

it('finds an account by its act_ external id and a user by id', function () {
    $acc = AdAccount::factory()->meta()->create(['external_id' => 'act_777']);
    $u = User::factory()->create();

    expect(Artisan::call('ads:write-limits', ['--account' => 'act_777', '--set' => ['restart_lock_days=3']]))->toBe(0)
        ->and(Artisan::call('ads:write-limits', ['--user' => (string) $u->id, '--set' => ['learning_note_days=9']]))->toBe(0);

    expect(wlLimits(null, $acc)['restart_lock_days'])->toBe(3)
        ->and(wlLimits($u, null)['learning_note_days'])->toBe(9);
});

it('refuses a negative number, a non-integer, a bad currency and an unknown key, changing nothing', function (array $set) {
    expect(Artisan::call('ads:write-limits', ['--set' => $set]))->toBe(1);
    expect(app(AdsSettings::class)->get(WriteLimits::SETTING))->toBeNull()
        ->and(AdsAuditLog::where('action', 'settings.write_limits_changed')->count())->toBe(0);
})->with([
    'negative' => [['max_daily_budget_minor=-5']],
    'decimal' => [['max_daily_budget_minor=1.5']],
    'currency' => [['cap_currency=egp']],
    'unknown key' => [['max_lifetime=4']],
    'no value' => [['restart_lock_days']],
    'one bad among good' => [['restart_lock_days=2', 'bogus=1']],
]);

it('refuses an unknown account or user and --account with --user', function () {
    $acc = AdAccount::factory()->meta()->create();
    $u = User::factory()->create();

    expect(Artisan::call('ads:write-limits', ['--account' => '999999', '--set' => ['restart_lock_days=2']]))->toBe(1)
        ->and(Artisan::call('ads:write-limits', ['--user' => 'nobody@example.test', '--set' => ['restart_lock_days=2']]))->toBe(1)
        ->and(Artisan::call('ads:write-limits', ['--account' => (string) $acc->id, '--user' => (string) $u->id, '--set' => ['restart_lock_days=2']]))->toBe(1);
});

it('writes one audit row per change with the scope before and after; a no-op writes none', function () {
    $acc = AdAccount::factory()->meta()->create();

    Artisan::call('ads:write-limits', ['--account' => (string) $acc->id, '--set' => ['max_daily_budget_minor=3000000', 'cap_currency=USD']]);
    Artisan::call('ads:write-limits', ['--account' => (string) $acc->id, '--set' => ['max_daily_budget_minor=3000000']]);

    $rows = AdsAuditLog::where('action', 'settings.write_limits_changed')->get();
    expect($rows)->toHaveCount(1)
        ->and($rows[0]->before)->toBe(['limits' => []])
        ->and($rows[0]->after)->toBe(['limits' => ['max_daily_budget_minor' => 3000000, 'cap_currency' => 'USD']])
        ->and($rows[0]->meta['scope'])->toBe('account')
        ->and($rows[0]->ad_account_id)->toBe($acc->id);
});

it('--clear removes the key from that scope only', function () {
    $acc = AdAccount::factory()->meta()->create();
    Artisan::call('ads:write-limits', ['--set' => ['max_daily_budget_minor=3000000']]);
    Artisan::call('ads:write-limits', ['--account' => (string) $acc->id, '--set' => ['max_daily_budget_minor=4000000', 'restart_lock_days=2']]);

    expect(Artisan::call('ads:write-limits', ['--account' => (string) $acc->id, '--clear' => ['max_daily_budget_minor']]))->toBe(0);

    expect(wlLimits(null, $acc)['max_daily_budget_minor'])->toBe(3000000)
        ->and(wlLimits(null, $acc)['restart_lock_days'])->toBe(2)
        ->and(app(AdsSettings::class)->get(WriteLimits::SETTING)['global'])->toBe(['max_daily_budget_minor' => 3000000])
        ->and(AdsAuditLog::where('action', 'settings.write_limits_changed')->count())->toBe(3);

    expect(Artisan::call('ads:write-limits', ['--clear' => ['nope']]))->toBe(1);
});

it('--list prints the effective values in EGP with the minor value beside them', function () {
    $acc = AdAccount::factory()->meta()->create(['name' => 'Le Voile Main']);
    Artisan::call('ads:write-limits', ['--account' => (string) $acc->id, '--set' => ['max_daily_budget_minor=3500050']]);

    Artisan::call('ads:write-limits', ['--list' => true]);
    $out = Artisan::output();

    expect($out)->toContain('2000000 minor (EGP 20,000.00)')
        ->toContain('3500050 minor (EGP 35,000.50)')
        ->toContain('Le Voile Main')
        ->toContain('activations_per_user_day');
});
