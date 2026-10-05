<?php

use App\Ads\AdsSettings;
use App\Ads\Control\AdWriteService;
use App\Ads\Control\Write\WriteDenied;
use App\Ads\Control\Write\WritePolicy;
use App\Ads\Reports\AdsFilter;
use App\Enums\UserRole;
use App\Models\AdAccount;
use App\Models\AdAccountAssignment;
use App\Models\AdPlatformConnection;
use App\Models\MediaBuyer;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

/** Builds one of the matrix users; buyers get an assignment on $acc as named. */
function wpUser(string $kind, AdAccount $acc): User
{
    $role = match (true) {
        str_starts_with($kind, 'admin') => UserRole::Admin,
        str_starts_with($kind, 'supervisor') => UserRole::Supervisor,
        str_starts_with($kind, 'buyer') => UserRole::MediaBuyer,
        $kind === 'content' => UserRole::Content,
        default => UserRole::Moderator,
    };
    $factory = User::factory();
    if (str_ends_with($kind, '_flag')) {
        $factory = $factory->adsAuthority();
    }
    $user = $factory->create(['role' => $role]);
    if (str_starts_with($kind, 'buyer')) {
        $buyer = MediaBuyer::factory()->create(['user_id' => $user->id]);
        if ($kind === 'buyer_assigned') {
            AdAccountAssignment::factory()->create(['ad_account_id' => $acc->id, 'media_buyer_id' => $buyer->id, 'starts_on' => '2026-01-01', 'ends_on' => null]);
        } elseif ($kind === 'buyer_ended') {
            $yesterday = CarbonImmutable::now(AdsFilter::TIMEZONE)->subDay()->toDateString();
            AdAccountAssignment::factory()->create(['ad_account_id' => $acc->id, 'media_buyer_id' => $buyer->id, 'starts_on' => '2026-01-01', 'ends_on' => $yesterday]);
        }
    }

    return $user;
}

function wpCode(callable $fn): ?string
{
    try {
        $fn();

        return null;
    } catch (WriteDenied $e) {
        return $e->errorCode;
    }
}

function wpSwitch(bool $on): void
{
    app(AdsSettings::class)->set('writes_enabled', $on);
}

it('applies scope, then level, then the kill switch for every role on a healthy account', function (string $kind, string $level, string $to, bool $switchOn) {
    $acc = AdAccount::factory()->meta()->create();
    $user = wpUser($kind, $acc);
    wpSwitch($switchOn);

    $inScope = in_array($kind, ['admin_flag', 'admin', 'supervisor', 'supervisor_flag', 'buyer_assigned'], true);
    $authority = in_array($kind, ['admin_flag', 'supervisor_flag'], true);
    $expected = match (true) {
        ! $inScope => 'out_of_scope',
        $level !== 'ad' && ! $authority => 'ads_authority_required',
        ! $switchOn && $to === 'active' => 'writes_disabled',
        default => null,
    };

    expect(wpCode(fn () => app(WritePolicy::class)->authorize($user, $acc, $level, $to, 'propose')))->toBe($expected);
})->with(
    ['admin_flag', 'admin', 'supervisor', 'supervisor_flag', 'buyer_assigned', 'buyer_unassigned', 'buyer_ended', 'content', 'moderator'],
)->with(['campaign', 'adset', 'ad'])->with(['active', 'paused'])->with(['switch on' => true, 'switch off' => false]);

it('refuses account and connection states for Run and Stop alike (Stop is not exempt)', function (string $kind, string $state, ?string $expected, string $to) {
    $acc = match ($state) {
        'google' => AdAccount::factory()->google()->create(),
        default => AdAccount::factory()->meta()->create(),
    };
    match ($state) {
        'inactive' => $acc->update(['is_active' => false]),
        'write_disabled' => $acc->update(['write_enabled' => false]),
        'needs_reconnect' => $acc->connection->update(['status' => 'needs_reconnect']),
        'read_only' => AdPlatformConnection::whereKey($acc->connection_id)->update(['read_only' => true]),
        'disabled' => $acc->connection->update(['status' => 'disabled']),
        default => null,
    };
    $user = wpUser($kind, $acc);

    expect(wpCode(fn () => app(WritePolicy::class)->authorize($user, $acc->fresh(), 'ad', $to, 'confirm')))->toBe($expected);
})->with(['admin_flag', 'buyer_assigned'])->with([
    'ok' => ['ok', null],
    'inactive' => ['inactive', 'out_of_scope'],
    'write_enabled false' => ['write_disabled', 'account_not_writable'],
    'needs_reconnect' => ['needs_reconnect', 'connection_needs_reconnect'],
    'read_only' => ['read_only', 'connection_read_only'],
    'disabled' => ['disabled', 'connection_disabled'],
    'google' => ['google', 'platform_not_writable'],
])->with(['active', 'paused']);

it('lets a buyer Stop an ad with the switch off but refuses the Run with 503', function () {
    $acc = AdAccount::factory()->meta()->create();
    $buyer = wpUser('buyer_assigned', $acc);
    wpSwitch(false);
    $policy = app(WritePolicy::class);

    expect(wpCode(fn () => $policy->authorize($buyer, $acc, 'ad', 'paused', 'propose')))->toBeNull();
    try {
        $policy->authorize($buyer, $acc, 'ad', 'active', 'propose');
        $this->fail('expected a refusal');
    } catch (WriteDenied $e) {
        expect($e->status)->toBe(503)->and($e->errorCode)->toBe('writes_disabled');
    }
});

it('refuses a supervisor without the flag at campaign level for Stop and Run with 403', function (string $to) {
    $acc = AdAccount::factory()->meta()->create();
    $sup = wpUser('supervisor', $acc);

    try {
        app(WritePolicy::class)->authorize($sup, $acc, 'campaign', $to, 'propose');
        $this->fail('expected a refusal');
    } catch (WriteDenied $e) {
        expect($e->status)->toBe(403)->and($e->errorCode)->toBe('ads_authority_required')->and($e->details['level'])->toBe('campaign');
    }
})->with(['active', 'paused']);

it('puts scope before level: a content user on a campaign is out of scope', function () {
    $acc = AdAccount::factory()->meta()->create();
    $content = wpUser('content', $acc);

    expect(wpCode(fn () => app(WritePolicy::class)->authorize($content, $acc, 'campaign', 'paused', 'propose')))->toBe('out_of_scope');
});

it('puts the platform before write_enabled and the kill switch', function () {
    $acc = AdAccount::factory()->google()->create(['write_enabled' => false]);
    $admin = wpUser('admin_flag', $acc);
    wpSwitch(false);

    expect(wpCode(fn () => app(WritePolicy::class)->authorize($admin, $acc, 'ad', 'active', 'propose')))->toBe('platform_not_writable');
});

it('records the phase in the refusal details', function () {
    $acc = AdAccount::factory()->meta()->create(['write_enabled' => false]);
    $admin = wpUser('admin_flag', $acc);

    try {
        app(WritePolicy::class)->authorize($admin, $acc, 'ad', 'paused', 'execute');
        $this->fail('expected a refusal');
    } catch (WriteDenied $e) {
        expect($e->status)->toBe(422)->and($e->details['phase'])->toBe('execute');
    }
});

it('renders the stable refusal shape', function () {
    $e = WriteDenied::make('action_in_progress', ['action_id' => '01ABC']);
    $res = $e->render();
    $body = $res->getData(true);

    expect($res->getStatusCode())->toBe(409)
        ->and($body['code'])->toBe('action_in_progress')
        ->and($body['message'])->toBe(__('ads.errors.action_in_progress'))
        ->and($body['errors']['status'][0])->toBe($body['message'])
        ->and($body['details']['action_id'])->toBe('01ABC');
});

it('maps every refusal code to its HTTP status and has a message in en and ar', function (string $code, int $status) {
    expect(WriteDenied::make($code)->status)->toBe($status);
    foreach (['en', 'ar'] as $locale) {
        expect(trans('ads.errors.'.$code, [], $locale))->not->toBe('ads.errors.'.$code);
    }
})->with([
    ['out_of_scope', 403], ['ads_authority_required', 403], ['not_proposer', 403], ['not_found', 404],
    ['idempotency_key_reused', 409], ['action_in_progress', 409], ['stop_in_progress', 409], ['not_confirmable', 409],
    ['diff_changed', 409], ['precondition_failed', 409], ['proposal_expired', 410], ['validation_failed', 422],
    ['account_not_writable', 422], ['connection_needs_reconnect', 422], ['connection_read_only', 422], ['connection_disabled', 422],
    ['platform_not_writable', 422], ['fake_writer_in_production', 422], ['sandbox_only', 422], ['budget_over_cap', 422],
    ['budget_unreadable', 422], ['currency_mismatch', 422], ['cap_exceeded', 422], ['restart_locked', 422], ['not_reversible', 422],
    ['platform_rejected', 422], ['permission_missing', 422], ['not_applied', 422], ['rate_limited', 429], ['writes_disabled', 503],
]);

it('keeps the AdWriteService checks delegating to the policy', function () {
    $acc = AdAccount::factory()->meta()->create();
    $off = AdAccount::factory()->meta()->create(['write_enabled' => false]);
    $buyer = wpUser('buyer_assigned', $acc);
    $flag = wpUser('admin_flag', $acc);
    $svc = app(AdWriteService::class);

    expect($svc->canWrite($buyer, $acc))->toBeTrue()
        ->and($svc->canWriteMany($buyer, [$acc, $off]))->toBe([$acc->id => true, $off->id => false])
        ->and($svc->inScope($buyer, $off))->toBeFalse()
        ->and($svc->allowedLevels($buyer))->toBe(['ad'])
        ->and($svc->allowedLevels($flag))->toBe(['campaign', 'adset', 'ad'])
        ->and(app(WritePolicy::class)->visibleAccountIds($buyer))->toBe([$acc->id])
        ->and(app(WritePolicy::class)->visibleAccountIds($flag))->toBeNull();
});
