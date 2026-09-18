<?php

use App\Bot\Flow\DetailsCollector;
use App\Models\BotIntent;

function collectIntent(string $key, array $details): BotIntent
{
    return new BotIntent(['key' => $key, 'route' => 'collect_then_handover', 'priority' => 'medium', 'queue' => 'agents', 'required_details' => $details]);
}

it('treats alternatives as satisfied by any one of them, from entities or collected state', function () {
    $d = new DetailsCollector;
    $cancel = collectIntent('cancel_order', ['order_ref|phone|email']);

    expect($d->missing($cancel, [], []))->toBe(['order_ref|phone|email'])
        ->and($d->missing($cancel, ['order_ref' => null, 'phone' => '01001234567'], []))->toBe([])
        ->and($d->missing($cancel, [], ['collected' => ['email' => 'a@b.test']]))->toBe([]);
});

it('needs photos from the burst, and an invoice photo counts for invoice', function () {
    $d = new DetailsCollector;
    $defect = collectIntent('defect', ['order_ref|invoice', 'photos']);

    expect($d->missing($defect, ['order_ref' => '1234'], []))->toBe(['photos'])
        ->and($d->missing($defect, ['photos' => 'yes'], []))->toBe([])
        ->and($d->missing(collectIntent('exchange_return', ['order_ref|invoice', 'product_photo', 'tag_photo']), [], []))->toBe(['order_ref|invoice', 'product_photo', 'tag_photo']);
});

it('never asks for optional details and counts free-text details once the customer was asked', function () {
    $d = new DetailsCollector;
    $store = collectIntent('store_complaint', ['name', 'phone', 'invoice?', 'visit_date']);

    expect($d->missing($store, [], []))->toBe(['name', 'phone', 'visit_date'])
        ->and($d->missing($store, ['phone' => '01001234567'], ['asks' => ['store_complaint' => 1]]))->toBe([])
        ->and($d->missing($store, [], ['asks' => ['store_complaint' => 1]]))->toBe(['phone']);
});

it('never remembers photos and ignores a stale remembered photo', function () {
    $d = new DetailsCollector;
    $defect = collectIntent('defect', ['order_ref|invoice', 'photos']);

    expect($d->remember(['collected' => ['photos' => 'yes']], ['photos' => 'yes', 'order_ref' => '1'])['collected'])->toBe(['order_ref' => '1'])
        ->and($d->missing($defect, [], ['collected' => ['photos' => 'yes', 'order_ref' => '1']]))->toBe(['photos']);
});

it('remembers non-null entities on top of what was collected', function () {
    $state = (new DetailsCollector)->remember(['collected' => ['phone' => '0100', 'order_ref' => '1']], ['order_ref' => '2', 'email' => null, 'product' => 'فستان']);

    expect($state['collected'])->toBe(['phone' => '0100', 'order_ref' => '2', 'product' => 'فستان']);
});

it('counts a remembered photo only for the intent that is waiting for it', function () {
    $d = new DetailsCollector;
    $defect = collectIntent('defect', ['order_ref|phone|email', 'photos']);
    $state = ['collected' => ['photos' => 'yes'], 'awaiting_intent' => 'defect'];

    expect($d->missing($defect, ['order_ref' => '1234'], $state))->toBe([])
        ->and($d->missing($defect, ['order_ref' => '1234'], ['collected' => ['photos' => 'yes']]))->toBe(['photos'])
        ->and($d->missing($defect, ['order_ref' => '1234'], ['collected' => ['photos' => 'yes'], 'awaiting_intent' => 'cancel_order']))->toBe(['photos']);
});

it('keeps photos in collected only when asked to', function () {
    $d = new DetailsCollector;

    expect($d->remember([], ['photos' => 'yes', 'order_ref' => '1'])['collected'])->toBe(['order_ref' => '1'])
        ->and($d->remember([], ['photos' => 'yes'], keepPhotos: true)['collected'])->toBe(['photos' => 'yes'])
        ->and($d->remember(['collected' => ['photos' => 'yes']], ['order_ref' => '1'], keepPhotos: true)['collected'])->toBe(['photos' => 'yes', 'order_ref' => '1']);
});

it('keeps a remembered order number when a later burst has no order number of its own', function () {
    $d = new DetailsCollector;
    $exchange = collectIntent('exchange_return', ['order_ref|phone|email', 'photos']);
    $state = ['collected' => ['order_ref' => '1047', 'photos' => 'yes'], 'awaiting_intent' => 'exchange_return'];

    expect($d->missing($exchange, ['order_ref' => null, 'phone' => null, 'email' => null, 'photos' => 'yes'], $state))->toBe([]);
});
