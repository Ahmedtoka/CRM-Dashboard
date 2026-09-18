<?php

use App\Analytics\ActivityLogger;
use App\Enums\{MessageDirection, Platform, SenderType, UserRole};
use App\Models\{ActivityLog, ChannelAccount, Conversation, Customer, CustomerIdentity, Message, Order, Tag, User};
use Illuminate\Support\Facades\Event;

beforeEach(fn () => Event::fake([
    App\Events\MessageCreated::class, App\Events\MessageUpdated::class, App\Events\ConversationUpdated::class,
    App\Events\CommentUpdated::class, App\Events\OrderUpdated::class, App\Events\UserNotified::class,
]));

it('returns conversation detail, typing lock, notes and tags', function () {
    $acc = ChannelAccount::factory()->create(['platform'=>Platform::Facebook]);
    $c = Conversation::factory()->for($acc, 'channelAccount')->create(['last_customer_message_at'=>now()->subMinutes(3), 'last_message_at'=>now()->subMinutes(3)]);
    Message::factory()->create(['conversation_id'=>$c->id, 'direction'=>MessageDirection::In, 'sender_type'=>SenderType::Customer, 'body'=>'بكام؟']);
    $admin = User::factory()->create(['role'=>UserRole::Admin, 'name'=>'Mona']);
    $other = User::factory()->create(['role'=>UserRole::Supervisor]);
    $tag = Tag::factory()->create();

    $this->actingAs($admin)->getJson("/inbox/conversations/{$c->id}")->assertOk()
        ->assertJsonPath('conversation.last_message_preview', 'بكام؟')
        ->assertJsonPath('conversation.waiting_since', $c->last_customer_message_at->toIso8601String())
        ->assertJsonPath('messages.0.sender_type', 'customer')
        ->assertJsonPath('window.mode', 'open')
        ->assertJsonStructure(['notes', 'customer' => ['identities', 'orders'], 'participants', 'lock' => ['holder']]);

    $this->actingAs($admin)->postJson("/inbox/conversations/{$c->id}/typing")->assertOk()
        ->assertJson(['locked'=>true, 'holder'=>['id'=>$admin->id, 'name'=>'Mona']]);
    $this->actingAs($other)->postJson("/inbox/conversations/{$c->id}/typing")->assertOk()
        ->assertJson(['locked'=>false, 'holder'=>['id'=>$admin->id]]);

    $this->actingAs($admin)->postJson("/inbox/conversations/{$c->id}/notes", ['body'=>'VIP'])->assertCreated()->assertJsonPath('data.user.id', $admin->id);
    expect(ActivityLog::where('action', ActivityLogger::NOTE_ADDED)->exists())->toBeTrue();

    $this->actingAs($admin)->postJson("/inbox/conversations/{$c->id}/tags", ['tag_ids'=>[$tag->id]])->assertOk()->assertJsonPath('data.tags.0.id', $tag->id);
});

it('serves the api conversation list and send endpoint with a token', function () {
    $acc = ChannelAccount::factory()->create(['platform'=>Platform::WhatsApp]);
    $cust = Customer::factory()->create();
    CustomerIdentity::factory()->for($cust)->create(['platform'=>Platform::WhatsApp]);
    $c = Conversation::factory()->for($cust)->for($acc, 'channelAccount')->create(['last_customer_message_at'=>now()->subHour()]);
    $mod = User::factory()->create(['role'=>UserRole::Moderator]);
    $mod->userPlatforms()->create(['platform'=>Platform::WhatsApp]);
    $token = $mod->createToken('phone')->plainTextToken;

    $this->withToken($token)->getJson('/api/v1/conversations')->assertOk()->assertJsonPath('data.0.id', $c->id);
    // Optimistic response is the queued row; the (sync, in tests) job then marks it sent.
    $id = $this->withToken($token)->postJson("/api/v1/conversations/{$c->id}/messages", ['body'=>'أهلا'])
        ->assertCreated()->assertJsonPath('data.user.id', $mod->id)->assertJsonPath('data.status', 'queued')->json('data.id');
    expect(Message::find($id)->status->value)->toBe('sent');
    $this->withToken($token)->getJson('/api/v1/quick-replies')->assertOk();
    $this->withToken($token)->getJson('/api/v1/reports/team')->assertForbidden();
});

it('ingests simulator messages through the webhook pipeline', function () {
    config(['crm.dev_tools' => true]);
    $admin = User::factory()->create(['role'=>UserRole::Admin]);

    $this->actingAs($admin)->postJson('/simulator/message', ['platform'=>'instagram', 'customer_key'=>'cust-1', 'name'=>'Nour', 'text'=>'عايز اكلم حد'])
        ->assertCreated();

    $identity = CustomerIdentity::where('platform', 'instagram')->where('external_id', 'cust-1')->firstOrFail();
    expect(Conversation::where('customer_id', $identity->customer_id)->exists())->toBeTrue();
});

it('merges customers sharing a phone', function () {
    $sup = User::factory()->create(['role'=>UserRole::Supervisor]);
    $a = Customer::factory()->create(['phone'=>'01001234567', 'email'=>null, 'orders_count'=>1, 'total_spent'=>100]);
    $b = Customer::factory()->create(['phone'=>'+20 100 123 4567', 'email'=>'b@example.com', 'orders_count'=>2, 'total_spent'=>50]);
    CustomerIdentity::factory()->for($b)->create(['platform'=>Platform::WhatsApp]);
    $order = Order::factory()->for($b)->create();

    $this->actingAs($sup)->getJson("/customers/{$a->id}/merge-suggestions")->assertOk()->assertJsonPath('data.0.id', $b->id);
    $this->actingAs($sup)->postJson("/customers/{$a->id}/merge", ['other_id'=>$b->id])->assertOk()->assertJsonPath('data.orders_count', 3);

    expect(Customer::find($b->id))->toBeNull()
        ->and($order->fresh()->customer_id)->toBe($a->id)
        ->and($a->fresh()->email)->toBe('b@example.com')
        ->and($a->identities()->count())->toBe(1)
        ->and(ActivityLog::where('action', ActivityLogger::CUSTOMER_MERGED)->where('user_id', $sup->id)->exists())->toBeTrue();

    $mod = User::factory()->create(['role'=>UserRole::Moderator]);
    $this->actingAs($mod)->postJson("/customers/{$a->id}/merge", ['other_id'=>$a->id])->assertForbidden();
});

it('logs web login and logout', function () {
    $u = User::factory()->create();
    $this->post('/login', ['email'=>$u->email, 'password'=>'password']);
    $this->assertAuthenticated();
    $this->post('/logout');
    $this->assertGuest();

    expect(ActivityLog::where('user_id', $u->id)->pluck('action')->all())
        ->toContain(ActivityLogger::USER_LOGIN, ActivityLogger::USER_LOGOUT);
});

it('shares crm props with inertia pages', function () {
    ChannelAccount::factory()->create(['status'=>'error', 'last_error'=>'Token expired']);
    $sup = User::factory()->create(['role'=>UserRole::Supervisor, 'locale'=>'en']);
    $admin = User::factory()->create(['role'=>UserRole::Admin]);

    $this->actingAs($sup)->get('/reports/bot')->assertOk()
        ->assertInertia(fn ($page) => $page->component('Reports/Bot')
            ->where('auth.user.id', $sup->id)->where('auth.user.platforms', [])
            ->where('locale', 'en')
            ->where('platforms.0.value', 'facebook')
            ->where('channelAlerts', [])
            ->has('metrics.handovers'));

    $this->actingAs($admin)->get('/reports/bot')->assertOk()
        ->assertInertia(fn ($page) => $page->where('channelAlerts.0.last_error', 'Token expired'));
});
