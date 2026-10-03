<?php

use App\Ads\AdsSettings;
use App\Models\Ad;
use App\Models\AdAccount;
use App\Models\AdAccountAssignment;
use App\Models\AdCampaign;
use App\Models\AdDailyMetric;
use App\Models\AdMaterial;
use App\Models\AdMaterialCollection;
use App\Models\AdMaterialFile;
use App\Models\AdPlatformConnection;
use App\Models\AdSet;
use App\Models\AdsSyncRun;
use App\Models\BuyerTarget;
use App\Models\MediaBuyer;
use App\Models\Order;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

it('builds every ads model through its factory and wires the relations', function () {
    $account = AdAccount::factory()->meta()->create();
    $campaign = AdCampaign::factory()->create(['ad_account_id' => $account->id]);
    $set = AdSet::factory()->create(['ad_campaign_id' => $campaign->id]);
    $ad = Ad::factory()->create(['ad_account_id' => $account->id, 'ad_campaign_id' => $campaign->id, 'ad_set_id' => $set->id]);
    $metric = AdDailyMetric::factory()->create(['ad_id' => $ad->id, 'ad_account_id' => $account->id, 'date' => '2026-09-15']);
    $buyer = MediaBuyer::factory()->create();
    $assignment = AdAccountAssignment::factory()->create(['ad_account_id' => $account->id, 'media_buyer_id' => $buyer->id]);
    $target = BuyerTarget::factory()->create(['media_buyer_id' => $buyer->id]);
    $material = AdMaterial::factory()->create(['media_buyer_id' => $buyer->id, 'types' => ['reel', 'story']]);
    $collection = AdMaterialCollection::factory()->create();
    $file = AdMaterialFile::factory()->create(['ad_material_id' => $material->id]);
    $material->collections()->attach($collection);
    $material->ads()->attach($ad);
    AdsSyncRun::factory()->create(['ad_account_id' => $account->id]);

    expect($account->connection->accounts->pluck('id')->all())->toBe([$account->id])
        ->and($account->campaigns->pluck('id')->all())->toBe([$campaign->id])
        ->and($account->ads->pluck('id')->all())->toBe([$ad->id])
        ->and($account->metrics->pluck('id')->all())->toBe([$metric->id])
        ->and($account->assignments->pluck('id')->all())->toBe([$assignment->id])
        ->and($ad->account->is($account))->toBeTrue()
        ->and($ad->campaign->is($campaign))->toBeTrue()
        ->and($ad->adSet->is($set))->toBeTrue()
        ->and($ad->metrics->count())->toBe(1)
        ->and($ad->materials->pluck('id')->all())->toBe([$material->id])
        ->and($metric->ad->is($ad))->toBeTrue()
        ->and($metric->account->is($account))->toBeTrue()
        ->and($metric->date->toDateString())->toBe('2026-09-15')
        ->and($assignment->account->is($account))->toBeTrue()
        ->and($assignment->buyer->is($buyer))->toBeTrue()
        ->and($buyer->assignments->count())->toBe(1)
        ->and($buyer->targets->pluck('id')->all())->toBe([$target->id])
        ->and($buyer->user)->toBeNull()
        ->and($material->types)->toBe(['reel', 'story'])
        ->and($material->status)->toBe('not_started')
        ->and($material->buyer->is($buyer))->toBeTrue()
        ->and($material->collections->count())->toBe(1)
        ->and($material->files->pluck('id')->all())->toBe([$file->id])
        ->and($material->ads->count())->toBe(1);
});

it('exposes platform states on the account and connection factories', function () {
    expect(AdAccount::factory()->tiktok()->create()->platform)->toBe('tiktok')
        ->and(AdAccount::factory()->google()->create()->platform)->toBe('google')
        ->and(AdPlatformConnection::factory()->google()->create()->platform)->toBe('google');
});

it('finds the buyer on a date from the assignment windows', function () {
    $account = AdAccount::factory()->meta()->create();
    $first = MediaBuyer::factory()->create();
    $second = MediaBuyer::factory()->create();
    AdAccountAssignment::factory()->create(['ad_account_id' => $account->id, 'media_buyer_id' => $first->id, 'starts_on' => '2026-09-01', 'ends_on' => '2026-09-20']);
    AdAccountAssignment::factory()->create(['ad_account_id' => $account->id, 'media_buyer_id' => $second->id, 'starts_on' => '2026-09-21', 'ends_on' => null]);

    expect($account->buyerOn('2026-09-15')->is($first))->toBeTrue()
        ->and($account->buyerOn('2026-09-20')->is($first))->toBeTrue()
        ->and($account->buyerOn('2026-10-30')->is($second))->toBeTrue()
        ->and($account->buyerOn('2026-08-31'))->toBeNull();
});

it('refuses a second metric row for the same ad and day', function () {
    $ad = Ad::factory()->create();
    AdDailyMetric::factory()->create(['ad_id' => $ad->id, 'ad_account_id' => $ad->ad_account_id, 'date' => '2026-09-15']);

    expect(fn () => AdDailyMetric::factory()->create(['ad_id' => $ad->id, 'ad_account_id' => $ad->ad_account_id, 'date' => '2026-09-15']))
        ->toThrow(QueryException::class);
});

it('keeps account external ids unique per platform', function () {
    AdAccount::factory()->meta()->create(['external_id' => 'act_1']);

    expect(fn () => AdAccount::factory()->meta()->create(['external_id' => 'act_1']))->toThrow(QueryException::class);
    expect(AdAccount::factory()->tiktok()->create(['external_id' => 'act_1']))->toBeInstanceOf(AdAccount::class);
});

it('stores connection credentials encrypted and hides them', function () {
    $connection = AdPlatformConnection::factory()->create(['credentials' => ['access_token' => 'SECRET-TOKEN-123']]);

    expect(DB::table('ad_platform_connections')->value('credentials'))->not->toContain('SECRET-TOKEN-123')
        ->and($connection->fresh()->credentials['access_token'])->toBe('SECRET-TOKEN-123')
        ->and($connection->fresh()->toArray())->not->toHaveKey('credentials');
});

it('links an order to the ad that brought it', function () {
    $ad = Ad::factory()->create();
    $order = Order::factory()->create(['ad_id' => $ad->id, 'ad_campaign_id' => null, 'ad_attribution' => 'utm_ad', 'utm_source' => 'facebook', 'landing_site' => '/?utm_source=facebook']);

    expect($order->fresh()->ad->is($ad))->toBeTrue()
        ->and($order->fresh()->ad_attribution)->toBe('utm_ad');

    $ad->delete();
    expect($order->fresh()->ad_id)->toBeNull();
});

it('reads and writes ads settings with defaults', function () {
    $settings = new AdsSettings;

    expect($settings->taxRate())->toBe(0.14)
        ->and($settings->winnerThresholds())->toBe(['winner' => 2.0, 'promising' => 1.3, 'loser' => 0.8, 'loser_min_spend' => 1000, 'min_spend' => 500, 'min_days' => 3]);

    $settings->set('tax_rate', 0.1);
    expect((new AdsSettings)->taxRate())->toBe(0.1);

    $settings->set('tax_rate', 0.2);
    expect((new AdsSettings)->taxRate())->toBe(0.2)
        ->and(DB::table('ads_settings')->count())->toBe(1);
});
