<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * «تكلفة الخدمة» (round 2026-10-24): the two numbers the service-cost report needs, on the queue
 * settings row (admin-only in the UI). Both nullable: until the owner fills them the report says
 * what is missing instead of inventing a figure.
 *
 * - `team_monthly_cost_egp`: what the moderators cost per month, in EGP (one total).
 * - `usd_egp_rate`: EGP per USD, to convert `bot_runs.cost_usd`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('queue_settings', function (Blueprint $t) {
            if (! Schema::hasColumn('queue_settings', 'team_monthly_cost_egp')) {
                $t->decimal('team_monthly_cost_egp', 12, 2)->nullable();
            }
            if (! Schema::hasColumn('queue_settings', 'usd_egp_rate')) {
                $t->decimal('usd_egp_rate', 8, 3)->nullable();
            }
        });
    }

    public function down(): void
    {
        Schema::table('queue_settings', function (Blueprint $t) {
            $t->dropColumn(['team_monthly_cost_egp', 'usd_egp_rate']);
        });
    }
};
