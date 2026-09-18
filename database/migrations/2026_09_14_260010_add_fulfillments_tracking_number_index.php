<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Global search (Task 13, fix round 1, ruling 6): `GlobalSearch::orderIds()`
 * looks up a candidate order by `fulfillments.tracking_number` as one of its
 * separate, individually-indexed exact lookups — this was missing an index
 * (unlike `shipments.tracking_number`, indexed by the earlier
 * `2026_09_14_260000_add_search_indexes` migration, which has already run
 * locally and is left untouched).
 */
return new class extends Migration
{
    public function up(): void
    {
        $has = fn (string $table, string $name) => collect(Schema::getIndexes($table))->contains(fn ($i) => $i['name'] === $name);

        Schema::table('fulfillments', function (Blueprint $t) use ($has) {
            if (! $has('fulfillments', 'fulfillments_tracking_number_index')) {
                $t->index('tracking_number');
            }
        });
    }

    public function down(): void
    {
        Schema::table('fulfillments', fn (Blueprint $t) => $t->dropIndex(['tracking_number']));
    }
};
