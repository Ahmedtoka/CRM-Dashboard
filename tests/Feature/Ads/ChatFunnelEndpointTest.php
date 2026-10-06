<?php

use App\Enums\UserRole;
use App\Models\Ad;
use App\Models\AdAccount;
use App\Models\AdAccountAssignment;
use App\Models\MediaBuyer;
use App\Models\User;
use Illuminate\Support\Carbon;

beforeEach(fn () => Carbon::setTestNow(Carbon::parse('2026-10-08 12:00', 'Africa/Cairo')));

function s3FunnelUrl(Ad ...$ads): string
{
    return '/ads/chat-funnel?'.http_build_query(['ads' => array_map(fn (Ad $a) => $a->id, $ads), 'from' => '2026-10-01', 'to' => '2026-10-07']);
}

it('serves the funnel per ad to admin and supervisor', function (string $role) {
    $ad = Ad::factory()->create();

    $this->actingAs(User::factory()->create(['role' => $role]))->getJson(s3FunnelUrl($ad))->assertOk()
        ->assertJsonPath("data.{$ad->id}.chats", 0)
        ->assertJsonPath('range', ['from' => '2026-10-01', 'to' => '2026-10-07']);
})->with(['admin', 'supervisor']);

it('lets a media buyer read only the ads of her accounts', function () {
    $user = User::factory()->create(['role' => UserRole::MediaBuyer]);
    $buyer = MediaBuyer::factory()->create(['user_id' => $user->id]);
    $mine = AdAccount::factory()->create();
    AdAccountAssignment::factory()->create(['ad_account_id' => $mine->id, 'media_buyer_id' => $buyer->id, 'starts_on' => '2026-01-01', 'ends_on' => null]);
    $own = Ad::factory()->create(['ad_account_id' => $mine->id]);
    $foreign = Ad::factory()->create();

    $data = $this->actingAs($user)->getJson(s3FunnelUrl($own, $foreign))->assertOk()->json('data');

    expect(array_keys($data))->toBe([$own->id]); // JSON object keys decode to int array keys
});

it('refuses agents and content', function (string $role) {
    $this->actingAs(User::factory()->create(['role' => $role]))->getJson(s3FunnelUrl(Ad::factory()->create()))->assertForbidden();
})->with(['moderator', 'content']);

it('validates the ad list', function () {
    $admin = User::factory()->create(['role' => UserRole::Admin]);
    $this->actingAs($admin)->getJson('/ads/chat-funnel')->assertUnprocessable()->assertJsonValidationErrors('ads');
    $this->actingAs($admin)->getJson('/ads/chat-funnel?'.http_build_query(['ads' => range(1, 51)]))->assertUnprocessable();
});
