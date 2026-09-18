<?php

use App\Bot\Flow\Orders\FakeOmsClient;
use App\Bot\Flow\Orders\HttpOmsClient;
use App\Bot\Flow\Orders\OmsClient;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    Http::preventStrayRequests();
    config(['crm.oms.base_url' => 'https://oms.test/api/', 'crm.oms.token' => 'oms-token', 'crm.oms.timeout' => 8]);
});

it('maps the OMS status case-insensitively with a bearer token', function (string $raw, ?string $state) {
    Http::fake(['oms.test/api/orders/1234' => Http::response(['status' => $raw, 'updated_at' => '2026-09-15T10:00:00+03:00', 'courier' => 'Bosta', 'tracking_url' => 'https://t.test/1'])]);

    $s = (new HttpOmsClient)->status('1234');

    expect($s?->state)->toBe($state);
    Http::assertSent(fn ($r) => $r->hasHeader('Authorization', 'Bearer oms-token') && $r->url() === 'https://oms.test/api/orders/1234');
})->with([
    ['HOLD', 'hold'], ['prepared', 'prepared'], ['Shipped', 'shipped'], ['On The Way', 'on_the_way'], ['on_the_way', 'on_the_way'],
    ['out_for_delivery', 'on_the_way'], ['delivered', 'delivered'], ['returned', 'returned'], ['canceled', 'cancelled'], ['cancelled', 'cancelled'],
    ['lost_in_space', null],
]);

it('reads courier, tracking and time', function () {
    Http::fake(['oms.test/*' => Http::response(['status' => 'shipped', 'updated_at' => '2026-09-15T10:00:00+03:00', 'courier' => 'Bosta', 'tracking_url' => 'https://t.test/1'])]);

    $s = (new HttpOmsClient)->status('1234');

    expect($s->courier)->toBe('Bosta')->and($s->trackingUrl)->toBe('https://t.test/1')->and($s->updatedAt?->toIso8601String())->toBe('2026-09-15T10:00:00+03:00');
});

it('returns null on 404 and throws on other failures', function () {
    Http::fake(['oms.test/api/orders/404' => Http::response([], 404), 'oms.test/api/orders/500' => Http::response([], 500)]);

    expect((new HttpOmsClient)->status('404'))->toBeNull();
    (new HttpOmsClient)->status('500');
})->throws(RequestException::class);

it('binds the http client only for the live driver with a base url', function () {
    config(['crm.drivers.oms' => 'live']);
    expect(app(OmsClient::class))->toBeInstanceOf(HttpOmsClient::class);

    config(['crm.oms.base_url' => null]);
    expect(app(OmsClient::class))->toBeInstanceOf(FakeOmsClient::class);

    config(['crm.drivers.oms' => 'fake', 'crm.oms.base_url' => 'https://oms.test']);
    expect(app(OmsClient::class))->toBeInstanceOf(FakeOmsClient::class)
        ->and(config('crm.drivers.oms'))->toBe('fake');
});
