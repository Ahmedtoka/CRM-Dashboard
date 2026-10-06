<?php

use App\Enums\UserRole;
use App\Models\Ad;
use App\Models\AdAccount;
use App\Models\AdAccountAssignment;
use App\Models\Conversation;
use App\Models\MediaBuyer;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

beforeEach(fn () => Carbon::setTestNow(Carbon::parse('2026-10-08 12:00', 'Africa/Cairo')));

function numbersFunnelTouch(Ad $ad): void
{
    $c = Conversation::factory()->create();
    DB::table('conversation_ad_referrals')->insert(['conversation_id' => $c->id, 'customer_id' => $c->customer_id, 'ad_external_id' => $ad->external_id, 'referred_at' => now()->subDays(2)]);
}

it('defers the chat funnel totals on the Numbers page', function () {
    numbersFunnelTouch(Ad::factory()->create());
    numbersFunnelTouch(Ad::factory()->create());
    $admin = User::factory()->create(['role' => UserRole::Admin]);

    $this->actingAs($admin)->get('/ads/numbers?from=2026-10-01&to=2026-10-07')->assertOk()
        ->assertInertia(fn ($page) => $page->component('Ads/Numbers')->missing('chatFunnel')
            ->loadDeferredProps('funnel', fn ($reload) => $reload->where('chatFunnel.chats', 2)->where('chatFunnel.orders', 0)));
});

it('scopes the funnel to the buyer accounts', function () {
    $user = User::factory()->create(['role' => UserRole::MediaBuyer]);
    $buyer = MediaBuyer::factory()->create(['user_id' => $user->id]);
    $mine = AdAccount::factory()->create();
    AdAccountAssignment::factory()->create(['ad_account_id' => $mine->id, 'media_buyer_id' => $buyer->id, 'starts_on' => '2026-01-01', 'ends_on' => null]);
    numbersFunnelTouch(Ad::factory()->create(['ad_account_id' => $mine->id]));
    numbersFunnelTouch(Ad::factory()->create()); // another buyer's account

    $this->actingAs($user)->get('/ads/numbers?from=2026-10-01&to=2026-10-07')->assertOk()
        ->assertInertia(fn ($page) => $page->loadDeferredProps('funnel', fn ($reload) => $reload->where('chatFunnel.chats', 1)));
});

it('shows an unlinked buyer nothing', function () {
    numbersFunnelTouch(Ad::factory()->create());
    $user = User::factory()->create(['role' => UserRole::MediaBuyer]);

    $this->actingAs($user)->get('/ads/numbers?from=2026-10-01&to=2026-10-07')->assertOk()
        ->assertInertia(fn ($page) => $page->loadDeferredProps('funnel', fn ($reload) => $reload->where('chatFunnel.chats', 0)));
});

it('narrows a supervisor funnel to the picked buyer and to buyer=me', function () {
    $buyer = MediaBuyer::factory()->create();
    $held = AdAccount::factory()->create();
    AdAccountAssignment::factory()->create(['ad_account_id' => $held->id, 'media_buyer_id' => $buyer->id, 'starts_on' => '2026-01-01', 'ends_on' => null]);
    $past = AdAccount::factory()->create(); // held before the range only
    AdAccountAssignment::factory()->create(['ad_account_id' => $past->id, 'media_buyer_id' => $buyer->id, 'starts_on' => '2026-01-01', 'ends_on' => '2026-09-01']);
    numbersFunnelTouch(Ad::factory()->create(['ad_account_id' => $held->id]));
    numbersFunnelTouch(Ad::factory()->create(['ad_account_id' => $past->id]));
    numbersFunnelTouch(Ad::factory()->create()); // nobody's account
    $supervisor = User::factory()->create(['role' => UserRole::Supervisor]);

    $this->actingAs($supervisor)->get('/ads/numbers?from=2026-10-01&to=2026-10-07&buyer='.$buyer->id)->assertOk()
        ->assertInertia(fn ($page) => $page->loadDeferredProps('funnel', fn ($reload) => $reload->where('chatFunnel.chats', 1)));
    $this->actingAs($supervisor)->get('/ads/numbers?from=2026-10-01&to=2026-10-07')->assertOk()
        ->assertInertia(fn ($page) => $page->loadDeferredProps('funnel', fn ($reload) => $reload->where('chatFunnel.chats', 3)));
    // A supervisor who buys nothing: buyer=me shows nothing.
    $this->actingAs($supervisor)->get('/ads/numbers?from=2026-10-01&to=2026-10-07&buyer=me')->assertOk()
        ->assertInertia(fn ($page) => $page->loadDeferredProps('funnel', fn ($reload) => $reload->where('chatFunnel.chats', 0)));
});
