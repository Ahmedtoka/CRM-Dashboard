<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('ad_accounts', 'deactivated_reason')) {
            Schema::table('ad_accounts', fn (Blueprint $t) => $t->string('deactivated_reason', 30)->nullable());
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('ad_accounts', 'deactivated_reason')) {
            Schema::table('ad_accounts', fn (Blueprint $t) => $t->dropColumn('deactivated_reason'));
        }
    }
};
