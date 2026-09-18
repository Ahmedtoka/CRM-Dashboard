<?php

use App\Enums\UserRole; use App\Models\User;

it('guards settings and reports by role', function () {
    config(['crm.dev_tools' => true]);
    $mod = User::factory()->create(['role'=>UserRole::Moderator]);
    $sup = User::factory()->create(['role'=>UserRole::Supervisor]);
    $this->actingAs($mod)->get('/reports/team')->assertForbidden();
    $this->actingAs($mod)->get('/reports/me')->assertOk();
    $this->actingAs($sup)->get('/settings/bot')->assertOk();
    $this->actingAs($sup)->get('/settings/users')->assertForbidden();
    $this->actingAs($sup)->get('/simulator')->assertForbidden();
});

it('renders settings pages from the lowercase settings folder', function () {
    $admin = User::factory()->create(['role'=>UserRole::Admin]);
    foreach (['users'=>'settings/Users', 'channels'=>'settings/Channels', 'bot'=>'settings/Bot', 'quick-replies'=>'settings/QuickReplies', 'tags'=>'settings/Tags', 'cities'=>'settings/Cities'] as $url => $component) {
        $this->actingAs($admin)->get("/settings/{$url}")->assertInertia(fn ($page) => $page->component($component));
        // Exact-case check (Windows file lookups are case-insensitive, Linux is not).
        expect(in_array(basename($component).'.vue', scandir(resource_path('js/pages/settings')), true))->toBeTrue();
    }
});

it('lets admins reach every settings page', function () {
    config(['crm.dev_tools' => true]);
    $admin = User::factory()->create(['role'=>UserRole::Admin]);
    foreach (['/settings/users', '/settings/channels', '/settings/bot', '/settings/quick-replies', '/settings/tags', '/settings/cities', '/simulator', '/reports/team', '/reports/bot', '/reports/activity', '/comments', '/orders', '/customers'] as $url) {
        $this->actingAs($admin)->get($url)->assertOk();
    }
});
