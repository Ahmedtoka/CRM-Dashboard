<?php

use App\Bot\Flow\HumanPacing;

it('scales delay with length within bounds', function () {
    $p = new HumanPacing;
    expect($p->delayMs('اه', 35))->toBe(1500)
        ->and($p->delayMs(str_repeat('ا', 100), 35))->toBe(3500)
        ->and($p->delayMs(str_repeat('ا', 1000), 35))->toBe(6000)
        ->and($p->delayMs('anything', 0))->toBe(0);
});

it('splits long replies at the last blank line before 450 chars', function () {
    $a = str_repeat('أ', 300);
    $b = str_repeat('ب', 300);
    expect((new HumanPacing)->split("$a\n\n$b"))->toBe([$a, $b])
        ->and((new HumanPacing)->split('قصير'))->toBe(['قصير']);
});
