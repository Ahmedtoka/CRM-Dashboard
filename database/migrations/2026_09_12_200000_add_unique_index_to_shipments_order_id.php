<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $this->dedupeShipments();

        Schema::table('shipments', function (Blueprint $table) {
            $table->unique('order_id');
        });
    }

    public function down(): void
    {
        Schema::table('shipments', function (Blueprint $table) {
            $table->dropUnique(['order_id']);
        });
    }

    /**
     * Keep the lowest-id shipment per order_id and delete the rest, together with
     * their shipment_events, before the unique index below would otherwise fail
     * to apply on data that predates this migration. Uses only groupBy/havingRaw
     * ("COUNT(*) > 1" is standard SQL), so it runs the same on SQLite and MariaDB.
     */
    private function dedupeShipments(): void
    {
        $duplicateOrderIds = DB::table('shipments')
            ->select('order_id')
            ->groupBy('order_id')
            ->havingRaw('COUNT(*) > 1')
            ->pluck('order_id');

        foreach ($duplicateOrderIds as $orderId) {
            $ids = DB::table('shipments')
                ->where('order_id', $orderId)
                ->orderBy('id')
                ->pluck('id');

            // Keep the first (lowest id); drop the rest.
            $dropIds = $ids->slice(1)->values()->all();

            if ($dropIds === []) {
                continue;
            }

            DB::table('shipment_events')->whereIn('shipment_id', $dropIds)->delete();
            DB::table('shipments')->whereIn('id', $dropIds)->delete();
        }
    }
};
