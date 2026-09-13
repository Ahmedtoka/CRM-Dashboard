<?php

use App\Enums\{MessageDirection, Platform, SenderType, UserRole};
use App\Models\{ChannelAccount, Conversation, Message, User};
use Illuminate\Support\Carbon;

beforeEach(function () {
    $this->mod = User::factory()->create(['role'=>UserRole::Moderator]);
    $conv = Conversation::factory()->for(ChannelAccount::factory()->state(['platform'=>Platform::Facebook]), 'channelAccount')->create();

    // 2026-09-10 23:30 Cairo (UTC+3) = 20:30 UTC -> Cairo day 2026-09-10.
    // 2026-09-10 22:00 UTC = 2026-09-11 01:00 Cairo -> Cairo day 2026-09-11.
    foreach (['2026-09-10 20:30:00', '2026-09-10 22:00:00'] as $at) {
        $m = Message::factory()->create([
            'conversation_id' => $conv->id, 'direction' => MessageDirection::Out,
            'sender_type' => SenderType::User, 'user_id' => $this->mod->id,
        ]);
        $m->forceFill(['created_at' => Carbon::parse($at, 'UTC')])->save();
    }
});

it('converts cairo dates to utc day bounds for reports', function () {
    $this->actingAs($this->mod)->get('/reports/me?from=2026-09-10&to=2026-09-10')->assertOk()
        ->assertInertia(fn ($page) => $page->component('Reports/Me')
            ->where('metrics.messages_sent', 1)
            ->where('range.from', '2026-09-10')->where('range.to', '2026-09-10'));

    $token = $this->mod->createToken('t')->plainTextToken;
    $this->withToken($token)->getJson('/api/v1/reports/me?from=2026-09-11&to=2026-09-11')
        ->assertOk()->assertJsonPath('metrics.messages_sent', 1);
    $this->withToken($token)->getJson('/api/v1/reports/me?from=2026-09-10&to=2026-09-11')
        ->assertOk()->assertJsonPath('metrics.messages_sent', 2);
});

it('defaults the range to today in cairo', function () {
    Carbon::setTestNow(Carbon::parse('2026-09-10 21:00:00', 'UTC')); // 2026-09-11 00:00 Cairo
    $this->actingAs($this->mod)->get('/reports/me')->assertOk()
        ->assertInertia(fn ($page) => $page->where('range.from', '2026-09-11')->where('metrics.messages_sent', 1));
    Carbon::setTestNow();
});
