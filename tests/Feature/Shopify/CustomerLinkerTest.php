<?php

use App\Analytics\ActivityLogger;
use App\Models\ActivityLog;
use App\Models\Conversation;
use App\Models\Customer;
use App\Models\CustomerIdentity;
use App\Models\CustomerMergeSuggestion;
use App\Shopify\Customers\CustomerLinker;
use App\Shopify\Sync\Mappers\CustomerMapper;

it('merges a single social customer into the shopify customer by phone', function () {
    $social = Customer::factory()->create(['phone' => '01001234567', 'shopify_customer_id' => null]);
    CustomerIdentity::factory()->for($social)->create();
    Conversation::factory()->for($social)->create();
    app(CustomerMapper::class)->upsert(['id' => 777, 'first_name' => 'Nour', 'last_name' => 'Ali', 'phone' => '+201001234567', 'email' => null, 'tags' => '', 'orders_count' => 3, 'total_spent' => '900.00', 'updated_at' => '2026-09-01T10:00:00Z', 'addresses' => []]);
    $linked = Customer::where('shopify_customer_id', '777')->firstOrFail();
    expect(Customer::count())->toBe(1)->and($linked->identities)->toHaveCount(1)->and($linked->conversations)->toHaveCount(1);
});

it('creates suggestions instead of merging when ambiguous', function () {
    Customer::factory()->count(2)->create(['phone' => '01001234567', 'shopify_customer_id' => null]);
    app(CustomerMapper::class)->upsert(['id' => 778, 'first_name' => 'X', 'last_name' => '', 'phone' => '01001234567', 'email' => null, 'tags' => '', 'orders_count' => 0, 'total_spent' => '0', 'updated_at' => '2026-09-01T10:00:00Z', 'addresses' => []]);
    expect(Customer::count())->toBe(3)->and(CustomerMergeSuggestion::where('status', 'open')->count())->toBe(2);
});

// --- Additional coverage -------------------------------------------------------

it('logs the automatic merge as a system action', function () {
    $social = Customer::factory()->create(['phone' => '01001234567', 'email' => null, 'notes' => 'بتحب اللون الأسود']);
    app(CustomerMapper::class)->upsert(['id' => 779, 'first_name' => 'Nour', 'last_name' => '', 'phone' => '+201001234567', 'email' => null, 'tags' => '', 'orders_count' => 1, 'total_spent' => '100', 'updated_at' => '2026-09-01T10:00:00Z', 'addresses' => []]);
    $linked = Customer::where('shopify_customer_id', '779')->firstOrFail();
    $log = ActivityLog::where('action', ActivityLogger::CUSTOMER_MERGED)->firstOrFail();
    expect($linked->notes)->toBe('بتحب اللون الأسود')
        ->and($log->user_id)->toBeNull()->and($log->actor_type->value)->toBe('system')
        ->and($log->meta['other_id'])->toBe($social->id);
});

it('suggests instead of merging when the candidate is linked to another shopify customer', function () {
    Customer::factory()->create(['phone' => '01001234567', 'shopify_customer_id' => '555']);
    app(CustomerMapper::class)->upsert(['id' => 780, 'first_name' => 'Y', 'last_name' => '', 'phone' => '01001234567', 'email' => null, 'tags' => '', 'orders_count' => 0, 'total_spent' => '0', 'updated_at' => '2026-09-01T10:00:00Z', 'addresses' => []]);
    expect(Customer::count())->toBe(2)->and(CustomerMergeSuggestion::count())->toBe(1);
});

it('never merges on non-mobile phones and only suggests on email', function () {
    Customer::factory()->create(['phone' => '0223456789', 'email' => 'x@example.com']);
    $data = ['id' => 781, 'first_name' => 'Z', 'last_name' => '', 'phone' => '0223456789', 'email' => 'x@example.com', 'tags' => '', 'orders_count' => 0, 'total_spent' => '0', 'updated_at' => '2026-09-01T10:00:00Z', 'addresses' => []];
    app(CustomerMapper::class)->upsert($data);
    expect(Customer::count())->toBe(2)
        ->and(CustomerMergeSuggestion::where('reason', 'email')->count())->toBe(1)
        ->and(CustomerMergeSuggestion::where('reason', 'phone')->count())->toBe(0);

    // Linking again does not duplicate the pair.
    app(CustomerLinker::class)->link(Customer::where('shopify_customer_id', '781')->firstOrFail());
    expect(CustomerMergeSuggestion::count())->toBe(1);
});

it('returns null when there is nothing to link', function () {
    $c = Customer::factory()->create(['phone' => '01001234567', 'shopify_customer_id' => '1']);
    expect(app(CustomerLinker::class)->link($c))->toBeNull();
});
