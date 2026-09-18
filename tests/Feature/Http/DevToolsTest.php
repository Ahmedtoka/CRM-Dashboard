<?php

use App\Enums\UserRole;
use App\Models\User;

// Developer-only pages (simulator, latency report) behind crm.dev_tools.

it('404s the simulator and latency report when developer tools are off', function () {
    config(['crm.dev_tools' => false]);
    $admin = User::factory()->create(['role' => UserRole::Admin]);

    $this->actingAs($admin)->get('/simulator')->assertNotFound();
    $this->actingAs($admin)->postJson('/simulator/message', ['platform' => 'whatsapp', 'customer_key' => 'x', 'name' => 'x', 'text' => 'x'])->assertNotFound();
    $this->actingAs($admin)->get('/reports/latency')->assertNotFound();
});

it('serves them to admins when developer tools are on', function () {
    config(['crm.dev_tools' => true]);
    $admin = User::factory()->create(['role' => UserRole::Admin]);

    $this->actingAs($admin)->get('/simulator')->assertOk();
    $this->actingAs($admin)->get('/reports/latency')->assertOk();
});

it('defaults to off and shares the flag with the frontend', function () {
    expect(config('crm.dev_tools'))->toBeFalse();
    $admin = User::factory()->create(['role' => UserRole::Admin]);

    $this->actingAs($admin)->get('/inbox')->assertInertia(fn ($page) => $page->where('devTools', false));

    config(['crm.dev_tools' => true]);
    $this->actingAs($admin)->get('/inbox')->assertInertia(fn ($page) => $page->where('devTools', true));
});
