<?php

use Illuminate\Support\Facades\Schema;

it('indexes the answered ratings by test flag and time, reversibly and only once', function () {
    $m = require database_path('migrations/2026_10_09_400010_add_rating_index_to_queue_entries.php');

    expect(Schema::hasIndex('queue_entries', 'queue_entries_test_reviewed_idx'))->toBeTrue();
    $m->up(); // guarded: a second run is a no-op
    $m->down();
    expect(Schema::hasIndex('queue_entries', 'queue_entries_test_reviewed_idx'))->toBeFalse();
    $m->up();
    expect(Schema::hasIndex('queue_entries', 'queue_entries_test_reviewed_idx'))->toBeTrue();
});
