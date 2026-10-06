<?php

use App\Enums\MessageDirection;
use App\Enums\Platform;
use App\Enums\SenderType;
use App\Enums\UserRole;
use App\Models\ChannelAccount;
use App\Models\City;
use App\Models\Conversation;
use App\Models\ConversationOutcome;
use App\Models\Customer;
use App\Models\Message;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\User;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;

it('records ordered on the running episode when the drawer places an order', function () {
    Event::fake();
    $acc = ChannelAccount::factory()->create(['platform' => Platform::Instagram]);
    $c = Conversation::factory()->for(Customer::factory())->for($acc, 'channelAccount')->create(['platform' => Platform::Instagram]);
    $first = Message::factory()->for($c)->create(['direction' => MessageDirection::In, 'sender_type' => SenderType::Customer]);
    $variant = ProductVariant::factory()->for(Product::factory())->create(['price' => 500]);
    $city = City::factory()->create(['shipping_fee' => 60]);
    $sup = User::factory()->create(['role' => UserRole::Supervisor]);

    $this->actingAs($sup)->postJson("/inbox/conversations/{$c->id}/orders", [
        'idempotency_key' => (string) Str::uuid(), 'type' => 'cod',
        'items' => [['variant_id' => $variant->id, 'qty' => 1]],
        'shipping' => ['name' => 'Nour', 'phone' => '01001234567', 'city_id' => $city->id, 'address' => '12 شارع النصر'],
    ])->assertSuccessful();

    $row = ConversationOutcome::sole();
    expect($row->episode_key)->toBe('m'.$first->id)->and($row->outcome)->toBe('ordered')
        ->and($row->source)->toBe('auto')->and($row->order_id)->not->toBeNull()->and($row->ended_at)->toBeNull();
});
