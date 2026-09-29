<?php

use App\Enums\UserRole;
use App\Models\QueueSetting;
use App\Models\User;
use Illuminate\Support\Facades\Hash;

it('creates فاطمة as leader of every shift and seven moderators on every platform', function () {
    $this->artisan('queue:setup-team', ['--domain' => 'team.test'])->assertSuccessful();

    $fatma = User::query()->where('email', 'fatma@team.test')->firstOrFail();
    expect($fatma->name)->toBe('فاطمة')
        ->and($fatma->role)->toBe(UserRole::Supervisor)
        ->and($fatma->is_active)->toBeTrue();

    $mods = User::query()->where('role', 'moderator')->where('email', 'like', '%@team.test')->get();
    expect($mods->pluck('name')->all())->toEqualCanonicalizing(['زينب', 'نرمين', 'منار', 'راندة', 'إسراء', 'إنجي', 'هيماء'])
        ->and($mods->pluck('name'))->not->toContain('ميار');

    foreach ($mods->push($fatma) as $u) {
        expect($u->userPlatforms()->pluck('platform')->map(fn ($p) => $p instanceof BackedEnum ? $p->value : $p)->all())
            ->toEqualCanonicalizing(['facebook', 'instagram', 'whatsapp', 'tiktok']);
    }

    $settings = QueueSetting::current();
    expect(collect($settings->shiftTemplates())->pluck('leader_user_id')->unique()->all())->toBe([$fatma->id])
        ->and($settings->default_roster['morning'])->toEqualCanonicalizing($mods->where('role', UserRole::Moderator)->pluck('id')->all())
        ->and($settings->enabled)->toBeFalse();
});

it('keeps existing accounts and their passwords when run again', function () {
    $this->artisan('queue:setup-team', ['--domain' => 'team.test'])->assertSuccessful();
    $zeinab = User::query()->where('email', 'zeinab@team.test')->firstOrFail();
    $zeinab->forceFill(['password' => Hash::make('kept-by-owner'), 'name' => 'زينب علي', 'is_active' => false])->save();

    $this->artisan('queue:setup-team', ['--domain' => 'team.test'])->assertSuccessful();

    $zeinab->refresh();
    expect(Hash::check('kept-by-owner', $zeinab->password))->toBeTrue()
        ->and($zeinab->name)->toBe('زينب علي')
        ->and($zeinab->is_active)->toBeTrue()
        ->and(User::query()->where('email', 'like', '%@team.test')->count())->toBe(8)
        ->and($zeinab->userPlatforms()->count())->toBe(4);
});

it('gives existing accounts a new password only when asked', function () {
    $this->artisan('queue:setup-team', ['--domain' => 'team.test'])->assertSuccessful();
    User::query()->where('email', 'fatma@team.test')->firstOrFail()->forceFill(['password' => Hash::make('old-one-123')])->save();

    $this->artisan('queue:setup-team', ['--domain' => 'team.test', '--reset-passwords' => true])->assertSuccessful();

    expect(Hash::check('old-one-123', User::query()->where('email', 'fatma@team.test')->firstOrFail()->password))->toBeFalse();
});
