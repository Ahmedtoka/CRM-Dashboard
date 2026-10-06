<?php

use App\Enums\OrderSource;
use App\Enums\OrderStatus;
use App\Enums\UserRole;
use App\Models\Ad;
use App\Models\AdAccount;
use App\Models\AdAccountAssignment;
use App\Models\AdCampaign;
use App\Models\AdDailyMetric;
use App\Models\AdSet;
use App\Models\MediaBuyer;
use App\Models\Order;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Http;

/** Fake drivers, no stray HTTP, history from 2026-09-01, «now» = Tue 2026-10-06 15:30 Cairo. */
function crSetup($test): void
{
    Http::preventStrayRequests();
    config([
        'crm.ads.drivers.meta' => 'fake', 'crm.ads.drivers.tiktok' => 'fake', 'crm.ads.drivers.google' => 'fake',
        'crm.ads.history_start' => '2026-09-01',
        // The S2 pages (Ads/Today, Decisions, Explorer, Numbers) land with the frontend tasks; backend tests assert props only.
        'inertia.testing.ensure_pages_exist' => false,
    ]);
    $test->travelTo(CarbonImmutable::parse('2026-10-06 15:30', 'Africa/Cairo'));
    (fn () => $this->withoutVite())->call($test); // protected on TestCase
}

function crUser(UserRole $role, bool $authority = false): User
{
    $u = User::factory()->create(['role' => $role]);
    if ($authority) {
        $u->forceFill(['ads_authority' => true])->save();
    }

    return $u;
}

function crAdmin(): User
{
    return crUser(UserRole::Admin, true);
}

/** @return array{user: User, buyer: MediaBuyer, account: AdAccount} */
function crBuyer(?AdAccount $account = null, string $since = '2026-09-01', ?string $until = null): array
{
    $user = crUser(UserRole::MediaBuyer);
    $buyer = MediaBuyer::factory()->create(['user_id' => $user->id]);
    $account ??= AdAccount::factory()->meta()->create(['currency' => 'EGP']);
    AdAccountAssignment::factory()->create([
        'ad_account_id' => $account->id, 'media_buyer_id' => $buyer->id, 'starts_on' => $since, 'ends_on' => $until,
    ]);

    return ['user' => $user, 'buyer' => $buyer, 'account' => $account];
}

/**
 * An ad under its own active campaign + ad set, with metric rows: [date => [spend, purchases, purchase_value, msg_conversations]].
 *
 * @param  array<string, array{0: float|int, 1: float|int, 2: float|int, 3: int}>  $days
 * @param  array<string, mixed>  $attrs
 */
function crAd(AdAccount $account, array $days = [], array $attrs = [], string $objective = 'OUTCOME_SALES'): Ad
{
    $camp = AdCampaign::factory()->create(['ad_account_id' => $account->id, 'objective' => $objective, 'status' => 'ACTIVE']);
    $set = AdSet::factory()->create(['ad_campaign_id' => $camp->id, 'status' => 'ACTIVE']);
    $ad = Ad::factory()->create(['ad_account_id' => $account->id, 'ad_campaign_id' => $camp->id, 'ad_set_id' => $set->id] + $attrs);
    foreach ($days as $date => [$spend, $purchases, $value, $msg]) {
        AdDailyMetric::factory()->create([
            'ad_id' => $ad->id, 'ad_account_id' => $account->id, 'date' => $date,
            'spend' => $spend, 'purchases' => $purchases, 'purchase_value' => $value, 'msg_conversations' => $msg,
        ]);
    }

    return $ad;
}

/** A real chat order attributed to the ad, placed at a Cairo time. */
function crOrder(Ad $ad, string $placedAtCairo, float $total): Order
{
    return Order::factory()->create([
        'ad_id' => $ad->id, 'status' => OrderStatus::Confirmed, 'source' => OrderSource::Chat, 'total' => $total,
        'placed_at' => CarbonImmutable::parse($placedAtCairo, 'Africa/Cairo')->utc(),
    ]);
}
