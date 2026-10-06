<?php

use App\Http\Support\SortParam;

it('reads -key as descending and key as ascending', function () {
    $desc = SortParam::parse('-total', ['total' => 'orders.total']);

    expect($desc->key)->toBe('total')
        ->and($desc->column)->toBe('orders.total')
        ->and($desc->direction)->toBe('desc')
        ->and($desc->value())->toBe('-total')
        ->and(SortParam::parse('total', ['total' => 'orders.total'])->direction)->toBe('asc');
});

it('returns null for anything outside the whitelist', function (mixed $raw) {
    expect(SortParam::parse($raw, ['total' => 'total']))->toBeNull();
})->with([
    'null' => [null],
    'empty' => [''],
    'dash only' => ['-'],
    'unknown key' => ['password'],
    'injection' => ['-id;drop table users'],
    'array' => [['total']],
]);
