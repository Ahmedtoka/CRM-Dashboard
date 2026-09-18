<?php

use App\Enums\UserRole;
use App\Models\ChannelAccount;
use App\Models\User;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;

beforeEach(function () {
    Sleep::fake();
    Http::preventStrayRequests();
    config([
        'app.url' => 'https://crm.example.com',
        'crm.meta.app_id' => '111222',
        'crm.meta.app_secret' => 'app-sec',
        'crm.meta.verify_token' => 'verify-me',
        'crm.meta.graph_version' => 'v23.0',
    ]);
    $this->admin = User::factory()->create(['role' => UserRole::Admin]);
});

it('is admin only, page and every action', function () {
    $account = ChannelAccount::factory()->create(['platform' => 'facebook', 'driver' => 'live']);

    foreach ([UserRole::Supervisor, UserRole::Moderator] as $role) {
        $user = User::factory()->create(['role' => $role]);
        $this->actingAs($user)->get('/settings/integrations')->assertForbidden();
        $this->actingAs($user)->postJson('/settings/integrations/facebook/system-token', ['token' => str_repeat('x', 30)])->assertForbidden();
        $this->actingAs($user)->getJson('/settings/integrations/instagram/discover')->assertForbidden();
        $this->actingAs($user)->postJson('/settings/integrations/instagram/connect')->assertForbidden();
        $this->actingAs($user)->postJson('/settings/integrations/whatsapp/phone-numbers', [])->assertForbidden();
        $this->actingAs($user)->postJson('/settings/integrations/whatsapp/connect', [])->assertForbidden();
        $this->actingAs($user)->postJson("/settings/integrations/{$account->id}/test")->assertForbidden();
        $this->actingAs($user)->postJson("/settings/integrations/{$account->id}/fix", ['action' => 'resubscribe'])->assertForbidden();
        $this->actingAs($user)->deleteJson("/settings/integrations/{$account->id}")->assertForbidden();
    }

    auth()->logout();
    $this->get('/settings/integrations')->assertRedirect('/login');
});

it('shows live accounts only, with meta setup details, and never a token', function () {
    ChannelAccount::factory()->create(['platform' => 'facebook', 'driver' => 'fake', 'name' => 'Demo Messenger']);
    ChannelAccount::factory()->create(['platform' => 'whatsapp', 'driver' => 'fake', 'name' => 'Demo WhatsApp']);
    $fb = ChannelAccount::factory()->create([
        'platform' => 'facebook', 'driver' => 'live', 'name' => 'Le Voile', 'external_id' => '459028320806456',
        'credentials' => ['access_token' => 'EAAG-page-SECRET'], 'profile' => ['picture' => 'https://cdn.example/p.jpg', 'method' => 'system_user'],
    ]);
    ChannelAccount::factory()->create([
        'platform' => 'whatsapp', 'driver' => 'live', 'name' => 'Le Voile +20 100', 'external_id' => '1098765',
        'credentials' => ['access_token' => 'EAAG-wa-SECRET'], 'profile' => ['waba_id' => '5550001', 'display_phone_number' => '+20 100 000 0000', 'quality_rating' => 'GREEN'],
    ]);

    $response = $this->actingAs($this->admin)->get('/settings/integrations')->assertOk();

    $response->assertInertia(fn ($page) => $page->component('settings/Integrations')
        ->where('accounts.facebook.id', $fb->id)
        ->where('accounts.facebook.name', 'Le Voile')
        ->where('accounts.facebook.profile.method', 'system_user')
        ->where('accounts.facebook.has_token', true)
        ->where('accounts.instagram', null)
        ->where('accounts.whatsapp.profile.waba_id', '5550001')
        ->where('accounts.whatsapp.profile.quality_rating', 'GREEN')
        ->where('meta.callback_urls.instagram', 'https://crm.example.com/webhooks/instagram')
        ->where('meta.callback_urls.whatsapp', 'https://crm.example.com/webhooks/whatsapp')
        ->where('meta.verify_token', 'verify-me')
        ->where('meta.can_override_callback', true)
        ->where('shopify', null));

    $body = $response->getContent();
    expect($body)->not->toContain('EAAG-page-SECRET')->not->toContain('EAAG-wa-SECRET')
        ->not->toContain('Demo Messenger')->not->toContain('Demo WhatsApp');
});

it('keeps the advanced channels page and the old channel flows reachable', function () {
    ChannelAccount::factory()->create(['platform' => 'facebook', 'driver' => 'fake', 'name' => 'Demo Messenger']);

    $this->actingAs($this->admin)->get('/settings/channels')->assertOk()->assertSee('Demo Messenger');
});

it('does not manage demo accounts from the integrations endpoints', function () {
    $fake = ChannelAccount::factory()->create(['platform' => 'facebook', 'driver' => 'fake']);

    $this->actingAs($this->admin)->postJson("/settings/integrations/{$fake->id}/test")->assertNotFound();
    $this->actingAs($this->admin)->deleteJson("/settings/integrations/{$fake->id}")->assertNotFound();
    expect($fake->fresh()->status)->toBe('connected');
});
