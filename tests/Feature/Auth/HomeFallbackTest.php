<?php

use App\Enums\UserRole;
use App\Models\BotSetting;
use App\Models\User;
use Illuminate\Support\Facades\URL;

beforeEach(fn () => BotSetting::current()->update(['onboarding_dismissed_at' => now()]));

it('final review C3: password confirm and email verify fall back to each role home, not the inbox', function () {
    $supervisor = User::factory()->create(['role' => UserRole::Supervisor]);
    $this->actingAs($supervisor)->post('/confirm-password', ['password' => 'password'])->assertRedirect(route('today', absolute: false));

    $buyer = User::factory()->unverified()->create(['role' => UserRole::MediaBuyer]);
    $url = URL::temporarySignedRoute('verification.verify', now()->addMinutes(60), ['id' => $buyer->id, 'hash' => sha1($buyer->email)]);
    $this->actingAs($buyer)->get($url)->assertRedirect(route('ads.today', absolute: false).'?verified=1');

    $agent = User::factory()->create(['role' => UserRole::Moderator]);
    $this->actingAs($agent)->post('/confirm-password', ['password' => 'password'])->assertRedirect(route('inbox', absolute: false));
});
