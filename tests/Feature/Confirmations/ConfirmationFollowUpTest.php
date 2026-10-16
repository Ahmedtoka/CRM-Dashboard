<?php

use App\Confirmations\CallOutcome;
use App\Confirmations\Events\OrderConfirmationAnswered;
use App\Confirmations\Events\OrderConfirmationCalled;
use App\Confirmations\Events\OrderConfirmationEditNote;
use App\Confirmations\Events\OrderConfirmationNoResponse;
use App\Enums\OrderConfirmationStatus;
use App\Enums\UserRole;
use App\Models\ActivityLog;
use App\Models\Conversation;
use App\Models\ConversationNote;
use App\Models\Order;
use App\Models\OrderConfirmation;
use App\Models\SupportCase;
use App\Models\User;
use App\Models\UserNotification;
use App\Shopify\Client\FakeShopifyTransport;
use App\Shopify\Connection\ShopifyIntegration;
use Illuminate\Support\Facades\Event;

beforeEach(function () {
    ShopifyIntegration::create(['shop_domain' => 'levoile-test.myshopify.com', 'access_token' => 't', 'api_secret' => 's', 'status' => 'connected']);
});

/** A sent-and-answered confirmation for a Shopify order, with its WhatsApp conversation. */
function answeredConfirmation(OrderConfirmationStatus $status, array $attrs = []): OrderConfirmation
{
    $conversation = Conversation::factory()->create();
    $order = Order::factory()->create(['shopify_order_id' => (string) fake()->unique()->numberBetween(1000, 999999), 'order_number' => '#1234']);

    return OrderConfirmation::factory()->sent()->create(array_merge([
        'order_id' => $order->id,
        'conversation_id' => $conversation->id,
        'status' => $status,
        'answer' => match ($status) {
            OrderConfirmationStatus::CancelRequested => 'cancel',
            OrderConfirmationStatus::EditRequested => 'edit',
            default => 'confirm',
        },
        'answered_at' => now(),
    ], $attrs));
}

/** @return list<list<string>> the tags of every tagsAdd call on the fake store */
function writtenTags(): array
{
    return array_column(FakeShopifyTransport::tagsAdded(), 'tags');
}

it('tags a confirmed order without opening a case', function () {
    $c = answeredConfirmation(OrderConfirmationStatus::Confirmed);

    OrderConfirmationAnswered::dispatch($c, 'confirm');

    expect(writtenTags())->toBe([['wa-confirmed']])
        ->and($c->fresh()->tag_synced_at)->not->toBeNull()
        ->and($c->fresh()->case_id)->toBeNull()
        ->and(SupportCase::count())->toBe(0);
});

it('tags a cancel request, opens one cancel_edit case and rings the supervisors', function () {
    $supervisor = User::factory()->create(['role' => UserRole::Supervisor, 'is_active' => true]);
    $moderator = User::factory()->create(['role' => UserRole::Moderator, 'is_active' => true]);
    $c = answeredConfirmation(OrderConfirmationStatus::CancelRequested);

    OrderConfirmationAnswered::dispatch($c, 'cancel');
    OrderConfirmationAnswered::dispatch($c->fresh(), 'cancel'); // a second tap

    $case = SupportCase::sole();
    expect(writtenTags()[0])->toBe(['wa-cancel-request'])
        ->and($case->type)->toBe('cancel_edit')
        ->and($case->conversation_id)->toBe($c->conversation_id)
        ->and($case->order_id)->toBe($c->order_id)
        ->and($case->data)->toMatchArray(['request' => 'cancel', 'order_id' => $c->order_id, 'source' => 'whatsapp_confirmation'])
        ->and($c->fresh()->case_id)->toBe($case->id)
        ->and(UserNotification::where('user_id', $supervisor->id)->where('type', 'case.created')->count())->toBe(1)
        ->and(UserNotification::where('user_id', $moderator->id)->count())->toBe(0);
});

it('reuses the open case when the customer switches from edit to cancel', function () {
    $c = answeredConfirmation(OrderConfirmationStatus::EditRequested);

    OrderConfirmationAnswered::dispatch($c, 'edit');
    $c->update(['status' => OrderConfirmationStatus::CancelRequested, 'answer' => 'cancel']);
    OrderConfirmationAnswered::dispatch($c->fresh(), 'cancel');

    expect(SupportCase::count())->toBe(1)
        ->and(SupportCase::sole()->data['request'])->toBe('cancel')
        ->and(writtenTags())->toBe([['wa-edit-request'], ['wa-cancel-request']])
        ->and(ConversationNote::where('body', 'like', '%غيّرت طلبها%')->count())->toBe(1);
});

it('appends the customer edit note to the case once', function () {
    $c = answeredConfirmation(OrderConfirmationStatus::EditRequested);
    OrderConfirmationAnswered::dispatch($c, 'edit');

    $c->update(['edit_note' => 'العنوان الجديد: مدينة نصر شارع عباس العقاد']);
    OrderConfirmationEditNote::dispatch($c->fresh());
    OrderConfirmationEditNote::dispatch($c->fresh());

    expect(SupportCase::count())->toBe(1)
        ->and(SupportCase::sole()->data['edit_note'])->toBe('العنوان الجديد: مدينة نصر شارع عباس العقاد')
        ->and(ConversationNote::where('body', 'like', '%مدينة نصر%')->count())->toBe(1);
});

it('puts an edit note that came first into the case it opens', function () {
    $c = answeredConfirmation(OrderConfirmationStatus::EditRequested, ['edit_note' => 'مقاس L بدل M']);

    OrderConfirmationEditNote::dispatch($c); // no case yet: nothing to append to
    OrderConfirmationAnswered::dispatch($c, 'edit');

    expect(SupportCase::sole()->data['edit_note'])->toBe('مقاس L بدل M');
});

it('rings the supervisors with its own kind when there is no conversation for a case', function () {
    $admin = User::factory()->create(['role' => UserRole::Admin, 'is_active' => true]);
    $c = answeredConfirmation(OrderConfirmationStatus::EditRequested, ['conversation_id' => null]);
    $c->order->update(['conversation_id' => null]);

    OrderConfirmationAnswered::dispatch($c->fresh(), 'edit');

    $bell = UserNotification::where('user_id', $admin->id)->sole();
    expect(SupportCase::count())->toBe(0)
        ->and(writtenTags())->toBe([['wa-edit-request']])
        ->and($bell->type)->toBe('order_confirmation.edit_request')
        ->and($bell->data)->toMatchArray(['request' => 'edit', 'order_id' => $c->order_id]);
});

it('tags a silent order wa-no-response', function () {
    $c = answeredConfirmation(OrderConfirmationStatus::NoResponse);

    OrderConfirmationNoResponse::dispatch($c);

    expect(writtenTags())->toBe([['wa-no-response']]);
});

it('records a call outcome with the tag, the activity log and the event', function () {
    $staff = User::factory()->create(['role' => UserRole::Moderator, 'is_active' => true]);
    $c = answeredConfirmation(OrderConfirmationStatus::NoResponse);
    Event::fake([OrderConfirmationCalled::class]);

    $result = app(CallOutcome::class)->record($c, 'unreachable', $staff);

    expect($result->status)->toBe(OrderConfirmationStatus::Unreachable)
        ->and($result->call_outcome)->toBe('unreachable')
        ->and($result->called_by)->toBe($staff->id)
        ->and($result->called_at)->not->toBeNull()
        ->and($result->tag_synced_at)->not->toBeNull()
        ->and(writtenTags())->toBe([['wa-unreachable']])
        ->and(ActivityLog::where('action', CallOutcome::ACTION)->sole()->meta)->toMatchArray(['outcome' => 'unreachable', 'confirmation_id' => $c->id]);

    Event::assertDispatched(OrderConfirmationCalled::class, fn ($e) => $e->confirmation->is($c) && $e->outcome === 'unreachable');

    app(CallOutcome::class)->record($c->fresh(), 'confirmed', $staff);
    expect($c->fresh()->status)->toBe(OrderConfirmationStatus::CalledConfirmed)
        ->and(writtenTags()[1])->toBe(['wa-confirmed']);
});

it('rejects an unknown call outcome', function () {
    $c = answeredConfirmation(OrderConfirmationStatus::NoResponse);

    expect(fn () => app(CallOutcome::class)->record($c, 'maybe', User::factory()->create()))
        ->toThrow(InvalidArgumentException::class);
});

it('records a manual outcome from sent, edit_requested and unreachable too', function (OrderConfirmationStatus $from) {
    $c = answeredConfirmation($from);

    $result = app(CallOutcome::class)->record($c, 'confirmed', User::factory()->create());

    expect($result->status)->toBe(OrderConfirmationStatus::CalledConfirmed);
})->with([
    'sent' => [OrderConfirmationStatus::Sent],
    'edit_requested' => [OrderConfirmationStatus::EditRequested],
    'unreachable' => [OrderConfirmationStatus::Unreachable],
]);

it('leaves a row another request already moved without a second tag or event', function () {
    $staff = User::factory()->create();
    $c = answeredConfirmation(OrderConfirmationStatus::NoResponse);
    $stale = $c->fresh();
    app(CallOutcome::class)->record($c, 'cancelled', $staff); // first click wins
    Event::fake([OrderConfirmationCalled::class]);

    $result = app(CallOutcome::class)->record($stale, 'confirmed', $staff); // a second tab, same row

    expect($result->status)->toBe(OrderConfirmationStatus::CalledCancelled)
        ->and(writtenTags())->toHaveCount(1)
        ->and(ActivityLog::where('action', CallOutcome::ACTION)->count())->toBe(1);
    Event::assertNotDispatched(OrderConfirmationCalled::class);
});

it('can be extended (the page tests swap in a spy)', function () {
    expect((new ReflectionClass(CallOutcome::class))->isFinal())->toBeFalse();
});
