<?php

use App\Enums\Platform;
use App\Media\MediaUrls;
use App\Media\SampleMedia;
use App\Models\ChannelAccount;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\MessageAttachment;
use App\Models\User;
use Illuminate\Support\Facades\Storage;

// Review 01, I1: every mobile limiter has its own counter.

function mbFreshGuard(): void
{
    app('auth')->forgetGuards();
}

it('does not lock login after many media fetches from the same address', function () {
    Storage::fake('media');
    Storage::disk('media')->put('inbound/x.png', SampleMedia::bytes('image'));
    $conv = Conversation::factory()->for(ChannelAccount::factory()->create(['platform' => Platform::WhatsApp]), 'channelAccount')->create();
    $a = MessageAttachment::factory()->stored()->create([
        'message_id' => Message::factory()->create(['conversation_id' => $conv->id])->id,
        'path' => 'inbound/x.png', 'mime' => 'image/png', 'type' => 'image',
    ]);
    $p = parse_url(MediaUrls::signedShow($a));
    $url = $p['path'].'?'.$p['query'];

    foreach (range(1, 15) as $i) {
        $this->get($url)->assertOk();
    }

    $u = User::factory()->create(['password' => bcrypt('secret123')]);
    $this->postJson('/api/v1/auth/login', ['email' => $u->email, 'password' => 'secret123', 'device_name' => 'p'])->assertOk();
});

it('limits login per email and address, not for everyone on the address', function () {
    $u = User::factory()->create(['password' => bcrypt('secret123')]);
    $other = User::factory()->create(['password' => bcrypt('secret123')]);

    foreach (range(1, 10) as $i) {
        $this->postJson('/api/v1/auth/login', ['email' => $u->email, 'password' => 'wrong', 'device_name' => 'p'])->assertUnprocessable();
    }

    $this->postJson('/api/v1/auth/login', ['email' => $u->email, 'password' => 'wrong', 'device_name' => 'p'])->assertTooManyRequests();
    $this->postJson('/api/v1/auth/login', ['email' => $other->email, 'password' => 'secret123', 'device_name' => 'p'])->assertOk();
});

it('does not lock refresh or the test push after queue polling', function () {
    $u = User::factory()->create(['role' => 'moderator']);
    $token = $u->createToken('p', ['mobile'], now()->addDays(30))->plainTextToken;

    foreach (range(1, 12) as $i) {
        mbFreshGuard();
        $this->withToken($token)->getJson('/api/v1/queue/me')->assertOk();
    }

    mbFreshGuard();
    $this->withToken($token)->postJson('/api/v1/devices/test')->assertOk();
    mbFreshGuard();
    $this->withToken($token)->postJson('/api/v1/auth/refresh')->assertOk();
});

it('caps login attempts per address across many accounts', function () {
    foreach (range(1, 30) as $i) {
        $this->postJson('/api/v1/auth/login', ['email' => "spray{$i}@example.test", 'password' => 'x', 'device_name' => 'p'])->assertUnprocessable();
    }

    $this->postJson('/api/v1/auth/login', ['email' => 'spray31@example.test', 'password' => 'x', 'device_name' => 'p'])->assertTooManyRequests();
    $this->withServerVariables(['REMOTE_ADDR' => '10.9.9.9'])
        ->postJson('/api/v1/auth/login', ['email' => 'spray31@example.test', 'password' => 'x', 'device_name' => 'p'])->assertUnprocessable();
});
