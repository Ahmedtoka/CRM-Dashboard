<?php

use App\Queue\QueueWording;

it('words who is ahead of her with the right agreement', function (int $count, string $phrase) {
    expect(QueueWording::ahead($count))->toBe($phrase);
})->with([
    [0, 'إنتي أول واحدة في الدور'],
    [1, 'قدامك عميلة واحدة'],
    [2, 'قدامك عميلتين'],
    [3, 'قدامك 3 عملاء'],
    [10, 'قدامك 10 عملاء'],
    [11, 'قدامك 11 عميلة'],
    [-1, 'إنتي أول واحدة في الدور'],
]);

it('words the minutes with the right agreement, never less than one', function (int $count, string $phrase) {
    expect(QueueWording::minutes($count))->toBe($phrase);
})->with([
    [0, 'دقيقة'],
    [1, 'دقيقة'],
    [2, 'دقيقتين'],
    [3, '3 دقايق'],
    [10, '10 دقايق'],
    [11, '11 دقيقة'],
]);
