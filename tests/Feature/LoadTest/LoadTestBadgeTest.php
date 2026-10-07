<?php

use App\Enums\Platform;
use App\Enums\UserRole;
use App\Http\Resources\ConversationResource;
use App\Http\Resources\QueueEntryResource;
use App\Http\Resources\ShiftMemberResource;
use App\Models\ChannelAccount;
use App\Models\Conversation;
use App\Models\QueueEntry;
use App\Models\User;
use App\Simulator\LoadTest\LoadTestChannels;
use App\Simulator\Simulator;

/** A load-test chat as the waves create it: the opener carries its run and scenario. */
function lbChat(): Conversation
{
    return app(Simulator::class)->customerMessage(Platform::Instagram, 'lb-1', 'دينا', 'المقاس ده متاح؟', loadTest: [
        'run' => 1, 'scenario' => 'size_color', 'name' => 'دينا', 'customer_key' => 'lb-1',
    ])->conversation->fresh();
}

it('tags the conversation a load-test opener opened, and marks it «تيست» without making it a team test', function () {
    $c = lbChat();

    expect($c->meta['load_test'])->toMatchArray(['run' => 1, 'scenario' => 'size_color', 'step' => 0])
        ->and($c->isLoadTest())->toBeTrue()
        ->and($c->is_test)->toBeFalse(); // reports, rating and idle sweep treat it like a real chat

    $row = (new ConversationResource($c))->resolve(request());
    expect($row['is_load_test'])->toBeTrue()->and($row['is_test'])->toBeFalse();
});

it('does not tag a real channel conversation even when the payload asks for it', function () {
    $c = Conversation::factory()->create();

    expect($c->isLoadTest())->toBeFalse()
        ->and((new ConversationResource($c))->resolve(request())['is_load_test'])->toBeFalse();
});

it('carries the badge on the board ticket and window', function () {
    $c = lbChat();
    $e = QueueEntry::factory()->create(['conversation_id' => $c->id, 'customer_id' => $c->customer_id, 'status' => 'active']);

    expect(QueueEntryResource::data($e->fresh())['is_load_test'])->toBeTrue()
        ->and(ShiftMemberResource::window($e->fresh())['is_load_test'])->toBeTrue()
        ->and(QueueEntryResource::data(QueueEntry::factory()->create())['is_load_test'])->toBeFalse();
});

it('keeps the load-test channels out of Settings → Channels', function () {
    LoadTestChannels::ensureAll();
    $real = ChannelAccount::factory()->create(['platform' => Platform::Facebook, 'driver' => 'live']);
    $admin = User::factory()->create(['role' => UserRole::Admin]);

    $this->actingAs($admin)->get('/settings/channels')->assertOk()
        ->assertInertia(fn ($page) => $page->has('accounts', 1)->where('accounts.0.id', $real->id));
});
