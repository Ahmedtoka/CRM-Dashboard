<?php

use App\Commerce\FakeCommerceProvider;
use App\Commerce\Jobs\SubmitOrderToProvider;
use App\Commerce\OrderService;
use App\Enums\OrderStatus;
use App\Enums\Platform;
use App\Enums\UserRole;
use App\Models\ChannelAccount;
use App\Models\Conversation;
use App\Models\Customer;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\User;
use App\Shopify\Jobs\ReconcileShopify;
use App\Shopify\Jobs\RunManualSync;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

/**
 * Final fix wave I2: no job may outlive its connection's retry_after (which
 * would let a second worker run it concurrently), and two executions of one
 * order submission can never create two store orders.
 */
it('keeps redis retry_after at 90 and adds a redislong connection for long jobs', function () {
    expect(config('queue.connections.redis.retry_after'))->toBe(90)
        ->and(config('queue.connections.redislong.driver'))->toBe('redis')
        ->and(config('queue.connections.redislong.connection'))->toBe(config('queue.connections.redis.connection'))
        ->and(config('queue.connections.redislong.queue'))->toBe('long')
        ->and(config('queue.connections.redislong.retry_after'))->toBe(3700);
});

it('gives the submission job a timeout below the redis retry_after', function () {
    expect((new SubmitOrderToProvider(1))->timeout)->toBe(60)
        ->and((new SubmitOrderToProvider(1))->queue)->toBe('commerce');
});

it('puts reconcile and manual sync on the commercelong queue of the redislong connection when redis is the default', function () {
    config(['queue.default' => 'redis']);

    foreach ([new ReconcileShopify, new RunManualSync('products')] as $job) {
        expect($job->queue)->toBe('commercelong')
            ->and($job->connection)->toBe('redislong')
            ->and($job->timeout)->toBeLessThan(config('queue.connections.redislong.retry_after'));
    }
});

it('keeps the default connection for reconcile and manual sync when the default is not redis', function (string $default) {
    config(['queue.default' => $default]);

    foreach ([new ReconcileShopify, new RunManualSync('products')] as $job) {
        expect($job->queue)->toBe('commercelong')
            ->and($job->connection)->toBeNull();
    }
})->with(['sync', 'database']);

it('does not call the store when another execution holds the order submission lock, and releases the job', function () {
    Event::fake();
    Queue::fake();
    FakeCommerceProvider::reset();

    $conv = Conversation::factory()->for(Customer::factory())->for(ChannelAccount::factory()->state(['platform' => Platform::Instagram]), 'channelAccount')->create(['platform' => Platform::Instagram]);
    $variant = ProductVariant::factory()->for(Product::factory())->create(['price' => 500, 'shopify_id' => '111', 'inventory_quantity' => 5]);
    $sup = User::factory()->create(['role' => UserRole::Supervisor]);

    $order = app(OrderService::class)->create($conv, $sup, [
        'idempotency_key' => (string) Str::uuid(), 'type' => 'cod',
        'items' => [['variant_id' => $variant->id, 'qty' => 1]],
        'shipping' => ['name' => 'Nour', 'phone' => '01001234567', 'province_code' => 'C', 'city' => 'Nasr City', 'address1' => 'x'],
    ]);

    $lock = Cache::lock("order-submit-{$order->id}", 75);
    expect($lock->get())->toBeTrue();

    $job = (new SubmitOrderToProvider($order->id))->withFakeQueueInteractions();
    $job->handle(app(OrderService::class));

    expect(FakeCommerceProvider::$payloads)->toBe([])
        ->and(FakeCommerceProvider::$lookups)->toBe(0)
        ->and($order->fresh()->status)->toBe(OrderStatus::Submitting)
        ->and($order->fresh()->submit_attempts)->toBe(0);
    $job->assertReleased();

    $lock->release();

    $job = (new SubmitOrderToProvider($order->id))->withFakeQueueInteractions();
    $job->handle(app(OrderService::class));

    expect(FakeCommerceProvider::$created)->toHaveCount(1)
        ->and($order->fresh()->status)->toBe(OrderStatus::Confirmed);
    $job->assertNotReleased();
});

it('uses a lock timeout shorter than retry_after so a released lock allows retries', function () {
    Queue::fake();
    FakeCommerceProvider::reset();

    $conv = Conversation::factory()->for(Customer::factory())->for(ChannelAccount::factory()->state(['platform' => Platform::Instagram]), 'channelAccount')->create(['platform' => Platform::Instagram]);
    $variant = ProductVariant::factory()->for(Product::factory())->create(['price' => 500, 'shopify_id' => '111', 'inventory_quantity' => 5]);
    $sup = User::factory()->create(['role' => UserRole::Supervisor]);

    $order = app(OrderService::class)->create($conv, $sup, [
        'idempotency_key' => (string) Str::uuid(), 'type' => 'cod',
        'items' => [['variant_id' => $variant->id, 'qty' => 1]],
        'shipping' => ['name' => 'Nour', 'phone' => '01001234567', 'province_code' => 'C', 'city' => 'Nasr City', 'address1' => 'x'],
    ]);

    // Verify the lock timeout is shorter than the retry_after (75 < 90)
    $reflection = new ReflectionClass(OrderService::class);
    $constant = $reflection->getConstant('SUBMIT_LOCK_SECONDS');
    expect($constant)->toBe(75)
        ->and($constant)->toBeLessThan(90);

    // Simulate a lock held by a dead/killed attempt
    $lock = Cache::lock("order-submit-{$order->id}", $constant);
    expect($lock->get())->toBeTrue();

    // Release the lock to simulate a job timeout and retry
    $lock->release();

    // A retry attempt can now acquire the lock
    $newLock = Cache::lock("order-submit-{$order->id}", $constant);
    expect($newLock->get())->toBeTrue();

    $newLock->release();
});
