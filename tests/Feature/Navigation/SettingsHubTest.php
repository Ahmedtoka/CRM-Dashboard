<?php

use App\Enums\UserRole;
use App\Models\Tag;
use App\Models\User;

// A5 (redesign 2026-10-17): /settings is one page of grouped cards, each item with its state, filtered by role.

function hubGroups($page): array
{
    return collect($page->toArray()['props']['groups'])->mapWithKeys(fn ($g) => [$g['id'] => collect($g['items'])->pluck('id')->all()])->all();
}

it('shows the admin every group, developer tools only with dev tools on', function () {
    config(['crm.dev_tools' => false]);
    $admin = User::factory()->create(['role' => UserRole::Admin]);

    $this->actingAs($admin)->get('/settings')->assertOk()->assertInertia(function ($page) {
        $page->component('settings/Index');
        expect(hubGroups($page))->toBe([
            'account' => ['profile', 'quick_replies'],
            'team' => ['users', 'queue', 'tags'],
            'channels' => ['integrations', 'shopify'],
            'store' => ['cities', 'branch_hours', 'order_confirmations'],
            'ads' => ['ad_accounts', 'ad_buyers', 'ad_rules'],
            'start' => ['onboarding'],
        ]);
    });

    config(['crm.dev_tools' => true]);
    $this->actingAs($admin)->get('/settings')->assertInertia(fn ($page) => expect(hubGroups($page)['dev'])->toBe(['simulator', 'latency']));
});

it('shows the supervisor her three groups', function () {
    $this->actingAs(User::factory()->create(['role' => UserRole::Supervisor]))->get('/settings')
        ->assertInertia(fn ($page) => expect(hubGroups($page))->toBe([
            'account' => ['profile', 'quick_replies'],
            'team' => ['queue', 'tags'],
            'ads' => ['ad_accounts', 'ad_buyers', 'ad_rules'],
        ]));
});

it('shows the moderator her account only', function () {
    $this->actingAs(User::factory()->create(['role' => UserRole::Moderator]))->get('/settings')
        ->assertInertia(fn ($page) => expect(hubGroups($page))->toBe(['account' => ['profile', 'quick_replies']]));
});

it('lets the ads roles reach their account from the hub', function () {
    $this->actingAs(User::factory()->create(['role' => UserRole::MediaBuyer]))->get('/settings')
        ->assertOk()
        ->assertInertia(fn ($page) => expect(hubGroups($page))->toBe(['account' => ['profile']]));
});

it('gives each item its link and a short state', function () {
    Tag::factory()->count(3)->create();

    $this->actingAs(User::factory()->create(['role' => UserRole::Admin]))->get('/settings')->assertInertia(function ($page) {
        $items = collect($page->toArray()['props']['groups'])->flatMap(fn ($g) => $g['items'])->keyBy('id');
        expect($items['tags'])->toMatchArray(['href' => '/settings/tags', 'count' => 3])
            ->and($items['ad_accounts']['href'])->toBe('/settings/ads/accounts')
            ->and($items['integrations']['href'])->toBe('/settings/integrations')
            ->and($items['profile']['href'])->toBe('/settings/profile');
    });
});
