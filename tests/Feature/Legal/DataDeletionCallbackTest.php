<?php

use App\Enums\Platform;
use App\Legal\Jobs\DeleteMetaUserData;
use App\Legal\SignedRequest;
use App\Models\BotRun;
use App\Models\Comment;
use App\Models\Conversation;
use App\Models\ConversationNote;
use App\Models\Customer;
use App\Models\CustomerIdentity;
use App\Models\DataDeletionRequest;
use App\Models\Message;
use App\Models\MessageAttachment;
use App\Models\SupportCase;
use App\Models\WebhookEvent;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    config(['crm.meta.app_secret' => 'test-app-secret', 'crm.meta.app_id' => 'app-1', 'app.url' => 'https://crm.example.test']);
    Storage::fake('media');
    // Meta's ids_for_pages lookup (audit A8): unanswered by default, so it "fails".
    fakeMetaGraph([]);
});

function deletionSigned(array $payload, string $secret = 'test-app-secret'): string
{
    return SignedRequest::make($payload, $secret);
}

/**
 * A Messenger customer with a conversation, message, photo, comment, case, bot run,
 * note and raw webhook payload.
 *
 * @return array<string, Model>
 */
function customerWithMessengerData(string $psid): array
{
    $customer = Customer::factory()->create(['name' => 'Customer '.$psid, 'phone' => '01000000000']);
    CustomerIdentity::factory()->create(['customer_id' => $customer->id, 'platform' => Platform::Facebook, 'external_id' => $psid]);
    $conversation = Conversation::factory()->create(['customer_id' => $customer->id, 'platform' => Platform::Facebook]);
    $message = Message::factory()->create(['conversation_id' => $conversation->id, 'body' => 'my phone is 010 for '.$psid]);
    Storage::disk('media')->put("inbound/{$psid}.jpg", 'jpeg-bytes');
    $attachment = MessageAttachment::factory()->create(['message_id' => $message->id, 'disk' => 'media', 'path' => "inbound/{$psid}.jpg"]);
    $comment = Comment::factory()->create(['customer_id' => $customer->id, 'body' => 'price? '.$psid]);
    $case = SupportCase::factory()->create(['conversation_id' => $conversation->id]);
    $run = BotRun::factory()->create(['conversation_id' => $conversation->id]);
    $note = ConversationNote::factory()->create(['conversation_id' => $conversation->id]);
    $event = WebhookEvent::create([
        'provider' => 'facebook', 'event_type' => 'message', 'dedupe_key' => 'mid.'.$psid,
        'received_at' => now(), 'payload' => ['sender' => ['id' => $psid], 'message' => ['text' => 'hi']],
        'signature_valid' => true, 'status' => 'processed', 'attempts' => 1,
    ]);

    return compact('customer', 'conversation', 'message', 'attachment', 'comment', 'case', 'run', 'note', 'event');
}

it('parses a valid signed_request and rejects tampered ones', function () {
    $signed = deletionSigned(['user_id' => '123', 'issued_at' => time()]);

    expect(SignedRequest::parse($signed, 'test-app-secret'))->toMatchArray(['user_id' => '123'])
        ->and(SignedRequest::parse($signed, 'other-secret'))->toBeNull()
        ->and(SignedRequest::parse($signed, null))->toBeNull()
        ->and(SignedRequest::parse('garbage', 'test-app-secret'))->toBeNull()
        ->and(SignedRequest::parse('a.b.c', 'test-app-secret'))->toBeNull();

    // Same signature, swapped payload.
    [$signature] = explode('.', $signed);
    $forged = rtrim(strtr(base64_encode((string) json_encode(['user_id' => '999', 'algorithm' => 'HMAC-SHA256'])), '+/', '-_'), '=');
    expect(SignedRequest::parse($signature.'.'.$forged, 'test-app-secret'))->toBeNull();
});

it('accepts a valid callback, queues the deletion and answers with the status url and code', function () {
    Queue::fake();

    $response = $this->post('/webhooks/facebook/data-deletion', [
        'signed_request' => deletionSigned(['user_id' => '555000111', 'issued_at' => time()]),
    ])->assertOk();

    $request = DataDeletionRequest::sole();
    expect($request->status)->toBe('pending')
        ->and($request->platform)->toBe('facebook')
        ->and($request->external_user_id)->toBe('555000111')
        ->and($request->confirmation_code)->toHaveLength(16);

    $response->assertExactJson([
        'url' => 'https://crm.example.test/data-deletion?code='.$request->confirmation_code,
        'confirmation_code' => $request->confirmation_code,
    ]);

    Queue::assertPushed(DeleteMetaUserData::class, fn (DeleteMetaUserData $job) => $job->deletionRequestId === $request->id);
});

it('rejects a callback with a bad or missing signature', function (?string $signed) {
    Queue::fake();

    $this->post('/webhooks/facebook/data-deletion', array_filter(['signed_request' => $signed]))
        ->assertStatus(400);

    expect(DataDeletionRequest::count())->toBe(0);
    Queue::assertNothingPushed();
})->with([
    'wrong secret' => fn () => deletionSigned(['user_id' => '1'], 'not-the-secret'),
    'no user id' => fn () => deletionSigned(['issued_at' => time()]),
    'garbage' => 'not-a-signed-request',
    'missing' => null,
]);

it('rejects every callback while the app secret is not configured', function () {
    config(['crm.meta.app_secret' => null]);

    $this->post('/webhooks/facebook/data-deletion', ['signed_request' => deletionSigned(['user_id' => '1'], '')])
        ->assertStatus(400);

    expect(DataDeletionRequest::count())->toBe(0);
});

it("deletes only that person's data and marks the request completed", function () {
    $target = customerWithMessengerData('111222333');
    $other = customerWithMessengerData('444555666');

    $this->post('/webhooks/facebook/data-deletion', [
        'signed_request' => deletionSigned(['user_id' => '111222333']),
    ])->assertOk();

    // The target is gone, rows and files alike.
    foreach ($target as $name => $model) {
        expect($model::class::whereKey($model->getKey())->exists())->toBeFalse("{$name} should be deleted");
    }
    expect(CustomerIdentity::where('external_id', '111222333')->exists())->toBeFalse();
    Storage::disk('media')->assertMissing('inbound/111222333.jpg');

    // The other customer is untouched.
    foreach ($other as $name => $model) {
        expect($model::class::whereKey($model->getKey())->exists())->toBeTrue("{$name} should be kept");
    }
    expect(CustomerIdentity::where('external_id', '444555666')->exists())->toBeTrue();
    Storage::disk('media')->assertExists('inbound/444555666.jpg');

    $request = DataDeletionRequest::sole();
    expect($request->status)->toBe('completed')->and($request->completed_at)->not->toBeNull();

    $this->withoutVite()->get('/data-deletion?lang=en&code='.$request->confirmation_code)
        ->assertOk()
        ->assertSee('data-status="completed"', false)
        ->assertDontSee('111222333');
});

it('marks the request not_found when no customer has that id', function () {
    $other = customerWithMessengerData('444555666');

    fakeMetaGraph(['GET 000000001/ids_for_pages' => ['data' => [['id' => '999888777', 'page' => ['id' => 'page-1']]]]]);

    $this->post('/webhooks/facebook/data-deletion', [
        'signed_request' => deletionSigned(['user_id' => '000000001']),
    ])->assertOk();

    expect(DataDeletionRequest::sole()->status)->toBe('not_found')
        ->and(Customer::whereKey($other['customer']->id)->exists())->toBeTrue()
        ->and(Message::whereKey($other['message']->id)->exists())->toBeTrue();
});

it('only matches the id on the same platform', function () {
    $instagram = Customer::factory()->create();
    CustomerIdentity::factory()->create(['customer_id' => $instagram->id, 'platform' => Platform::Instagram, 'external_id' => '777']);

    fakeMetaGraph(['GET 777/ids_for_pages' => ['data' => [['id' => '778', 'page' => ['id' => 'page-1']]]]]);

    $this->post('/webhooks/facebook/data-deletion', [
        'signed_request' => deletionSigned(['user_id' => '777']),
    ])->assertOk();

    expect(DataDeletionRequest::sole()->status)->toBe('not_found')
        ->and(Customer::whereKey($instagram->id)->exists())->toBeTrue();
});

it('does not run again for a request that is already finished', function () {
    $target = customerWithMessengerData('111222333');
    $request = DataDeletionRequest::create([
        'confirmation_code' => DataDeletionRequest::newConfirmationCode(),
        'platform' => 'facebook',
        'external_user_id' => '111222333',
        'status' => 'completed',
        'requested_at' => now(),
        'completed_at' => now()->subDay(),
    ]);

    (new DeleteMetaUserData($request->id))->handle();

    expect($request->fresh()->completed_at->isYesterday())->toBeTrue()
        ->and(Customer::whereKey($target['customer']->id)->exists())->toBeTrue();
});

// Audit A8: Meta's callback carries the APP-scoped user id; identities hold PAGE-scoped ids.

it('finds the customer through Meta ids_for_pages and deletes their data', function () {
    $target = customerWithMessengerData('psid-on-our-page');
    $other = customerWithMessengerData('psid-someone-else');
    fakeMetaGraph(['GET asid-42/ids_for_pages' => function ($request) {
        expect($request->data()['access_token'] ?? null)->toBe('app-1|test-app-secret');

        return ['data' => [['id' => 'psid-on-our-page', 'page' => ['id' => 'page-1', 'name' => 'Le Voile']]]];
    }]);

    $this->post('/webhooks/facebook/data-deletion', [
        'signed_request' => deletionSigned(['user_id' => 'asid-42']),
    ])->assertOk();

    expect(DataDeletionRequest::sole()->status)->toBe('completed')
        ->and(Customer::whereKey($target['customer']->id)->exists())->toBeFalse()
        ->and(WebhookEvent::whereKey($target['event']->id)->exists())->toBeFalse()
        ->and(Customer::whereKey($other['customer']->id)->exists())->toBeTrue();
});

it('keeps the request for manual review when Meta cannot map the id', function (array $routes) {
    Log::spy();
    $other = customerWithMessengerData('444555666');
    fakeMetaGraph($routes);

    $this->post('/webhooks/facebook/data-deletion', [
        'signed_request' => deletionSigned(['user_id' => 'asid-77']),
    ])->assertOk();

    $request = DataDeletionRequest::sole();
    expect($request->status)->toBe('needs_manual_review')
        ->and($request->external_user_id)->toBe('asid-77')
        ->and($request->completed_at)->toBeNull()
        ->and(Customer::whereKey($other['customer']->id)->exists())->toBeTrue();
    Log::shouldHaveReceived('warning')->withArgs(fn ($msg, $ctx) => $msg === 'crm.data_deletion.needs_manual_review' && $ctx['request_id'] === $request->id);
})->with([
    'graph error' => [['GET asid-77/ids_for_pages' => [['error' => ['message' => 'nope']], 400]]],
    'no page ids' => [['GET asid-77/ids_for_pages' => ['data' => []]]],
    'not answered' => [[]],
]);

it('asks for manual review without calling Meta when the app id is not configured', function () {
    config(['crm.meta.app_id' => null]);
    Http::preventStrayRequests();

    $this->post('/webhooks/facebook/data-deletion', [
        'signed_request' => deletionSigned(['user_id' => 'asid-77']),
    ])->assertOk();

    expect(DataDeletionRequest::sole()->status)->toBe('needs_manual_review');
});

it('shows a manual-review request honestly on the status page', function () {
    $request = DataDeletionRequest::create([
        'confirmation_code' => DataDeletionRequest::newConfirmationCode(),
        'platform' => 'facebook',
        'external_user_id' => 'asid-77',
        'status' => 'needs_manual_review',
        'requested_at' => now(),
    ]);

    $this->withoutVite();

    $this->get('/data-deletion?lang=en&code='.$request->confirmation_code)
        ->assertOk()
        ->assertSee('data-status="needs_manual_review"', false)
        ->assertSee('reviewing it by hand')
        ->assertDontSee('asid-77');
    $this->get('/data-deletion?lang=ar&code='.$request->confirmation_code)
        ->assertOk()
        ->assertSee('data-status="needs_manual_review"', false);
});
