<?php

use App\Enums\{Platform, UserRole};
use App\Models\{ChannelAccount, Conversation, Customer, CustomerIdentity, Order, User};
use Illuminate\Support\Facades\Event;

beforeEach(function () {
    Event::fake([App\Events\ConversationUpdated::class, App\Events\MessageCreated::class]);
    $this->mod = User::factory()->create(['role'=>UserRole::Moderator]);
    $this->mod->userPlatforms()->create(['platform'=>Platform::Facebook]);
    $this->token = $this->mod->createToken('phone')->plainTextToken;

    $this->customer = Customer::factory()->create(['phone'=>'01001234567']);
    CustomerIdentity::factory()->for($this->customer)->create(['platform'=>Platform::Facebook]);
    CustomerIdentity::factory()->for($this->customer)->create(['platform'=>Platform::TikTok]);
    $this->fbOrder = Order::factory()->for($this->customer)->create(['platform'=>Platform::Facebook]);
    $this->ttOrder = Order::factory()->for($this->customer)->create(['platform'=>Platform::TikTok]);
});

it('forbids moderators from orders on other platforms (web and api)', function () {
    $this->actingAs($this->mod)->get("/orders/{$this->ttOrder->id}")->assertForbidden();
    $this->withToken($this->token)->getJson("/api/v1/orders/{$this->ttOrder->id}")->assertForbidden();
    $this->withToken($this->token)->getJson("/api/v1/orders/{$this->fbOrder->id}")->assertOk();
});

it('filters nested customer identities and orders for moderators', function () {
    $conv = Conversation::factory()->for($this->customer)
        ->for(ChannelAccount::factory()->state(['platform'=>Platform::Facebook]), 'channelAccount')->create();

    $detail = $this->actingAs($this->mod)->getJson("/inbox/conversations/{$conv->id}")->assertOk();
    expect($detail->json('customer.identities.*.platform'))->toBe(['facebook'])
        ->and($detail->json('customer.orders.*.id'))->toBe([$this->fbOrder->id]);

    $this->actingAs($this->mod)->get("/customers/{$this->customer->id}")->assertOk()
        ->assertInertia(fn ($page) => $page->component('Customers/Show')
            ->has('customer.identities', 1)->where('customer.identities.0.platform', 'facebook')
            ->has('customer.orders', 1)->where('customer.orders.0.id', $this->fbOrder->id));

    $api = $this->withToken($this->token)->getJson("/api/v1/customers/{$this->customer->id}")->assertOk();
    expect($api->json('data.identities.*.platform'))->toBe(['facebook'])
        ->and($api->json('data.orders.*.id'))->toBe([$this->fbOrder->id]);

    $list = $this->actingAs($this->mod)->get('/customers')->assertOk();
    $list->assertInertia(fn ($page) => $page->where('customers.data.0.identities', fn ($ids) => collect($ids)->pluck('platform')->all() === ['facebook']));

    // Supervisors still see everything.
    $sup = User::factory()->create(['role'=>UserRole::Supervisor]);
    expect($this->actingAs($sup)->getJson("/inbox/conversations/{$conv->id}")->json('customer.orders'))->toHaveCount(2);
});

it('excludes merge suggestions without an allowed identity', function () {
    $tiktokOnly = Customer::factory()->create(['phone'=>'+20 100 123 4567']);
    CustomerIdentity::factory()->for($tiktokOnly)->create(['platform'=>Platform::TikTok]);
    $fbTwin = Customer::factory()->create(['phone'=>'00201001234567']);
    CustomerIdentity::factory()->for($fbTwin)->create(['platform'=>Platform::Facebook]);
    CustomerIdentity::factory()->for($fbTwin)->create(['platform'=>Platform::WhatsApp]);

    $res = $this->actingAs($this->mod)->getJson("/customers/{$this->customer->id}/merge-suggestions")->assertOk();
    expect($res->json('data.*.id'))->toBe([$fbTwin->id])
        ->and($res->json('data.0.identities.*.platform'))->toBe(['facebook']);
});
