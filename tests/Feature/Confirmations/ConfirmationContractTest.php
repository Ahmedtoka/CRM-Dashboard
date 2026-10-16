<?php

use App\Confirmations\ConfirmationPayload;
use App\Confirmations\ConfirmationSettings;
use App\Confirmations\Contracts\ConfirmationSender;
use App\Confirmations\Contracts\ShopifyOrderTagger;
use App\Enums\OrderConfirmationStatus;
use App\Models\OrderConfirmation;

it('round-trips button payloads and rejects anything else', function () {
    expect(ConfirmationPayload::parse(ConfirmationPayload::make(42, 'cancel')))->toBe(['id' => 42, 'action' => 'cancel'])
        ->and(ConfirmationPayload::parse('oc:42:refund'))->toBeNull()
        ->and(ConfirmationPayload::parse('oc:x:confirm'))->toBeNull()
        ->and(ConfirmationPayload::parse('menu:orders'))->toBeNull()
        ->and(ConfirmationPayload::parse(null))->toBeNull();
});

it('is off by default and keeps saved values', function () {
    $settings = app(ConfirmationSettings::class);

    expect($settings->enabled())->toBeFalse()
        ->and($settings->delayMinutes())->toBe(3)
        ->and($settings->template('reminder'))->toBe('order_reminder_ar');

    $settings->save(['enabled' => true, 'delay_minutes' => 5, 'unknown' => 'ignored']);

    expect($settings->enabled())->toBeTrue()
        ->and($settings->delayMinutes())->toBe(5)
        ->and($settings->all())->not->toHaveKey('unknown');
});

it('keeps the no-response deadline after the reminder', function () {
    $settings = app(ConfirmationSettings::class);
    $settings->save(['reminder_after_hours' => 10, 'no_response_after_hours' => 4]);

    expect($settings->noResponseAfterHours())->toBe(11);
});

it('stores one confirmation per order with its status enum', function () {
    $confirmation = OrderConfirmation::factory()->sent()->create();

    expect($confirmation->fresh()->status)->toBe(OrderConfirmationStatus::Sent)
        ->and(OrderConfirmationStatus::NoResponse->shopifyTag())->toBe('wa-no-response')
        ->and(OrderConfirmationStatus::Sent->awaitingCustomer())->toBeTrue();

    expect(fn () => OrderConfirmation::factory()->create(['order_id' => $confirmation->order_id]))
        ->toThrow(Illuminate\Database\UniqueConstraintViolationException::class);
});

it('binds the slice contracts', function () {
    expect(app(ConfirmationSender::class))->toBeInstanceOf(ConfirmationSender::class)
        ->and(app(ShopifyOrderTagger::class))->toBeInstanceOf(ShopifyOrderTagger::class);
});
