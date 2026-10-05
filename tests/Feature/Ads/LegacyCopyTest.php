<?php

use App\Enums\UserRole;
use App\Models\AdAccount;
use App\Models\AdPlatformConnection;
use App\Models\AdWriteAction;
use App\Models\AdWriteStep;
use App\Models\User;
use Illuminate\Support\Facades\DB;

function lcMigration(): object
{
    return require database_path('migrations/2026_10_07_100050_copy_ad_actions_to_ad_write_actions.php');
}

/** @param array<string, mixed> $over */
function lcRow(array $over): int
{
    return DB::table('ad_actions')->insertGetId($over + [
        'platform' => 'meta', 'level' => 'ad', 'external_id' => '111', 'name' => 'Ad', 'from_status' => 'ACTIVE', 'to_status' => 'PAUSED',
        'reason' => null, 'result' => 'ok', 'error' => null, 'created_at' => '2026-09-20 10:00:00', 'updated_at' => '2026-09-20 10:00:00',
    ]);
}

beforeEach(function () {
    $this->acc = AdAccount::factory()->meta()->create(['name' => 'LV Main']);
    $this->tt = AdAccount::factory()->tiktok()->create(['name' => 'TT']);
    $this->user = User::factory()->create(['role' => UserRole::Admin]);
    $this->ids = [
        'stop' => lcRow(['user_id' => $this->user->id, 'ad_account_id' => $this->acc->id, 'account_name' => 'LV Main', 'reason' => 'ROAS low']),
        'run' => lcRow(['user_id' => $this->user->id, 'platform' => 'tiktok', 'ad_account_id' => $this->tt->id, 'account_name' => 'TT', 'external_id' => '222', 'from_status' => 'DISABLE', 'to_status' => 'ENABLE', 'created_at' => '2026-09-21 11:00:00', 'updated_at' => '2026-09-21 11:00:00']),
        'level' => lcRow(['user_id' => $this->user->id, 'ad_account_id' => $this->acc->id, 'level' => 'campaign', 'external_id' => '333', 'result' => 'error', 'error' => 'level_not_allowed']),
        'platform' => lcRow(['user_id' => $this->user->id, 'ad_account_id' => $this->acc->id, 'external_id' => '444', 'to_status' => 'ACTIVE', 'result' => 'error', 'error' => '(#100) Invalid parameter']),
        'nouser' => lcRow(['user_id' => null, 'ad_account_id' => $this->acc->id, 'external_id' => '555']),
    ];
});

it('copies every ad_actions row into ad_write_actions as a legacy row, with states, codes and timestamps mapped', function () {
    lcMigration()->up();

    expect(DB::table('ad_actions')->count())->toBe(5)->and(AdWriteAction::where('source', 'legacy')->count())->toBe(5);
    $row = fn (string $k) => AdWriteAction::where('source_ref', 'ad_actions:'.$this->ids[$k])->sole();

    $stop = $row('stop');
    expect($stop->state)->toBe('succeeded')->and($stop->type)->toBe('set_status')->and($stop->to_status)->toBe('paused')
        ->and($stop->from_status)->toBe('ACTIVE')->and($stop->target_level)->toBe('ad')->and($stop->target_external_id)->toBe('111')
        ->and($stop->target_key)->toBe($this->acc->id.':ad:111')->and($stop->target_name)->toBe('Ad')->and($stop->account_name)->toBe('LV Main')
        ->and($stop->reason)->toBe('ROAS low')->and($stop->proposed_by_id)->toBe($this->user->id)->and($stop->confirmed_by_id)->toBe($this->user->id)
        ->and($stop->idempotency_key)->toBe('legacy:ad_actions:'.$this->ids['stop'])->and($stop->open_business_key)->toBeNull()
        ->and($stop->params)->toBe(['to' => 'paused'])->and($stop->diff)->toBe([['path' => 'status', 'before' => 'ACTIVE', 'after' => 'PAUSED']])
        ->and($stop->diff_hash)->toHaveLength(64)->and($stop->public_id)->toHaveLength(26)
        ->and($stop->created_at->toDateTimeString())->toBe('2026-09-20 10:00:00')->and($stop->confirmed_at->toDateTimeString())->toBe('2026-09-20 10:00:00')
        ->and($stop->finished_at->toDateTimeString())->toBe('2026-09-20 10:00:00');
    $step = AdWriteStep::where('ad_write_action_id', $stop->id)->sole();
    expect($step->state)->toBe('succeeded')->and($step->seq)->toBe(1)->and($step->request_sent_at)->toBeNull();

    $run = $row('run');
    expect($run->state)->toBe('succeeded')->and($run->to_status)->toBe('active')->and($run->platform)->toBe('tiktok')
        ->and($run->diff[0]['after'])->toBe('ACTIVE')->and($run->created_at->toDateTimeString())->toBe('2026-09-21 11:00:00');

    $level = $row('level');
    expect($level->state)->toBe('failed')->and($level->error_code)->toBe('level_not_allowed')->and($level->target_level)->toBe('campaign')
        ->and(AdWriteStep::where('ad_write_action_id', $level->id)->count())->toBe(0); // refused before any platform call

    $platform = $row('platform');
    expect($platform->state)->toBe('failed')->and($platform->error_code)->toBe('platform_rejected')
        ->and($platform->error_message)->toBe('(#100) Invalid parameter')->and($platform->to_status)->toBe('active')
        ->and(AdWriteStep::where('ad_write_action_id', $platform->id)->sole()->state)->toBe('failed');

    $nouser = $row('nouser');
    expect($nouser->proposed_by_id)->toBeNull()->and($nouser->confirmed_by_id)->toBeNull()->and($nouser->state)->toBe('succeeded');
});

it('is idempotent on re-run and down() removes only the legacy rows', function () {
    $pipeline = AdWriteAction::factory()->create(); // a pipeline row must survive down()
    lcMigration()->up();
    lcMigration()->up();

    expect(AdWriteAction::where('source', 'legacy')->count())->toBe(5);

    lcMigration()->down();

    expect(AdWriteAction::where('source', 'legacy')->count())->toBe(0)->and(AdWriteStep::count())->toBe(0)
        ->and(AdWriteAction::whereKey($pipeline->id)->exists())->toBeTrue()
        ->and(DB::table('ad_actions')->count())->toBe(5);
});

it('archives a connection whose only history is in the new pipeline instead of deleting it', function () {
    $c = AdPlatformConnection::factory()->meta()->create();
    $acc = AdAccount::factory()->meta()->create(['connection_id' => $c->id]);
    AdWriteAction::factory()->succeeded()->create(['ad_account_id' => $acc->id]);
    $admin = User::factory()->create(['role' => UserRole::Admin]);

    $this->actingAs($admin)->delete("/ads/connections/{$c->id}")->assertRedirect();

    expect(AdPlatformConnection::whereKey($c->id)->exists())->toBeTrue()->and($c->fresh()->status)->toBe('disabled');
});
