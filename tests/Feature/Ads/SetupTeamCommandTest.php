<?php

use App\Ads\Commands\SetupTeamCommand;
use App\Ads\Platforms\Data\AccountInfo;
use App\Ads\Platforms\Fake\FakeAdsDriver;
use App\Ads\Sync\SyncAdAccount;
use App\Enums\UserRole;
use App\Models\AdAccount;
use App\Models\AdAccountAssignment;
use App\Models\AdPlatformConnection;
use App\Models\MediaBuyer;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Queue;

/** Meta answers with the three real Le Voile accounts (the plain fake lists demo ids). */
beforeEach(function () {
    app()->bind(FakeAdsDriver::class, fn () => new class extends FakeAdsDriver
    {
        public function accounts(AdPlatformConnection $c): array
        {
            return array_map(fn ($m) => new AccountInfo($m['account'], $m['account_name'], 'EGP', 'Africa/Cairo', 'active', 0.0), SetupTeamCommand::TEAM);
        }
    });
});

it('creates the three buyers with logins, connects Meta and gives each their account from 1 October', function () {
    Queue::fake();

    $this->artisan('ads:setup-team', ['--token' => 'EAAtest'])->assertSuccessful();

    expect(User::where('role', UserRole::MediaBuyer)->count())->toBe(3)
        ->and(MediaBuyer::count())->toBe(3)
        ->and(AdPlatformConnection::where('platform', 'meta')->sole()->credentials)->toBe(['access_token' => 'EAAtest']);

    foreach (SetupTeamCommand::TEAM as $member) {
        $a = AdAccountAssignment::where('ad_account_id', AdAccount::where('external_id', $member['account'])->sole()->id)->sole();
        expect($a->buyer->user->email)->toBe($member['email'])
            ->and($a->starts_on->toDateString())->toBe('2026-10-01')
            ->and($a->ends_on)->toBeNull();
    }
    Queue::assertPushed(SyncAdAccount::class, fn (SyncAdAccount $j) => $j->kind === 'backfill' && $j->days === 90);
});

it('is safe to run again: same rows, passwords kept, the existing connection reused', function () {
    Queue::fake();
    $this->artisan('ads:setup-team', ['--token' => 'EAAtest'])->assertSuccessful();
    $hash = User::where('email', 'bakinam@arena.com')->sole()->password;

    $this->artisan('ads:setup-team')->expectsOutputToContain('(unchanged)')->assertSuccessful();

    expect(User::count())->toBe(3)->and(MediaBuyer::count())->toBe(3)
        ->and(AdPlatformConnection::count())->toBe(1)->and(AdAccountAssignment::count())->toBe(3)
        ->and(User::where('email', 'bakinam@arena.com')->sole()->password)->toBe($hash);
});

it('tells which account is missing from the token and fails', function () {
    Queue::fake();
    app()->bind(FakeAdsDriver::class, fn () => new class extends FakeAdsDriver
    {
        public function accounts(AdPlatformConnection $c): array
        {
            return array_map(fn ($m) => new AccountInfo($m['account'], $m['account_name'], 'EGP', 'Africa/Cairo', 'active', 0.0), array_slice(SetupTeamCommand::TEAM, 0, 2));
        }
    });

    $this->artisan('ads:setup-team', ['--token' => 'EAAtest'])->expectsOutputToContain('Lv Main 22 (act_950240346866068) is not on this token')->assertFailed();

    expect(AdAccountAssignment::count())->toBe(2);
});

it('stops without a token when Meta is not connected yet', function () {
    $this->artisan('ads:setup-team')->expectsQuestion('Meta System User token (ads_read on the three accounts)', '')->assertFailed();

    expect(AdPlatformConnection::count())->toBe(0)->and(MediaBuyer::count())->toBe(3);
});

it('gives each new login a working password', function () {
    Queue::fake();
    $this->artisan('ads:setup-team', ['--token' => 'EAAtest', '--reset-passwords' => true])->assertSuccessful();

    expect(Hash::needsRehash(User::where('email', 'mostafa@arena.com')->sole()->password))->toBeFalse();
});
