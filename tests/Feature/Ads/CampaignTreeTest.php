<?php

use App\Ads\Buyers\AssignmentService;
use App\Ads\Reports\AdsFilter;
use App\Ads\Reports\CampaignTree;
use App\Enums\OrderStatus;
use App\Enums\UserRole;
use App\Models\Ad;
use App\Models\AdAccount;
use App\Models\AdCampaign;
use App\Models\AdDailyMetric;
use App\Models\AdSet;
use App\Models\Customer;
use App\Models\MediaBuyer;
use App\Models\Order;
use App\Models\User;
use Carbon\CarbonImmutable;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    config(['crm.ads.drivers.meta' => 'fake', 'crm.ads.drivers.tiktok' => 'fake', 'crm.ads.drivers.google' => 'fake']);
    $this->withoutVite();
});

function ctRange(array $extra = []): AdsFilter
{
    return new AdsFilter(CarbonImmutable::parse('2026-09-01'), CarbonImmutable::parse('2026-09-30'), ...$extra);
}

function ctAd(AdAccount $acc, ?AdCampaign $campaign, ?AdSet $set, string $name, array $metric, string $date = '2026-09-10'): Ad
{
    $ad = Ad::factory()->for($acc, 'account')->create([
        'name' => $name, 'ad_campaign_id' => $campaign?->id, 'ad_set_id' => $set?->id,
    ]);
    AdDailyMetric::factory()->create(array_merge([
        'ad_id' => $ad->id, 'ad_account_id' => $acc->id, 'date' => $date,
        'spend' => 0, 'impressions' => 0, 'clicks' => 0, 'reach' => 0, 'purchases' => 0, 'purchase_value' => 0,
    ], $metric));

    return $ad;
}

/** @return array{acc:AdAccount, c1:AdCampaign, c2:AdCampaign, s1:AdSet, s2:AdSet, s3:AdSet, a1:Ad, a2:Ad, a3:Ad} */
function ctWorld(): array
{
    $acc = AdAccount::factory()->meta()->create(['name' => 'LV Main']);
    $c1 = AdCampaign::factory()->for($acc, 'account')->create(['name' => 'LV | Black Abaya | Sales | Ahmed | 261004']);
    $c2 = AdCampaign::factory()->for($acc, 'account')->create(['name' => 'Sales 1']);
    $s1 = AdSet::factory()->for($c1, 'campaign')->create(['name' => 'Broad | EG | Advantage+']);
    $s2 = AdSet::factory()->for($c1, 'campaign')->create(['name' => 'Interests']);
    $s3 = AdSet::factory()->for($c2, 'campaign')->create(['name' => 'Broad | EG | Advantage+']);

    return [
        'acc' => $acc, 'c1' => $c1, 'c2' => $c2, 's1' => $s1, 's2' => $s2, 's3' => $s3,
        'a1' => ctAd($acc, $c1, $s1, 'ad one', ['spend' => 100, 'purchase_value' => 400, 'purchases' => 4, 'impressions' => 1000, 'clicks' => 20]),
        'a2' => ctAd($acc, $c1, $s1, 'ad two', ['spend' => 50, 'purchase_value' => 50, 'purchases' => 1, 'impressions' => 1000, 'clicks' => 10]),
        'a3' => ctAd($acc, $c2, $s3, 'ad three', ['spend' => 400, 'purchase_value' => 400, 'purchases' => 2, 'impressions' => 3000, 'clicks' => 30]),
    ];
}

it('rolls the metrics up from ads to ad sets to campaigns', function () {
    $w = ctWorld();
    ctAd($w['acc'], $w['c1'], $w['s2'], 'ad four', ['spend' => 50, 'purchase_value' => 150, 'purchases' => 3, 'impressions' => 2000, 'clicks' => 20]);

    $tree = app(CampaignTree::class)->build(ctRange());

    // Sorted by spend: c2 (400) then c1 (100 + 50 + 50 = 200).
    expect(array_column($tree, 'id'))->toBe([$w['c2']->id, $w['c1']->id]);

    $c1 = $tree[1];
    expect($c1['level'])->toBe('campaign')->and($c1['external_id'])->toBe($w['c1']->external_id)
        ->and($c1['account_id'])->toBe($w['acc']->id)->and($c1['account'])->toBe('LV Main')->and($c1['platform'])->toBe('meta')
        ->and($c1['objective'])->toBe('OUTCOME_SALES')
        ->and($c1['metrics'])->toMatchArray([
            'spend' => 200.0, 'spend_tax' => 228.0, 'purchase_value' => 600.0, 'roas' => 3.0, 'purchases' => 8.0, 'cpa' => 25.0,
            'impressions' => 4000, 'clicks' => 50, 'ctr' => 0.0125, 'real_orders' => 0,
        ]);

    // Ad sets of c1: s1 (150) before s2 (50); s1 sums its two ads from the raw figures, not an average of ratios.
    expect(array_column($c1['children'], 'id'))->toBe([$w['s1']->id, $w['s2']->id]);
    $s1 = $c1['children'][0];
    expect($s1['level'])->toBe('adset')->and($s1['external_id'])->toBe($w['s1']->external_id)->and($s1['account_id'])->toBe($w['acc']->id)
        ->and($s1['metrics'])->toMatchArray(['spend' => 150.0, 'purchase_value' => 450.0, 'roas' => 3.0, 'clicks' => 30]);

    // Ads carry the local id for the row actions.
    [$one, $two] = $s1['children'];
    expect($one['level'])->toBe('ad')->and($one['ad_id'])->toBe($w['a1']->id)->and($one['id'])->toBe($w['a1']->id)
        ->and($one['external_id'])->toBe($w['a1']->external_id)->and($one['account_id'])->toBe($w['acc']->id)
        ->and($one['name'])->toBe('ad one')->and($one['metrics']['roas'])->toBe(4.0)->and($one['children'])->toBe([])
        ->and($one['trend'])->toHaveKeys(['roas_pct', 'spend_pct', 'dir'])
        ->and($two['ad_id'])->toBe($w['a2']->id);
});

it('counts ad-attributed real orders per node', function () {
    $w = ctWorld();
    $mk = fn (Ad $ad, array $a = []) => Order::factory()->create(array_merge([
        'customer_id' => Customer::factory()->create()->id, 'status' => OrderStatus::Confirmed,
        'ad_id' => $ad->id, 'placed_at' => '2026-09-12 10:00:00',
    ], $a));
    $mk($w['a1']);
    $mk($w['a1']);
    $mk($w['a2']);
    $mk($w['a3']);
    $mk($w['a3'], ['status' => OrderStatus::Cancelled]);   // dead order
    $mk($w['a3'], ['placed_at' => '2026-08-12 10:00:00']); // outside the range

    $tree = collect(app(CampaignTree::class)->build(ctRange()))->keyBy('id');

    expect($tree[$w['c1']->id]['metrics']['real_orders'])->toBe(3)
        ->and($tree[$w['c1']->id]['children'][0]['children'][0]['metrics']['real_orders'])->toBe(2)
        ->and($tree[$w['c2']->id]['metrics']['real_orders'])->toBe(1);
});

it('flags campaign and ad set names that break the naming convention', function () {
    $w = ctWorld();
    ctAd($w['acc'], $w['c1'], $w['s2'], 'ad four', ['spend' => 5]);

    $tree = collect(app(CampaignTree::class)->build(ctRange()))->keyBy('id');

    expect($tree[$w['c1']->id]['naming_ok'])->toBeTrue()->and($tree[$w['c2']->id]['naming_ok'])->toBeFalse();
    $sets = collect($tree[$w['c1']->id]['children'])->keyBy('id');
    expect($sets[$w['s1']->id]['naming_ok'])->toBeTrue()->and($sets[$w['s2']->id]['naming_ok'])->toBeFalse();
});

it('sorts by roas with nulls last', function () {
    $w = ctWorld();
    $acc = $w['acc'];
    $c3 = AdCampaign::factory()->for($acc, 'account')->create(['name' => 'LV | X | Y | Z | 260101']);
    ctAd($acc, $c3, AdSet::factory()->for($c3, 'campaign')->create(), 'no sales', ['spend' => 0, 'impressions' => 10]);

    // roas: c1 3.0, c2 1.0, c3 null
    $bySpend = array_column(app(CampaignTree::class)->build(ctRange(), 'spend'), 'id');
    $byRoas = array_column(app(CampaignTree::class)->build(ctRange(), 'roas'), 'id');

    expect($bySpend)->toBe([$w['c2']->id, $w['c1']->id, $c3->id])
        ->and($byRoas)->toBe([$w['c1']->id, $w['c2']->id, $c3->id]);
});

it('puts ads without a campaign under a placeholder and leaves out campaigns with no metrics in range', function () {
    $w = ctWorld();
    ctAd($w['acc'], null, null, 'orphan', ['spend' => 10]);
    AdCampaign::factory()->for($w['acc'], 'account')->create(['name' => 'idle']);
    ctAd($w['acc'], $w['c1'], $w['s1'], 'old', ['spend' => 999], '2026-08-01');

    $tree = app(CampaignTree::class)->build(ctRange());

    expect($tree)->toHaveCount(3)
        ->and(collect($tree)->firstWhere('id', 0))->toMatchArray(['name' => '', 'account_id' => $w['acc']->id])
        ->and(collect($tree)->firstWhere('id', $w['c1']->id)['metrics']['spend'])->toBe(150.0);
});

it('shows a media buyer only the metric rows of accounts assigned to them on those dates', function () {
    $user = User::factory()->create(['role' => UserRole::MediaBuyer]);
    $buyer = MediaBuyer::factory()->create(['user_id' => $user->id]);
    $other = MediaBuyer::factory()->create();
    $svc = app(AssignmentService::class);

    $mine = AdAccount::factory()->meta()->create(['name' => 'MINE']);
    $foreign = AdAccount::factory()->meta()->create(['name' => 'FOREIGN']);
    $shared = AdAccount::factory()->meta()->create(['name' => 'SHARED']);
    $svc->assign($mine, $buyer, CarbonImmutable::parse('2026-09-01'));
    $svc->assign($foreign, $other, CarbonImmutable::parse('2026-09-01'));
    $svc->assign($shared, $other, CarbonImmutable::parse('2026-09-01'));
    $svc->assign($shared, $buyer, CarbonImmutable::parse('2026-09-16')); // buyer owns it from the 16th only

    foreach ([$mine, $foreign, $shared] as $acc) {
        $c = AdCampaign::factory()->for($acc, 'account')->create(['name' => 'Camp '.$acc->name]);
        $s = AdSet::factory()->for($c, 'campaign')->create();
        ctAd($acc, $c, $s, 'early '.$acc->name, ['spend' => 100], '2026-09-10');
        ctAd($acc, $c, $s, 'late '.$acc->name, ['spend' => 7], '2026-09-20');
    }

    $filter = new AdsFilter(CarbonImmutable::parse('2026-09-01'), CarbonImmutable::parse('2026-09-30'), restrictBuyerId: $buyer->id);
    $tree = collect(app(CampaignTree::class)->build($filter))->keyBy('name');

    expect($tree->keys()->sort()->values()->all())->toBe(['Camp MINE', 'Camp SHARED'])
        ->and($tree['Camp MINE']['metrics']['spend'])->toBe(107.0)
        ->and($tree['Camp SHARED']['metrics']['spend'])->toBe(7.0);

    $page = $this->actingAs($user)->get('/ads/explorer?view=tree&from=2026-09-01&to=2026-09-30&buyer='.$other->id)->assertOk();
    expect($page->getContent())->not->toContain('FOREIGN');
});

it('serves the page to ads report roles and keeps content users out', function () {
    $w = ctWorld();
    $admin = User::factory()->create(['role' => UserRole::Admin]);

    $this->actingAs($admin)->get('/ads/explorer?view=tree&from=2026-09-01&to=2026-09-30&sort=-roas')->assertOk()->assertInertia(fn (Assert $p) => $p
        ->component('Ads/Explorer', false)
        ->where('filters.sort', '-roas')->where('filters.from', '2026-09-01')
        ->has('tree', 2)->where('tree.0.id', $w['c1']->id)
        ->has('buyers')->has('platforms', 3)->where('currency', 'EGP'));

    $this->actingAs($admin)->get('/ads/explorer?view=tree&from=2026-09-01&to=2026-09-30&sort=bogus')->assertOk()->assertInertia(fn (Assert $p) => $p
        ->where('filters.sort', '-spend'));

    $this->actingAs(User::factory()->create(['role' => UserRole::Content]))->get('/ads/explorer?view=tree')->assertRedirect();
});

it('keeps one placeholder per account for ads without a campaign', function () {
    $w = ctWorld();
    $other = AdAccount::factory()->tiktok()->create(['name' => 'LV Other']);
    ctAd($w['acc'], null, null, 'orphan a', ['spend' => 10]);
    ctAd($other, null, null, 'orphan b', ['spend' => 20]);

    $tree = collect(app(CampaignTree::class)->build(ctRange()));
    $holders = $tree->where('placeholder', true)->values();

    expect($holders)->toHaveCount(2)
        ->and($holders->pluck('account_id')->sort()->values()->all())->toBe(collect([$w['acc']->id, $other->id])->sort()->values()->all())
        ->and($holders->every(fn ($n) => $n['id'] === 0 && $n['children'][0]['placeholder'] === true && $n['children'][0]['children'][0]['placeholder'] === false))->toBeTrue()
        ->and($tree->where('placeholder', false)->every(fn ($n) => $n['id'] > 0))->toBeTrue();
    expect($holders->firstWhere('account_id', $other->id)['children'][0]['children'][0]['account_id'])->toBe($other->id);
});

it('filters the page by accounts and exposes the picked ones and the options', function () {
    $w = ctWorld();
    $other = AdAccount::factory()->meta()->create(['name' => 'Other acc']);
    $oc = AdCampaign::factory()->for($other, 'account')->create(['name' => 'Other camp']);
    ctAd($other, $oc, AdSet::factory()->for($oc, 'campaign')->create(), 'other ad', ['spend' => 5]);
    $admin = User::factory()->create(['role' => UserRole::Admin]);

    $this->actingAs($admin)->get('/ads/explorer?view=tree&from=2026-09-01&to=2026-09-30&accounts[]='.$other->id)->assertOk()->assertInertia(fn (Assert $p) => $p
        ->has('tree', 1)->where('tree.0.id', $oc->id)
        ->where('filters.accounts', [$other->id])
        ->has('account_options', 2));

    $this->actingAs($admin)->get('/ads/explorer?view=tree&from=2026-09-01&to=2026-09-30')->assertInertia(fn (Assert $p) => $p
        ->has('tree', 3)->where('filters.accounts', []));
});
