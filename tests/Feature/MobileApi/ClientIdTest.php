<?php

use App\Enums\Handler;
use App\Enums\Platform;
use App\Enums\UserRole;
use App\Inbox\Jobs\SendOutboundMessage;
use App\Media\SampleMedia;
use App\Models\ChannelAccount;
use App\Models\Conversation;
use App\Models\ConversationNote;
use App\Models\Customer;
use App\Models\CustomerIdentity;
use App\Models\Message;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;

beforeEach(function () {
    Event::fake();
    Queue::fake();
    Storage::fake('media');
    $acc = ChannelAccount::factory()->create(['platform' => Platform::WhatsApp]);
    $cust = Customer::factory()->create();
    CustomerIdentity::factory()->for($cust)->create(['platform' => Platform::WhatsApp]);
    $this->conv = Conversation::factory()->for($cust)->for($acc, 'channelAccount')
        ->create(['platform' => Platform::WhatsApp, 'handler' => Handler::Human, 'last_customer_message_at' => now()->subMinute()]);
    $this->mod = User::factory()->create(['role' => UserRole::Moderator]);
    $this->mod->userPlatforms()->create(['platform' => Platform::WhatsApp]);
});

function mbSend(User $u, Conversation $c, array $body)
{
    Sanctum::actingAs($u);

    return test()->postJson("/api/v1/conversations/{$c->id}/messages", $body);
}

it('sends once for a repeated client_id and replays the same message with 200', function () {
    $clientId = (string) Str::uuid();

    $first = mbSend($this->mod, $this->conv, ['body' => 'أهلاً', 'client_id' => $clientId])->assertCreated();
    $second = mbSend($this->mod, $this->conv, ['body' => 'أهلاً', 'client_id' => $clientId])->assertOk();

    expect($second->json('data.id'))->toBe($first->json('data.id'))
        ->and($second->json('data.body'))->toBe('أهلاً')
        ->and(array_keys($second->json('data')))->toBe(array_keys($first->json('data')))
        ->and(Message::query()->where('conversation_id', $this->conv->id)->count())->toBe(1);
    Queue::assertPushed(SendOutboundMessage::class, 1);
});

it('treats the same client_id from another user, or another conversation, as a new send', function () {
    $clientId = (string) Str::uuid();
    $other = User::factory()->create(['role' => UserRole::Moderator]);
    $other->userPlatforms()->create(['platform' => Platform::WhatsApp]);
    $conv2 = Conversation::factory()->for($this->conv->customer)->for($this->conv->channelAccount, 'channelAccount')
        ->create(['platform' => Platform::WhatsApp, 'handler' => Handler::Human, 'last_customer_message_at' => now()->subMinute()]);

    mbSend($this->mod, $this->conv, ['body' => 'a', 'client_id' => $clientId])->assertCreated();
    mbSend($other, $this->conv, ['body' => 'a', 'client_id' => $clientId])->assertCreated();
    mbSend($this->mod, $conv2, ['body' => 'a', 'client_id' => $clientId])->assertCreated();

    expect(Message::count())->toBe(3);
});

it('keeps sending without a client_id exactly as before', function () {
    mbSend($this->mod, $this->conv, ['body' => 'x'])->assertCreated();
    mbSend($this->mod, $this->conv, ['body' => 'x'])->assertCreated();

    expect(Message::count())->toBe(2);
});

it('rejects a client_id that is not a uuid', function () {
    mbSend($this->mod, $this->conv, ['body' => 'x', 'client_id' => 'abc'])->assertUnprocessable()->assertJsonValidationErrors('client_id');
});

it('replays an attachment send with all of its messages', function () {
    Sanctum::actingAs($this->mod);
    $ids = collect(range(1, 2))->map(fn () => $this->postJson("/api/v1/conversations/{$this->conv->id}/attachments", [
        'file' => UploadedFile::fake()->createWithContent('p.png', SampleMedia::bytes('image')),
    ])->assertCreated()->json('data.id'))->all();
    $clientId = (string) Str::uuid();

    $first = mbSend($this->mod, $this->conv, ['body' => 'الصور', 'attachment_ids' => $ids, 'client_id' => $clientId])->assertCreated();
    $second = mbSend($this->mod, $this->conv, ['body' => 'الصور', 'attachment_ids' => $ids, 'client_id' => $clientId])->assertOk();

    expect($second->json('data.id'))->toBe($first->json('data.id'))
        ->and(array_column($second->json('messages'), 'id'))->toBe(array_column($first->json('messages'), 'id'))
        ->and(Message::query()->where('conversation_id', $this->conv->id)->count())->toBe(count($first->json('messages')));
});

it('sends again once the remembered message is gone or the window passed', function () {
    $clientId = (string) Str::uuid();
    mbSend($this->mod, $this->conv, ['body' => 'a', 'client_id' => $clientId])->assertCreated();

    $this->travel(49)->hours();
    $this->conv->forceFill(['last_customer_message_at' => now()->subMinute()])->save();
    mbSend($this->mod, $this->conv, ['body' => 'a', 'client_id' => $clientId])->assertCreated();

    expect(Message::count())->toBe(2);
});

it('answers 429 while the same client_id is still being sent elsewhere', function () {
    $clientId = (string) Str::uuid();
    Cache::lock("msg-client:{$this->mod->id}:{$this->conv->id}:{$clientId}:lock", 30)->get();

    mbSend($this->mod, $this->conv, ['body' => 'a', 'client_id' => $clientId])->assertStatus(429)->assertHeader('Retry-After', '2');

    expect(Message::count())->toBe(0);
});

it('stores a note once per client_id', function () {
    $clientId = (string) Str::uuid();
    Sanctum::actingAs($this->mod);

    $first = $this->postJson("/api/v1/conversations/{$this->conv->id}/notes", ['body' => 'متابعة', 'client_id' => $clientId])->assertCreated();
    $second = $this->postJson("/api/v1/conversations/{$this->conv->id}/notes", ['body' => 'متابعة', 'client_id' => $clientId])->assertOk();

    expect($second->json('data.id'))->toBe($first->json('data.id'))
        ->and(ConversationNote::count())->toBe(1);

    $this->postJson("/api/v1/conversations/{$this->conv->id}/notes", ['body' => 'تانية', 'client_id' => (string) Str::uuid()])->assertCreated();
    expect(ConversationNote::count())->toBe(2);
});

it('also accepts client_id on the web inbox without changing its replies', function () {
    $clientId = (string) Str::uuid();

    $this->actingAs($this->mod)->postJson("/inbox/conversations/{$this->conv->id}/messages", ['body' => 'w', 'client_id' => $clientId])->assertCreated();
    $this->actingAs($this->mod)->postJson("/inbox/conversations/{$this->conv->id}/messages", ['body' => 'w', 'client_id' => $clientId])->assertOk();

    expect(Message::count())->toBe(1);
});

it('refuses the same client_id reused for a different message or note with 409', function () {
    $clientId = (string) Str::uuid();

    mbSend($this->mod, $this->conv, ['body' => 'أولى', 'client_id' => $clientId])->assertCreated();
    mbSend($this->mod, $this->conv, ['body' => 'تانية', 'client_id' => $clientId])->assertStatus(409);

    $noteId = (string) Str::uuid();
    $this->postJson("/api/v1/conversations/{$this->conv->id}/notes", ['body' => 'a', 'client_id' => $noteId])->assertCreated();
    $this->postJson("/api/v1/conversations/{$this->conv->id}/notes", ['body' => 'b', 'client_id' => $noteId])->assertStatus(409);

    expect(Message::count())->toBe(1)->and(ConversationNote::count())->toBe(1);
});

it('treats a message and a note with the same client_id as separate sends', function () {
    $clientId = (string) Str::uuid();

    mbSend($this->mod, $this->conv, ['body' => 'x', 'client_id' => $clientId])->assertCreated();
    $this->postJson("/api/v1/conversations/{$this->conv->id}/notes", ['body' => 'x', 'client_id' => $clientId])->assertCreated();
});

it('explains a reused client_id in the moderator language', function () {
    $clientId = (string) Str::uuid();
    $this->mod->forceFill(['locale' => 'ar'])->save();

    mbSend($this->mod, $this->conv, ['body' => 'أولى', 'client_id' => $clientId])->assertCreated();
    mbSend($this->mod, $this->conv, ['body' => 'تانية', 'client_id' => $clientId])->assertStatus(409)
        ->assertJsonPath('message', __('errors.send.client_id_reused', [], 'ar'));

    $this->mod->forceFill(['locale' => 'en'])->save();
    mbSend($this->mod->fresh(), $this->conv, ['body' => 'تالتة', 'client_id' => $clientId])->assertStatus(409)
        ->assertJsonPath('message', __('errors.send.client_id_reused', [], 'en'));
});
