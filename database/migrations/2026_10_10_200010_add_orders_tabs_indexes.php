<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Fresh-orders review round 1: the /orders tabs filter on the order date and group by governorate.
 * - orders(placed_at): the order date coalesce(placed_at, created_at) (already indexed on most installs; guarded).
 * - orders(shipping_province_code): the governorate filter and grouping.
 * Each index is guarded (table, columns, name, same columns) so it is safe to re-run on MariaDB 10.4; down() drops
 * only these.
 */
return new class extends Migration
{
    /** @var array<string, array{0: string, 1: list<string>}> name => [table, columns] */
    private const INDEXES = [
        'orders_placed_at_date_idx' => ['orders', ['placed_at']],
        'orders_shipping_province_code_idx' => ['orders', ['shipping_province_code']],
    ];

    public function up(): void
    {
        foreach (self::INDEXES as $name => [$table, $columns]) {
            if (! Schema::hasTable($table) || ! Schema::hasColumns($table, $columns)
                || Schema::hasIndex($table, $name) || Schema::hasIndex($table, $columns)) {
                continue;
            }
            Schema::table($table, fn (Blueprint $t) => $t->index($columns, $name));
        }
    }

    public function down(): void
    {
        foreach (self::INDEXES as $name => [$table]) {
            if (Schema::hasTable($table) && Schema::hasIndex($table, $name)) {
                Schema::table($table, fn (Blueprint $t) => $t->dropIndex($name));
            }
        }
    }
};
