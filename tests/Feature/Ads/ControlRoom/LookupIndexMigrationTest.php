<?php

use Illuminate\Support\Facades\Schema;

it('final review B4/B-m6: indexes ads.external_id and conversations by test flag and time, reversibly and only once', function () {
    $m = require database_path('migrations/2026_10_09_400020_add_control_room_lookup_indexes.php');
    $all = [['ads', 'ads_external_id_idx'], ['conversations', 'conversations_test_last_message_idx']];
    $present = fn () => array_map(fn ($i) => Schema::hasIndex($i[0], $i[1]), $all);

    expect($present())->toBe([true, true]);
    $m->up(); // guarded: a second run is a no-op
    $m->down();
    expect($present())->toBe([false, false]);
    $m->up();
    expect($present())->toBe([true, true]);
});
