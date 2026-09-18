<?php

use App\Bot\Flow\Orders\OrderSnapshot;
use App\Bot\Flow\Orders\OrderStatusText;
use Carbon\CarbonImmutable;

function snap(string $key, string $placed = '2026-09-01 10:00', ?string $gov = 'القاهرة', ?string $url = null): OrderSnapshot
{
    return new OrderSnapshot(1, '#1234', CarbonImmutable::parse($placed, 'Africa/Cairo'), 'oms', $key, $url, $gov);
}

it('maps every status to a short arabic line', function (string $key, string $contains) {
    expect((new OrderStatusText)->line(snap($key, url: 'https://t.test/1')))->toContain($contains)->toContain('#1234');
})->with([
    ['confirmed', 'جاري تجهيزه'], ['prepared', 'اتجهز'], ['shipped', 'اتشحن'], ['on_the_way', 'مع المندوب'], ['delivered', 'اتسلم'],
    ['cancelled', 'اتلغى'], ['hold', 'هراجع الأوردر'], ['returned', 'هراجع الأوردر'],
]);

it('includes the tracking link when shipped', function () {
    expect((new OrderStatusText)->line(snap('shipped', url: 'https://t.test/1')))->toBe("الأوردر رقم #1234 اتشحن ✨\nتقدري تتابعيه من هنا: https://t.test/1")
        ->and((new OrderStatusText)->line(snap('shipped')))->toBe('الأوردر رقم #1234 اتشحن ✨');
});

it('flags delay after 5 working days in cairo giza alex and 7 elsewhere', function () {
    $t = new OrderStatusText;
    $now = CarbonImmutable::parse('2026-09-10 12:00', 'Africa/Cairo');
    expect($t->isDelayed(snap('shipped', '2026-09-01 10:00', 'الجيزة'), $now))->toBeTrue()
        ->and($t->isDelayed(snap('shipped', '2026-09-07 10:00', 'أسوان'), $now))->toBeFalse()
        ->and($t->isDelayed(snap('delivered', '2026-08-01 10:00'), $now))->toBeFalse();
});

it('skips fridays when counting working days', function () {
    $t = new OrderStatusText;
    // Placed Wed Sep 2; Sep 3..8 is 6 days with Friday Sep 4 off = 5 working days.
    expect($t->isDelayed(snap('confirmed', '2026-09-02 10:00', 'الاسكندريه'), CarbonImmutable::parse('2026-09-08 12:00', 'Africa/Cairo')))->toBeFalse()
        ->and($t->isDelayed(snap('confirmed', '2026-09-02 10:00', 'الاسكندريه'), CarbonImmutable::parse('2026-09-09 12:00', 'Africa/Cairo')))->toBeTrue()
        // Elsewhere (or unknown governorate) waits 7 working days.
        ->and($t->isDelayed(snap('confirmed', '2026-09-02 10:00', null), CarbonImmutable::parse('2026-09-09 12:00', 'Africa/Cairo')))->toBeFalse()
        // Sep 11 is a Friday (still 7); Saturday Sep 12 is the 8th working day.
        ->and($t->isDelayed(snap('confirmed', '2026-09-02 10:00', null), CarbonImmutable::parse('2026-09-11 12:00', 'Africa/Cairo')))->toBeFalse()
        ->and($t->isDelayed(snap('confirmed', '2026-09-02 10:00', null), CarbonImmutable::parse('2026-09-12 12:00', 'Africa/Cairo')))->toBeTrue()
        ->and($t->isDelayed(snap('cancelled', '2026-08-01 10:00'), CarbonImmutable::parse('2026-09-11 12:00', 'Africa/Cairo')))->toBeFalse();
});

it('never calls an old hold or returned order delayed', function () {
    $now = CarbonImmutable::parse('2026-09-15 12:00', 'Africa/Cairo');
    expect((new OrderStatusText)->isDelayed(snap('returned', '2026-08-01 10:00'), $now))->toBeFalse()
        ->and((new OrderStatusText)->isDelayed(snap('hold', '2026-08-01 10:00'), $now))->toBeFalse();
});

it('needs an agent only for hold and returned', function () {
    $t = new OrderStatusText;
    expect($t->needsAgent(snap('hold')))->toBeTrue()
        ->and($t->needsAgent(snap('returned')))->toBeTrue()
        ->and($t->needsAgent(snap('shipped')))->toBeFalse();
});
