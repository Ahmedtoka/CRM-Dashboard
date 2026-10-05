<?php

use App\Ads\Audit\AdsAudit;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Ads authority (B2, owner decision D4, red-team R-30): the flag that allows Run and Stop at campaign and ad-set level.
     * It is not the inbox supervisor role. Seeded true for admins only (active or not: a deactivated admin cannot sign in).
     */
    public function up(): void
    {
        if (! Schema::hasTable('users') || Schema::hasColumn('users', 'ads_authority')) {
            return;
        }

        Schema::table('users', function (Blueprint $table) {
            $table->boolean('ads_authority')->default(false)->after('role');
        });

        $ids = DB::table('users')->where('role', 'admin')->orderBy('id')->pluck('id')->map(fn ($id) => (int) $id)->all();
        if ($ids === []) {
            return;
        }
        DB::table('users')->whereIn('id', $ids)->update(['ads_authority' => true]);

        if (Schema::hasTable('ads_audit_log')) {
            AdsAudit::record('users.ads_authority_seeded', null, null, ['user_ids' => $ids]);
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('users') && Schema::hasColumn('users', 'ads_authority')) {
            Schema::table('users', function (Blueprint $table) {
                $table->dropColumn('ads_authority');
            });
        }
    }
};
