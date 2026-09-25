<?php

use App\Enums\Platform;
use App\Enums\UserRole;
use App\Models\BotSetting;
use App\Models\ChannelAccount;
use App\Models\User;
use App\Onboarding\OnboardingProgress;
use Inertia\Testing\AssertableInertia;

it('reads every step from what is really connected', function () {
    BotSetting::current()->update(['enabled' => false, 'store_url' => null]);

    $p = app(OnboardingProgress::class)->build();
    $steps = collect($p['steps'])->keyBy('key');
    expect($p['total'])->toBe(7)->and($steps['facebook']['done'])->toBeFalse()->and($steps['bot']['done'])->toBeFalse()
        ->and($steps['instagram']['blocked_by'])->toBe('facebook')->and($p['complete'])->toBeFalse();

    $fb = ChannelAccount::factory()->create(['platform' => Platform::Facebook, 'driver' => 'live', 'status' => 'connected', 'name' => 'Le Voile Stores']);
    ChannelAccount::factory()->create(['platform' => Platform::Instagram, 'driver' => 'live', 'status' => 'connected', 'credentials' => ['linked_facebook_account_id' => $fb->id]]);
    BotSetting::current()->update(['enabled' => true, 'store_url' => 'https://levoilestores.com']);

    $p = app(OnboardingProgress::class)->build();
    $steps = collect($p['steps'])->keyBy('key');
    expect($steps['facebook']['done'])->toBeTrue()->and($steps['facebook']['detail'])->toBe('Le Voile Stores')
        ->and($steps['instagram']['done'])->toBeTrue()->and($steps['instagram']['blocked_by'])->toBeNull()
        ->and($steps['bot']['done'])->toBeTrue()->and($p['complete'])->toBeTrue();
});

it('lands a fresh admin on the page after login, not a moderator, and not once something is connected or it was skipped', function () {
    $admin = User::factory()->create(['role' => UserRole::Admin, 'password' => 'password']);
    $moderator = User::factory()->create(['role' => UserRole::Moderator, 'password' => 'password']);

    $this->post('/login', ['email' => $admin->email, 'password' => 'password'])->assertRedirect('/onboarding');
    auth()->logout();
    $this->post('/login', ['email' => $moderator->email, 'password' => 'password'])->assertRedirect(route('inbox', absolute: false));
    auth()->logout();

    $this->actingAs($admin)->get('/onboarding')->assertOk()->assertInertia(fn (AssertableInertia $page) => $page->component('Onboarding')->has('progress.steps', 7));
    $this->actingAs($moderator)->get('/onboarding')->assertForbidden();

    $this->actingAs($admin)->post('/onboarding/dismiss')->assertRedirect(route('inbox'));
    expect(BotSetting::current()->onboarding_dismissed_at)->not->toBeNull();
    auth()->logout();
    $this->post('/login', ['email' => $admin->email, 'password' => 'password'])->assertRedirect(route('inbox', absolute: false));
    auth()->logout();

    BotSetting::current()->update(['onboarding_dismissed_at' => null]);
    ChannelAccount::factory()->create(['platform' => Platform::WhatsApp, 'driver' => 'live', 'status' => 'connected']);
    $this->post('/login', ['email' => $admin->email, 'password' => 'password'])->assertRedirect(route('inbox', absolute: false));
});
