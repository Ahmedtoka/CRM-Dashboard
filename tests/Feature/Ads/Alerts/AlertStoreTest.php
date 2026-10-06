<?php

use App\Ads\Alerts\AlertStore;
use App\Ads\Alerts\Family;
use App\Ads\Alerts\Finding;
use App\Ads\Alerts\Rule;
use App\Ads\Alerts\RuleContext;
use App\Models\Ad;
use App\Models\AdsAlert;
use App\Models\AdWriteAction;
use App\Models\MediaBuyer;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Tests\Support\AlertWorld as W;

beforeEach(fn () => W::freeze());

function stRule(string $id = 'test.rule', int $hours = 48): Rule
{
    return new class($id, $hours) implements Rule
    {
        public function __construct(private string $ruleId, private int $hours) {}

        public function id(): string
        {
            return $this->ruleId;
        }

        public function schedule(): string
        {
            return Rule::DAILY;
        }

        public function isPerformance(): bool
        {
            return false;
        }

        public function cooldownUntil(CarbonImmutable $now): CarbonImmutable
        {
            return $now->addHours($this->hours);
        }

        public function evaluate(RuleContext $ctx): array
        {
            return [];
        }
    };
}

function stFinding(Ad $ad, string $severity = 'high', string $rule = 'test.rule', string $action = 'stop'): Finding
{
    return Finding::forAd($rule, $ad, Family::SALES, $severity, $action, 120.0, 'spend_no_result', ['k' => 3], ['x' => 1]);
}

it('opens once, then refreshes the same row and logs an escalation', function () {
    $ad = W::ad(W::account());
    $buyer = W::buyer($ad->account);
    $store = app(AlertStore::class);

    $first = $store->sync($ad->ad_account_id, [stRule()], [stFinding($ad)], [$ad->id], W::freeze());
    $second = $store->sync($ad->ad_account_id, [stRule()], [stFinding($ad, 'critical')], [$ad->id], W::freeze('2026-10-06 11:00:00'));

    $row = AdsAlert::sole();
    expect($first['opened'])->toBe([$row->id])->and($second)->toBe(['opened' => [], 'refreshed' => 1, 'resolved' => 0])
        ->and($row->severity)->toBe('critical')->and($row->state)->toBe('open')->and($row->dedupe_key)->toBe($row->fingerprint)
        ->and($row->buyer_id)->toBe(MediaBuyer::query()->where('user_id', $buyer->id)->value('id'))
        ->and($row->events()->pluck('event')->all())->toBe(['fired', 'escalated']);
});

it('resolves a finding that is gone and holds it back until the cooldown passes', function () {
    $ad = W::ad(W::account());
    $store = app(AlertStore::class);
    $store->sync($ad->ad_account_id, [stRule()], [stFinding($ad)], [$ad->id], W::freeze());

    $r = $store->sync($ad->ad_account_id, [stRule()], [], [$ad->id], W::freeze('2026-10-07 10:00:00'));
    expect($r['resolved'])->toBe(1)->and(AdsAlert::sole()->state)->toBe('resolved')
        ->and(AdsAlert::sole()->resolved_reason)->toBe('condition_cleared')->and(AdsAlert::sole()->dedupe_key)->toBeNull();

    $store->sync($ad->ad_account_id, [stRule()], [stFinding($ad)], [$ad->id], W::freeze('2026-10-08 10:00:00'));
    expect(AdsAlert::count())->toBe(1);

    $store->sync($ad->ad_account_id, [stRule()], [stFinding($ad)], [$ad->id], W::freeze('2026-10-09 10:01:00'));
    expect(AdsAlert::count())->toBe(2)->and(AdsAlert::query()->where('state', 'open')->count())->toBe(1);
});

it('says not_running when the ad stopped outside the CRM, and never resolves rules that did not run', function () {
    $ad = W::ad(W::account());
    $store = app(AlertStore::class);
    $store->sync($ad->ad_account_id, [stRule(), stRule('other.rule')], [stFinding($ad), stFinding($ad, 'high', 'other.rule')], [$ad->id], W::freeze());

    $store->sync($ad->ad_account_id, [stRule()], [], [], W::freeze());

    expect(AdsAlert::where('rule_id', 'test.rule')->sole()->resolved_reason)->toBe('not_running')
        ->and(AdsAlert::where('rule_id', 'other.rule')->sole()->state)->toBe('open');
});

it('keeps a snoozed row snoozed while it still fires and wakes it when the time passes', function () {
    $ad = W::ad(W::account());
    $store = app(AlertStore::class);
    $store->sync($ad->ad_account_id, [stRule()], [stFinding($ad)], [$ad->id], W::freeze());
    $store->snooze(AdsAlert::sole(), W::authority(), CarbonImmutable::parse('2026-10-07 09:00', 'Africa/Cairo'));

    $store->sync($ad->ad_account_id, [stRule()], [stFinding($ad)], [$ad->id], W::freeze('2026-10-06 18:00:00'));
    expect(AdsAlert::sole()->state)->toBe('snoozed');

    expect($store->wakeSnoozed(W::freeze('2026-10-07 09:00:00')))->toBe(1)->and(AdsAlert::sole()->state)->toBe('open')
        ->and(AdsAlert::sole()->events()->pluck('event')->all())->toBe(['fired', 'snoozed', 'unsnoozed']);
});

it('mutes a dismissed finding for seven days and records the reason', function () {
    $ad = W::ad(W::account());
    $store = app(AlertStore::class);
    $store->sync($ad->ad_account_id, [stRule('test.rule', 1)], [stFinding($ad)], [$ad->id], W::freeze());
    $store->dismiss(AdsAlert::sole(), W::authority(), 'wrong_numbers', 'Meta lags', W::freeze());

    $store->sync($ad->ad_account_id, [stRule('test.rule', 1)], [stFinding($ad)], [$ad->id], W::freeze('2026-10-12 10:00:00'));
    expect(AdsAlert::count())->toBe(1)->and(AdsAlert::sole()->dismiss_reason)->toBe('wrong_numbers');

    $store->sync($ad->ad_account_id, [stRule('test.rule', 1)], [stFinding($ad)], [$ad->id], W::freeze('2026-10-13 10:01:00'));
    expect(AdsAlert::count())->toBe(2);
});

it('closes the ad alerts as acted when a Stop succeeds, and only run alerts when a Run succeeds', function () {
    $ad = W::ad(W::account());
    $store = app(AlertStore::class);
    $store->sync($ad->ad_account_id, [stRule(), stRule('restock.rule')], [stFinding($ad), stFinding($ad, 'high', 'restock.rule', 'run')], [$ad->id], W::freeze());
    $stop = AdWriteAction::factory()->create(['state' => AdWriteAction::SUCCEEDED, 'ad_account_id' => $ad->ad_account_id, 'target_level' => 'ad', 'target_external_id' => $ad->external_id, 'to_status' => 'paused']);

    expect($store->actOnWrite($stop))->toBe(1)
        ->and(AdsAlert::where('rule_id', 'test.rule')->sole()->state)->toBe('acted')
        ->and(AdsAlert::where('rule_id', 'test.rule')->sole()->write_action_id)->toBe($stop->id)
        ->and(AdsAlert::where('rule_id', 'restock.rule')->sole()->state)->toBe('open');

    $run = AdWriteAction::factory()->create(['state' => AdWriteAction::SUCCEEDED, 'ad_account_id' => $ad->ad_account_id, 'target_level' => 'ad', 'target_external_id' => $ad->external_id, 'to_status' => 'active']);
    expect($store->actOnWrite($run))->toBe(1)->and(AdsAlert::where('rule_id', 'restock.rule')->sole()->state)->toBe('acted');
});

it('reads the account buyer once per evaluation, not once per new alert', function () {
    $acc = W::account();
    W::buyer($acc);
    $ads = [W::ad($acc), W::ad($acc), W::ad($acc)];
    $store = app(AlertStore::class);

    DB::enableQueryLog();
    $store->sync($acc->id, [stRule()], array_map(fn (Ad $ad) => stFinding($ad), $ads), array_map(fn (Ad $ad) => $ad->id, $ads), W::freeze());
    $reads = collect(DB::getQueryLog())->filter(fn (array $q) => str_contains($q['query'], 'from "ad_accounts"'))->count();

    expect(AdsAlert::count())->toBe(3)->and($reads)->toBe(1)
        ->and(AdsAlert::query()->distinct()->pluck('buyer_id')->filter()->count())->toBe(1);
});
