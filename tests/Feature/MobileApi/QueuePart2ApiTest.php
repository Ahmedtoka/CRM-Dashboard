<?php

use App\Enums\Handler;
use App\Enums\Platform;
use App\Enums\UserRole;
use App\Models\ChannelAccount;
use App\Models\Conversation;
use App\Models\Customer;
use App\Models\CustomerIdentity;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\QueueEntry;
use App\Models\QueueSetting;
use App\Models\Shift;
use App\Models\ShiftMember;
use App\Models\SupportCase;
use App\Models\User;
use App\Queue\Scoring\ScoreKeeper;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Event;
use Laravel\Sanctum\Sanctum;

/*
 * What landed on master after the mobile API (Queue Part 2 points + case resolution, «ابعت منتج»),
 * as the app sees it: the same shared traits on /api/v1.
 */

beforeEach(function () {
    Carbon::setTestNow(Carbon::parse('2026-10-05 12:00', 'Africa/Cairo'));
    QueueSetting::factory()->create(['id' => 1, 'enabled' => true]);
});

it('gives her own points of the day on queue/me and queue/me/points', function () {
    $shift = Shift::factory()->create(['status' => 'open', 'opened_at' => now()->subHours(2), 'starts_at' => now()->subHours(2), 'ends_at' => now()->addHours(6)]);
    $u = User::factory()->create(['role' => UserRole::Moderator, 'last_seen_at' => now()]);
    ShiftMember::factory()->for($shift)->create(['user_id' => $u->id, 'status' => 'available']);
    $e = QueueEntry::factory()->create(['shift_id' => $shift->id, 'assigned_user_id' => $u->id, 'status' => 'closed', 'close_reason' => 'problem', 'closed_at' => now()->subMinutes(5)]);
    app(ScoreKeeper::class)->award($u->id, 'base', 12, 'base_problem', ['ticket' => $e->ticket_no], 'base:'.$e->id, ['queue_entry_id' => $e->id]);
    $other = User::factory()->create(['role' => UserRole::Moderator]);

    Sanctum::actingAs($u);
    $this->getJson('/api/v1/queue/me')->assertOk()->assertJsonPath('data.points_today.total', 12);
    $this->getJson('/api/v1/queue/me/points')->assertOk()->assertJsonPath('data.points.total', 12)->assertJsonPath('data.user.id', $u->id);
    $this->getJson('/api/v1/queue/me/points?date=2026-08-01')->assertUnprocessable();

    Sanctum::actingAs($other);
    $this->getJson('/api/v1/queue/me/points')->assertOk()->assertJsonPath('data.points.total', 0)->assertJsonPath('data.user.id', $other->id);
});

it('resolves a case through CaseResolution and counts the resolved tab', function () {
    $acc = ChannelAccount::factory()->create(['platform' => Platform::Facebook]);
    $conv = Conversation::factory()->for($acc, 'channelAccount')->create(['platform' => Platform::Facebook]);
    $case = SupportCase::factory()->create(['conversation_id' => $conv->id, 'platform' => 'facebook', 'status' => 'in_progress']);
    $mod = User::factory()->create(['role' => UserRole::Moderator]);
    $mod->userPlatforms()->create(['platform' => Platform::Facebook]);
    Sanctum::actingAs($mod);

    $this->patchJson("/api/v1/cases/{$case->id}", ['status' => 'resolved'])->assertOk()
        ->assertJsonPath('data.status', 'resolved')
        ->assertJsonStructure(['data' => ['sla', 'opened_by']]);

    $this->getJson('/api/v1/cases')->assertOk()->assertJsonPath('counts.resolved', 1);
    $this->getJson('/api/v1/cases?status=resolved')->assertOk()->assertJsonCount(1, 'data');
});

it('sends a product card from the app («ابعت منتج»), with the out-of-stock confirmation', function () {
    Event::fake();
    $acc = ChannelAccount::factory()->create(['platform' => Platform::Facebook]);
    $cust = Customer::factory()->create();
    CustomerIdentity::factory()->for($cust)->create(['platform' => Platform::Facebook]);
    $conv = Conversation::factory()->for($cust)->for($acc, 'channelAccount')->create([
        'platform' => Platform::Facebook, 'handler' => Handler::Bot, 'last_customer_message_at' => now()->subMinutes(5)]);
    $product = Product::factory()->create(['title' => 'فستان ليلي', 'handle' => 'layla-dress', 'status' => 'active']);
    $variant = ProductVariant::factory()->for($product)->create(['title' => 'أسود / M', 'price' => 950, 'inventory_quantity' => 0, 'inventory_policy' => 'deny']);
    $mod = User::factory()->create(['role' => UserRole::Moderator]);
    $mod->userPlatforms()->create(['platform' => Platform::Facebook]);
    Sanctum::actingAs($mod);

    $this->postJson("/api/v1/conversations/{$conv->id}/product", ['variant_id' => $variant->id])
        ->assertStatus(409)->assertJsonPath('code', 'out_of_stock');
    $this->postJson("/api/v1/conversations/{$conv->id}/product", ['variant_id' => $variant->id, 'confirm_out_of_stock' => true])
        ->assertCreated()->assertJsonPath('data.cards.type', 'generic')->assertJsonPath('data.sender_type', 'user');

    $outsider = User::factory()->create(['role' => UserRole::Moderator]);
    $outsider->userPlatforms()->create(['platform' => Platform::WhatsApp]);
    Sanctum::actingAs($outsider);
    $this->postJson("/api/v1/conversations/{$conv->id}/product", ['variant_id' => $variant->id, 'confirm_out_of_stock' => true])->assertForbidden();
});
