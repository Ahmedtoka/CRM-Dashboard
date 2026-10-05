<?php

use App\Ads\Audit\AdsAudit;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Per-account write switch (B1, owner decision D3): on for every account, existing and newly discovered. A non-empty
     * slice-1 list in ads_settings.writable_account_ids is carried over once (accounts not on it are switched off). The
     * setting row is left untouched: it is what the slice-1 code reads if this release is rolled back.
     */
    public function up(): void
    {
        if (! Schema::hasTable('ad_accounts') || Schema::hasColumn('ad_accounts', 'write_enabled')) {
            return;
        }

        Schema::table('ad_accounts', function (Blueprint $table) {
            $table->boolean('write_enabled')->default(true)->after('is_active');
        });

        DB::table('ad_accounts')->update(['write_enabled' => true]);

        $list = $this->sliceOneList();
        if ($list === null || $list === []) {
            return;
        }

        $off = DB::table('ad_accounts')->whereNotIn('external_id', $list)->orderBy('id')->pluck('id')->map(fn ($id) => (int) $id)->all();
        if ($off !== []) {
            DB::table('ad_accounts')->whereIn('id', $off)->update(['write_enabled' => false]);
        }

        if (Schema::hasTable('ads_audit_log')) {
            AdsAudit::record('settings.writable_accounts_migrated', null, ['writable' => $list], ['write_enabled_false' => $off]);
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('ad_accounts') && Schema::hasColumn('ad_accounts', 'write_enabled')) {
            Schema::table('ad_accounts', function (Blueprint $table) {
                $table->dropColumn('write_enabled');
            });
        }
    }

    /** @return list<string>|null */
    private function sliceOneList(): ?array
    {
        if (! Schema::hasTable('ads_settings')) {
            return null;
        }
        $raw = DB::table('ads_settings')->where('key', 'writable_account_ids')->value('value');
        $value = is_string($raw) ? json_decode($raw, true) : $raw;

        return is_array($value) ? array_values(array_unique(array_map('strval', $value))) : null;
    }
};
