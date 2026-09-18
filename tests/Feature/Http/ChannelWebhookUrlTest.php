<?php

use App\Enums\UserRole;
use App\Models\ChannelAccount;
use App\Models\User;

it('shows the webhook url on APP_URL, not the host the page was opened on', function () {
    config(['app.url' => 'https://crm.example.com/']);
    $admin = User::factory()->create(['role' => UserRole::Admin]);
    ChannelAccount::factory()->create(['platform' => 'facebook', 'driver' => 'fake']);

    $this->actingAs($admin)->get('http://127.0.0.1:8000/settings/channels')->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('accounts.0.webhook_url', 'https://crm.example.com/webhooks/facebook')
            ->where('accounts.0.driver', 'fake'));
});
