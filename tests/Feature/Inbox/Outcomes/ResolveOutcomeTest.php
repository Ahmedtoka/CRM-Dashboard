<?php

use App\Enums\MessageDirection;
use App\Enums\Platform;
use App\Enums\SenderType;
use App\Enums\UserRole;
use App\Models\ChannelAccount;
use App\Models\Conversation;
use App\Models\ConversationOutcome;
use App\Models\Message;
use App\Models\Order;
use App\Models\User;
use Illuminate\Support\Facades\Event;
use Laravel\Sanctum\Sanctum;

beforeEach(fn () => Event::fake());

/** @return array{0: Conversation, 1: User} */
function s3Chat(): array
{
    $acc = ChannelAccount::factory()->create(['platform' => Platform::Facebook]);
    $c = Conversation::factory()->for($acc, 'channelAccount')->create(['platform' => Platform::Facebook]);
    Message::factory()->for($c)->create(['direction' => MessageDirection::In, 'sender_type' => SenderType::Customer]);
    $mod = User::factory()->create(['role' => UserRole::Moderator]);
    $mod->userPlatforms()->create(['platform' => Platform::Facebook]);

    return [$c, $mod];
}

it('refuses a web resolve without an outcome', function () {
    [$c, $mod] = s3Chat();

    $this->actingAs($mod)->postJson("/inbox/conversations/{$c->id}/resolve")
        ->assertUnprocessable()->assertJsonValidationErrors('outcome');

    expect($c->fresh()->status->value)->toBe('open')->and(ConversationOutcome::count())->toBe(0);
});

it('resolves on the web with an outcome, or without one when an order exists', function () {
    [$c, $mod] = s3Chat();
    $this->actingAs($mod)->postJson("/inbox/conversations/{$c->id}/resolve", ['outcome' => 'price'])
        ->assertOk()->assertJsonPath('data.status', 'resolved');
    expect(ConversationOutcome::sole()->outcome)->toBe('price')->and(ConversationOutcome::sole()->ended_by)->toBe('resolve');

    [$ordered, $mod2] = s3Chat();
    Order::factory()->create(['conversation_id' => $ordered->id, 'customer_id' => $ordered->customer_id, 'status' => 'confirmed']);
    $this->actingAs($mod2)->postJson("/inbox/conversations/{$ordered->id}/resolve")->assertOk();
    expect($ordered->outcomes()->sole()->outcome)->toBe('ordered');
});

it('keeps the mobile API resolve payload: no body still resolves and records unknown', function () {
    [$c, $mod] = s3Chat();
    Sanctum::actingAs($mod);

    $this->postJson("/api/v1/conversations/{$c->id}/resolve")
        ->assertOk()
        ->assertJsonPath('data.id', $c->id)
        ->assertJsonPath('data.status', 'resolved');

    $row = ConversationOutcome::sole();
    expect($row->outcome)->toBe('unknown')->and($row->source)->toBe('agent')->and($row->ended_by)->toBe('api_resolve')
        ->and($row->set_by_id)->toBe($mod->id);
});

it('takes an outcome from the mobile API when one is sent', function () {
    [$c, $mod] = s3Chat();
    Sanctum::actingAs($mod);

    $this->postJson("/api/v1/conversations/{$c->id}/resolve", ['outcome' => 'size_out'])->assertOk();

    expect(ConversationOutcome::sole()->outcome)->toBe('size_out');
});

it('returns the same resource keys from the API resolve as before S3', function () {
    [$c, $mod] = s3Chat();
    Sanctum::actingAs($mod);

    $keys = array_keys($this->postJson("/api/v1/conversations/{$c->id}/resolve")->assertOk()->json('data'));

    expect($keys)->not->toContain('outcome')->and($keys)->toContain('status', 'handler', 'queue_entry', 'can');
});
