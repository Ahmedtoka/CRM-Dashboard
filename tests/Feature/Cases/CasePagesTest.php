<?php

use App\Enums\Platform;
use App\Enums\UserRole;
use App\Models\ChannelAccount;
use App\Models\Conversation;
use App\Models\Customer;
use App\Models\SupportCase;
use App\Models\User;

function caseConversation(Platform $platform, ?Customer $customer = null): Conversation
{
    $account = ChannelAccount::factory()->create(['platform' => $platform]);

    return Conversation::factory()->create(array_filter([
        'channel_account_id' => $account->id,
        'customer_id' => $customer?->id,
    ]));
}

it('renders the Cases page for a supervisor with all cases, filters and status counts', function () {
    $sup = User::factory()->create(['role' => UserRole::Supervisor]);
    $fbCase = SupportCase::factory()->create(['conversation_id' => caseConversation(Platform::Facebook)->id, 'status' => 'new']);
    $waCase = SupportCase::factory()->create(['conversation_id' => caseConversation(Platform::WhatsApp)->id, 'status' => 'closed', 'closed_at' => now()]);

    $this->actingAs($sup)->get('/cases')->assertOk()->assertInertia(fn ($page) => $page
        ->component('Cases')
        ->has('cases.data', 2)
        ->where('filters.status', null)
        ->where('counts.new', 1)
        ->where('counts.in_progress', 0)
        ->where('counts.closed', 1)
        ->where('counts.all', 2));

    expect(true)->toBeTrue(); // keep both ids referenced for clarity
    [$fbCase, $waCase];
});

it('filters cases by status while counts stay unaffected', function () {
    $sup = User::factory()->create(['role' => UserRole::Supervisor]);
    $open = SupportCase::factory()->create(['conversation_id' => caseConversation(Platform::Facebook)->id, 'status' => 'new']);
    SupportCase::factory()->create(['conversation_id' => caseConversation(Platform::Facebook)->id, 'status' => 'closed', 'closed_at' => now()]);

    $this->actingAs($sup)->get('/cases?status=closed')->assertOk()->assertInertia(fn ($page) => $page
        ->component('Cases')
        ->has('cases.data', 1)
        ->where('filters.status', 'closed')
        ->where('counts.new', 1)
        ->where('counts.closed', 1));

    expect($open->status)->toBe('new');
});

it('scopes the cases list to the moderators platforms', function () {
    $mod = User::factory()->create(['role' => UserRole::Moderator]);
    $mod->userPlatforms()->create(['platform' => Platform::Facebook]);

    $fbCase = SupportCase::factory()->create(['conversation_id' => caseConversation(Platform::Facebook)->id]);
    SupportCase::factory()->create(['conversation_id' => caseConversation(Platform::WhatsApp)->id]);

    $this->actingAs($mod)->get('/cases')->assertOk()->assertInertia(fn ($page) => $page
        ->component('Cases')
        ->has('cases.data', 1)
        ->where('cases.data.0.id', $fbCase->id));
});

it('blocks a moderator from viewing or updating a case outside their platforms with a 403', function () {
    $mod = User::factory()->create(['role' => UserRole::Moderator]);
    $mod->userPlatforms()->create(['platform' => Platform::Facebook]);

    $waCase = SupportCase::factory()->create(['conversation_id' => caseConversation(Platform::WhatsApp)->id]);

    $this->actingAs($mod)->getJson("/cases/{$waCase->id}")->assertForbidden();
    $this->actingAs($mod)->patchJson("/cases/{$waCase->id}", ['status' => 'closed'])->assertForbidden();
});

it('shows a single case as JSON to a user who can access its platform', function () {
    $sup = User::factory()->create(['role' => UserRole::Supervisor]);
    $case = SupportCase::factory()->returnExchange()->create(['conversation_id' => caseConversation(Platform::Facebook)->id]);

    $this->actingAs($sup)->getJson("/cases/{$case->id}")
        ->assertOk()
        ->assertJsonPath('data.id', $case->id)
        ->assertJsonPath('data.type', 'return_exchange');
});

it('closes a case, stamping closed_at/closed_by, then reopening clears them', function () {
    $sup = User::factory()->create(['role' => UserRole::Supervisor]);
    $case = SupportCase::factory()->create(['conversation_id' => caseConversation(Platform::Facebook)->id, 'status' => 'new']);

    $this->actingAs($sup)->patchJson("/cases/{$case->id}", ['status' => 'closed'])
        ->assertOk()
        ->assertJsonPath('data.status', 'closed');

    $case->refresh();
    expect($case->closed_at)->not->toBeNull();
    expect($case->closed_by_id)->toBe($sup->id);

    $this->actingAs($sup)->patchJson("/cases/{$case->id}", ['status' => 'in_progress'])
        ->assertOk()
        ->assertJsonPath('data.status', 'in_progress');

    $case->refresh();
    expect($case->closed_at)->toBeNull();
    expect($case->closed_by_id)->toBeNull();
});

it('assigns a case to a team member', function () {
    $sup = User::factory()->create(['role' => UserRole::Supervisor]);
    $agent = User::factory()->create(['role' => UserRole::Moderator]);
    $case = SupportCase::factory()->create(['conversation_id' => caseConversation(Platform::Facebook)->id]);

    $this->actingAs($sup)->patchJson("/cases/{$case->id}", ['assigned_to_id' => $agent->id])
        ->assertOk()
        ->assertJsonPath('data.assigned_to.id', $agent->id);
});

it('rejects an invalid status', function () {
    $sup = User::factory()->create(['role' => UserRole::Supervisor]);
    $case = SupportCase::factory()->create(['conversation_id' => caseConversation(Platform::Facebook)->id]);

    $this->actingAs($sup)->patchJson("/cases/{$case->id}", ['status' => 'bogus'])->assertStatus(422);
});

it("includes the conversation's cases in the conversation JSON (task 6, kept working)", function () {
    $sup = User::factory()->create(['role' => UserRole::Supervisor]);
    $conversation = caseConversation(Platform::Facebook);
    SupportCase::factory()->create(['conversation_id' => $conversation->id]);

    $this->actingAs($sup)->getJson("/inbox/conversations/{$conversation->id}")
        ->assertOk()
        ->assertJsonCount(1, 'cases');
});

it('searches cases by order number, returning only the matching case', function () {
    $sup = User::factory()->create(['role' => UserRole::Supervisor]);
    $target = SupportCase::factory()->create(['conversation_id' => caseConversation(Platform::Facebook)->id, 'order_number' => '#5566']);
    SupportCase::factory()->create(['conversation_id' => caseConversation(Platform::Facebook)->id, 'order_number' => '#7788']);

    $this->actingAs($sup)->get('/cases?q=5566')->assertOk()->assertInertia(fn ($page) => $page
        ->has('cases.data', 1)
        ->where('cases.data.0.id', $target->id));
});

it('searches cases by customer phone', function () {
    $sup = User::factory()->create(['role' => UserRole::Supervisor]);
    $customer = Customer::factory()->create(['phone' => '+201099998888']);
    $target = SupportCase::factory()->create(['conversation_id' => caseConversation(Platform::Facebook, $customer)->id]);
    SupportCase::factory()->create(['conversation_id' => caseConversation(Platform::Facebook)->id]);

    $this->actingAs($sup)->get('/cases?q=1099998888')->assertOk()->assertInertia(fn ($page) => $page
        ->has('cases.data', 1)
        ->where('cases.data.0.id', $target->id));
});

it('searches cases by customer name', function () {
    $sup = User::factory()->create(['role' => UserRole::Supervisor]);
    $customer = Customer::factory()->create(['name' => 'Mona Youssef']);
    $target = SupportCase::factory()->create(['conversation_id' => caseConversation(Platform::Facebook, $customer)->id]);
    SupportCase::factory()->create(['conversation_id' => caseConversation(Platform::Facebook)->id]);

    $this->actingAs($sup)->get('/cases?q=Mona+Youssef')->assertOk()->assertInertia(fn ($page) => $page
        ->has('cases.data', 1)
        ->where('cases.data.0.id', $target->id));
});

it("never lets a moderator's search reach a case on another platform", function () {
    $mod = User::factory()->create(['role' => UserRole::Moderator]);
    $mod->userPlatforms()->create(['platform' => Platform::Facebook]);

    $customer = Customer::factory()->create(['name' => 'Sara Samy', 'phone' => '+201011112222']);
    SupportCase::factory()->create(['conversation_id' => caseConversation(Platform::WhatsApp, $customer)->id, 'order_number' => '#9999']);

    $this->actingAs($mod)->get('/cases?q=Sara')->assertOk()->assertInertia(fn ($page) => $page->has('cases.data', 0));
    $this->actingAs($mod)->get('/cases?q=1011112222')->assertOk()->assertInertia(fn ($page) => $page->has('cases.data', 0));
    $this->actingAs($mod)->get('/cases?q=9999')->assertOk()->assertInertia(fn ($page) => $page->has('cases.data', 0));
});
